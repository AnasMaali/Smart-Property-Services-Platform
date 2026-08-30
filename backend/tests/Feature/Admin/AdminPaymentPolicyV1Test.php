<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B24 - Service Payment Policy + Card / Apple Pay / Pay on
 * Site. Covers: Admin payment-policy CRUD/validation/audit, the customer
 * Service API's additive `payment_policy` block, the canonical mixed-Cart
 * payment-method intersection, the existing Stripe/Card path (still
 * unchanged, now with an explicit `payment_method` selection), the full
 * Pay-on-Site Booking path (creation, idempotency, no Stripe involvement,
 * on-site collection, cancellation before/after collection), and
 * historical immutability against later policy changes.
 */
class AdminPaymentPolicyV1Test extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    // -----------------------------------------------------------------
    // Admin payment-policy CRUD / validation / audit
    // -----------------------------------------------------------------

    public function test_admin_can_set_and_reload_payment_methods(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();

        $before = $this->auditLogsFor($service['uuid'])->count();

        $this->postJson("/api/v1/admin/services/{$service['uuid']}/payment-methods", [
            'payment_methods' => ['CARD', 'PAY_ON_SITE'],
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $this->assertSame($before + 1, $this->auditLogsFor($service['uuid'])->count());

        $detail = $this->getJson("/api/v1/admin/services/{$service['uuid']}", $this->bearer($admin['access_token']));
        $policy = $detail->json('data.service.payment_policy');

        $this->assertEqualsCanonicalizing(['CARD', 'PAY_ON_SITE'], collect($policy['allowed_methods'])->pluck('code')->all());
        $this->assertFalse($policy['requires_prepayment']);
    }

    public function test_requires_prepayment_is_true_exactly_when_pay_on_site_is_not_allowed(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();

        $this->postJson("/api/v1/admin/services/{$service['uuid']}/payment-methods", [
            'payment_methods' => ['CARD', 'APPLE_PAY'],
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $detail = $this->getJson("/api/v1/admin/services/{$service['uuid']}", $this->bearer($admin['access_token']));
        $this->assertTrue($detail->json('data.service.payment_policy.requires_prepayment'));
    }

    public function test_empty_payment_method_set_is_rejected(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();

        $this->postJson("/api/v1/admin/services/{$service['uuid']}/payment-methods", [
            'payment_methods' => [],
        ], $this->bearer($admin['access_token']))->assertStatus(422);
    }

    public function test_unauthenticated_customer_cannot_set_payment_methods(): void
    {
        $service = $this->createCartService();
        $customer = $this->createAuthenticatedCartCustomer();

        $this->postJson("/api/v1/admin/services/{$service['uuid']}/payment-methods", [
            'payment_methods' => ['CARD'],
        ], ['Authorization' => 'Bearer '.$customer['access_token']])->assertStatus(401);
    }

    public function test_customer_service_api_exposes_payment_policy(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService(overrides: ['payment_methods' => false]);
        $this->createCartPricingScheme($service['uuid']);

        $this->postJson("/api/v1/admin/services/{$service['uuid']}/payment-methods", [
            'payment_methods' => ['CARD', 'APPLE_PAY', 'PAY_ON_SITE'],
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");
        $policy = $response->json('data.payment_policy');

        $this->assertFalse($policy['requires_prepayment']);
        $this->assertEqualsCanonicalizing(['CARD', 'APPLE_PAY', 'PAY_ON_SITE'], collect($policy['allowed_methods'])->pluck('code')->all());
        $this->assertSame(['code', 'label'], array_keys($policy['allowed_methods'][0]));
    }

    // -----------------------------------------------------------------
    // Mixed-Cart payment-method intersection (section 9/32 of the spec)
    // -----------------------------------------------------------------

    public function test_mixed_cart_intersection_excludes_pay_on_site_when_one_service_requires_prepayment(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $serviceA = $this->createCartService(overrides: ['payment_methods' => ['CARD', 'APPLE_PAY', 'PAY_ON_SITE']]);
        $this->createCartPricingScheme($serviceA['uuid']);
        $this->createCartPricingRule($this->schemeForLatestVersion($serviceA['uuid']));

        $serviceB = $this->createCartService(overrides: ['payment_methods' => ['CARD', 'APPLE_PAY']]);
        $this->createCartPricingScheme($serviceB['uuid']);
        $this->createCartPricingRule($this->schemeForLatestVersion($serviceB['uuid']));

        $this->addCartItem($customer['access_token'], ['service_uuid' => $serviceA['uuid']])->assertStatus(201);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $serviceB['uuid']])->assertStatus(201);

        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);
        $slot = $this->createAppointmentSlot();
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);

        $checkout = $this->getCheckout($customer['access_token']);
        $codes = collect($checkout->json('data.checkout.available_payment_methods'))->pluck('code')->all();

        $this->assertEqualsCanonicalizing(['CARD', 'APPLE_PAY'], $codes);
        $this->assertTrue($checkout->json('data.checkout.requires_prepayment'));
    }

    public function test_mixed_cart_intersection_is_empty_when_services_share_no_method(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $serviceA = $this->createCartService(overrides: ['payment_methods' => ['PAY_ON_SITE']]);
        $this->createCartPricingScheme($serviceA['uuid']);
        $this->createCartPricingRule($this->schemeForLatestVersion($serviceA['uuid']));

        $serviceB = $this->createCartService(overrides: ['payment_methods' => ['CARD']]);
        $this->createCartPricingScheme($serviceB['uuid']);
        $this->createCartPricingRule($this->schemeForLatestVersion($serviceB['uuid']));

        $this->addCartItem($customer['access_token'], ['service_uuid' => $serviceA['uuid']])->assertStatus(201);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $serviceB['uuid']])->assertStatus(201);

        $checkout = $this->getCheckout($customer['access_token']);

        $this->assertSame([], $checkout->json('data.checkout.available_payment_methods'));

        // Neither an online payment nor a Pay-on-Site confirmation can
        // proceed - both must return a deterministic, safe rejection
        // before any Booking/payment is created.
        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);
        $slot = $this->createAppointmentSlot();
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);

        $this->createPayment($customer['access_token'], (string) Str::uuid(), 'CARD')->assertStatus(422);
        $this->postJson('/api/v1/bookings/pay-on-site', [], array_merge(
            ['Authorization' => 'Bearer '.$customer['access_token'], 'Idempotency-Key' => (string) Str::uuid()],
        ))->assertStatus(422);

        $this->assertSame(0, DB::table('payment_attempts')->count());
        $this->assertSame(0, DB::table('bookings')->count());
    }

    // -----------------------------------------------------------------
    // Existing CARD path - still unchanged, now with explicit selection
    // -----------------------------------------------------------------

    public function test_existing_card_path_still_works_with_explicit_payment_method(): void
    {
        $customer = $this->readyForPaymentCustomer();

        $response = $this->createPayment($customer['access_token'], (string) Str::uuid(), 'CARD');

        $response->assertStatus(201);
        $this->assertSame('PENDING', $response->json('data.payment.status'));
    }

    public function test_payment_method_is_required_and_validated_against_cart_policy(): void
    {
        $customer = $this->readyForPaymentCustomer();

        // Missing payment_method entirely.
        $this->createPayment($customer['access_token'], (string) Str::uuid(), null)->assertStatus(422);

        // PAY_ON_SITE is never a valid value for the online endpoint.
        $this->createPayment($customer['access_token'], (string) Str::uuid(), 'PAY_ON_SITE')->assertStatus(422);

        $this->assertSame(0, DB::table('payment_attempts')->count());
    }

    // -----------------------------------------------------------------
    // Pay-on-Site booking path
    // -----------------------------------------------------------------

    /**
     * @return array{customer: array{user_uuid: string, access_token: string}, service: array{uuid: string, slug: string}}
     */
    private function readyPayOnSiteCustomer(): array
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService(overrides: ['payment_methods' => ['PAY_ON_SITE', 'CARD']]);
        $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($this->schemeForLatestVersion($service['uuid']));

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);
        $slot = $this->createAppointmentSlot();
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);

        return ['customer' => $customer, 'service' => $service];
    }

    private function confirmPayOnSite(string $accessToken, ?string $idempotencyKey = null): TestResponse
    {
        $headers = ['Authorization' => 'Bearer '.$accessToken];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->postJson('/api/v1/bookings/pay-on-site', [], $headers);
    }

    public function test_pay_on_site_confirms_a_booking_without_stripe(): void
    {
        $fixture = $this->readyPayOnSiteCustomer();
        $key = (string) Str::uuid();

        $response = $this->confirmPayOnSite($fixture['customer']['access_token'], $key);

        $response->assertStatus(201);
        $this->assertSame('CONFIRMED', $response->json('data.booking.status'));
        $this->assertSame('PAY_ON_SITE', $response->json('data.booking.source'));
        $this->assertSame('PAY_ON_SITE', $response->json('data.booking.payment_method'));
        $this->assertSame('100.000000', $response->json('data.booking.on_site_payment.amount_due'));
        $this->assertSame('PENDING', $response->json('data.booking.on_site_payment.collection_status'));

        $this->assertSame(0, DB::table('payment_attempts')->count());

        $bookingUuid = $response->json('data.booking.uuid');
        $bookingRow = DB::table('bookings')->where('id', UuidBinary::toBinary($bookingUuid))->first();
        $this->assertNull($bookingRow->payment_attempt_id);
        $this->assertNotNull($bookingRow->idempotency_key);
    }

    public function test_pay_on_site_idempotency_key_replay_returns_the_same_booking(): void
    {
        $fixture = $this->readyPayOnSiteCustomer();
        $key = (string) Str::uuid();

        $first = $this->confirmPayOnSite($fixture['customer']['access_token'], $key);
        $first->assertStatus(201);

        // The Cart is now CONVERTED; a naive replay would find "no active
        // cart", but the Idempotency-Key must still resolve to the SAME
        // Booking rather than erroring.
        $second = $this->confirmPayOnSite($fixture['customer']['access_token'], $key);

        $second->assertStatus(200);
        $this->assertSame($first->json('data.booking.uuid'), $second->json('data.booking.uuid'));
        $this->assertSame(1, DB::table('bookings')->count());
    }

    public function test_pay_on_site_requires_the_idempotency_key_header(): void
    {
        $fixture = $this->readyPayOnSiteCustomer();

        $this->postJson('/api/v1/bookings/pay-on-site', [], ['Authorization' => 'Bearer '.$fixture['customer']['access_token']])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('bookings')->count());
    }

    public function test_pay_on_site_rejected_when_service_does_not_allow_it(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService(overrides: ['payment_methods' => ['CARD', 'APPLE_PAY']]);
        $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($this->schemeForLatestVersion($service['uuid']));

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);
        $slot = $this->createAppointmentSlot();
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);

        $this->confirmPayOnSite($customer['access_token'], (string) Str::uuid())->assertStatus(422);
        $this->assertSame(0, DB::table('bookings')->count());
    }

    // -----------------------------------------------------------------
    // On-site collection
    // -----------------------------------------------------------------

    public function test_admin_can_collect_on_site_payment_and_second_call_is_idempotent(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $fixture = $this->readyPayOnSiteCustomer();
        $booking = $this->confirmPayOnSite($fixture['customer']['access_token'], (string) Str::uuid());
        $bookingUuid = $booking->json('data.booking.uuid');

        $before = $this->auditLogsFor($bookingUuid)->count();

        $first = $this->postJson("/api/v1/admin/bookings/{$bookingUuid}/collect-on-site-payment", [], $this->bearer($admin['access_token']));
        $first->assertStatus(200);
        $this->assertSame('COLLECTED', $first->json('data.booking.on_site_settlement.collection_status'));
        $this->assertSame('100.000000', $first->json('data.booking.on_site_settlement.amount_collected'));
        $this->assertSame($before + 1, $this->auditLogsFor($bookingUuid)->count());

        // Second call is a safe no-op, not a duplicate collection or error.
        $second = $this->postJson("/api/v1/admin/bookings/{$bookingUuid}/collect-on-site-payment", [], $this->bearer($admin['access_token']));
        $second->assertStatus(200);
        $this->assertSame($before + 1, $this->auditLogsFor($bookingUuid)->count());
    }

    public function test_customer_cannot_collect_on_site_payment(): void
    {
        $fixture = $this->readyPayOnSiteCustomer();
        $booking = $this->confirmPayOnSite($fixture['customer']['access_token'], (string) Str::uuid());
        $bookingUuid = $booking->json('data.booking.uuid');

        $this->postJson("/api/v1/admin/bookings/{$bookingUuid}/collect-on-site-payment", [], [
            'Authorization' => 'Bearer '.$fixture['customer']['access_token'],
        ])->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // Cancellation before/after collection
    // -----------------------------------------------------------------

    public function test_cancelling_an_uncollected_pay_on_site_booking_creates_no_refund(): void
    {
        $fixture = $this->readyPayOnSiteCustomer();
        $booking = $this->confirmPayOnSite($fixture['customer']['access_token'], (string) Str::uuid());
        $bookingUuid = $booking->json('data.booking.uuid');

        $this->postJson("/api/v1/bookings/{$bookingUuid}/cancel", [], ['Authorization' => 'Bearer '.$fixture['customer']['access_token']])
            ->assertStatus(200);

        $this->assertSame(0, DB::table('booking_refunds')->count());

        $settlement = DB::table('booking_on_site_settlements')->where('booking_id', UuidBinary::toBinary($bookingUuid))->first();
        $this->assertNull($settlement->refund_status);
    }

    public function test_cancelling_a_collected_pay_on_site_booking_flags_manual_refund_required(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $fixture = $this->readyPayOnSiteCustomer();
        $booking = $this->confirmPayOnSite($fixture['customer']['access_token'], (string) Str::uuid());
        $bookingUuid = $booking->json('data.booking.uuid');

        $this->postJson("/api/v1/admin/bookings/{$bookingUuid}/collect-on-site-payment", [], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $this->postJson("/api/v1/bookings/{$bookingUuid}/cancel", [], ['Authorization' => 'Bearer '.$fixture['customer']['access_token']])
            ->assertStatus(200);

        $this->assertSame(0, DB::table('booking_refunds')->count());

        $settlement = DB::table('booking_on_site_settlements')->where('booking_id', UuidBinary::toBinary($bookingUuid))->first();
        $this->assertSame('MANUAL_REFUND_REQUIRED', $settlement->refund_status);
    }

    // -----------------------------------------------------------------
    // Historical immutability
    // -----------------------------------------------------------------

    public function test_later_payment_policy_change_never_alters_an_existing_bookings_snapshot(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $fixture = $this->readyPayOnSiteCustomer();
        $booking = $this->confirmPayOnSite($fixture['customer']['access_token'], (string) Str::uuid());
        $bookingUuid = $booking->json('data.booking.uuid');

        $this->postJson("/api/v1/admin/services/{$fixture['service']['uuid']}/payment-methods", [
            'payment_methods' => ['CARD', 'APPLE_PAY'],
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $reread = $this->getJson("/api/v1/bookings/{$bookingUuid}", ['Authorization' => 'Bearer '.$fixture['customer']['access_token']]);

        $this->assertSame('PAY_ON_SITE', $reread->json('data.booking.payment_method'));
        $this->assertSame('PAY_ON_SITE', $reread->json('data.booking.source'));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function schemeForLatestVersion(string $serviceUuid): string
    {
        $serviceIdBinary = UuidBinary::toBinary($serviceUuid);

        $versionId = DB::table('pricing_scheme_versions')
            ->where('service_id', $serviceIdBinary)
            ->orderByDesc('created_at')
            ->value('id');

        return UuidBinary::toString($versionId);
    }
}
