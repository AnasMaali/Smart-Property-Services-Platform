<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\Feature\Booking\Concerns\CreatesBookingFixtures;
use Tests\Feature\Technician\Concerns\CreatesTechnicianFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B25 - Inspection -> Repair Quote -> Historical Credit ->
 * Remaining Balance. Covers: eligibility gating (Service policy, inspection
 * completion, confirmed online payment), server-computed credit/balance,
 * quote lifecycle (draft/edit/send/accept/decline), revision/double-credit
 * prevention, the below-credit rejection, zero-balance funding, the
 * Cart-less balance-payment webhook path, historical price-change
 * immunity, customer ownership, and Admin step-up.
 */
class InspectionQuoteCreditV1Test extends TestCase
{
    use CreatesAdminFixtures;
    use CreatesBookingFixtures;
    use CreatesTechnicianFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    // -----------------------------------------------------------------
    // Fixture: a fully paid, inspection-completed Booking Item ready for
    // quote creation.
    // -----------------------------------------------------------------

    /**
     * @return array{customer: array, service: array, booking: object, item: object, admin: array}
     */
    private function readyInspectionFixture(string $inspectionPrice = '150.000000', array $serviceOverrides = []): array
    {
        $service = $this->createCartService(overrides: $serviceOverrides);
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['effect_amount' => $inspectionPrice]);
        DB::table('services')->where('id', UuidBinary::toBinary($service['uuid']))->update(['inspection_quote_credit_enabled' => 1]);

        $specializationId = $this->createSpecialization();
        $this->linkServiceSpecialization($service['uuid'], $specializationId);

        $customer = $this->createAuthenticatedCartCustomer();

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid'], 'quantity' => 1])->assertStatus(201);

        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);
        $slot = $this->createAppointmentSlot();
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);

        $createResponse = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $paymentRow = $this->paymentRow($createResponse->json('data.payment.uuid'));

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $paymentRow->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $paymentRow->requested_amount,
        ]))->assertStatus(200);

        $payment = $this->paymentRow(UuidBinary::toString($paymentRow->id));
        $booking = $this->bookingRowForPayment($payment);
        $this->assertNotNull($booking, 'Booking was not created from the successful payment.');

        $item = DB::table('booking_items')->where('booking_id', $booking->id)->first();

        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $technician = $this->createEligibleTechnician($specializationId);
        $itemUuid = UuidBinary::toString($item->id);

        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/assign-technician", ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(201);
        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/start-work", ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);
        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/complete-work", ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);

        return [
            'customer' => $customer,
            'service' => $service,
            'booking' => $this->bookingRow(UuidBinary::toString($booking->id)),
            'item' => DB::table('booking_items')->where('id', $item->id)->first(),
            'admin' => $admin,
        ];
    }

    private function createQuoteUrl(object $item): string
    {
        return '/api/v1/admin/booking-items/'.UuidBinary::toString($item->id).'/repair-quotes';
    }

    private function quoteUrl(string $quoteUuid, string $suffix = ''): string
    {
        return '/api/v1/admin/repair-quotes/'.$quoteUuid.$suffix;
    }

    private function quoteRow(string $quoteUuid): ?object
    {
        return DB::table('booking_item_repair_quotes')->where('id', UuidBinary::toBinary($quoteUuid))->first();
    }

    // -----------------------------------------------------------------
    // Section 37 - golden business flow.
    // -----------------------------------------------------------------

    public function test_golden_business_flow_quote_credit_and_balance_payment(): void
    {
        $fixture = $this->readyInspectionFixture('150.000000');

        $create = $this->postJson($this->createQuoteUrl($fixture['item']), ['quoted_amount' => 1000], $this->bearer($fixture['admin']['access_token']));
        $create->assertStatus(201);

        $this->assertSame('1000.000000', $create->json('data.quote.quoted_amount'));
        $this->assertSame('150.000000', $create->json('data.quote.credit_amount'));
        $this->assertSame('850.000000', $create->json('data.quote.balance_due_amount'));

        $quoteUuid = $create->json('data.quote.uuid');

        $this->postJson($this->quoteUrl($quoteUuid, '/send'), [], $this->bearer($fixture['admin']['access_token']))->assertStatus(200);

        $accept = $this->postJson('/api/v1/bookings/'.UuidBinary::toString($fixture['booking']->id).'/quote/accept', [], ['Authorization' => 'Bearer '.$fixture['customer']['access_token']]);
        $accept->assertStatus(200)->assertJsonPath('data.quote.status', 'ACCEPTED');

        $bookingUuid = UuidBinary::toString($fixture['booking']->id);
        $pay = $this->postJson('/api/v1/bookings/'.$bookingUuid.'/quote/pay-balance', [], [
            'Authorization' => 'Bearer '.$fixture['customer']['access_token'],
            'Idempotency-Key' => (string) Str::uuid(),
        ]);
        $pay->assertStatus(201);
        $this->assertSame('850.000000', $pay->json('data.payment.requested_amount'));

        $balanceRow = DB::table('repair_quote_payment_attempts')->where('quote_id', UuidBinary::toBinary($quoteUuid))->first();
        $this->assertNotNull($balanceRow);

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $balanceRow->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $balanceRow->requested_amount,
        ]))->assertStatus(200);

        $fundedBalance = DB::table('repair_quote_payment_attempts')->where('id', $balanceRow->id)->first();
        $this->assertSame('SUCCESSFUL', DB::table('payment_statuses')->where('id', $fundedBalance->status_id)->value('code'));
        $this->assertSame('850.000000', (string) $fundedBalance->confirmed_amount);

        // Total customer funds received: 150 (inspection) + 850 (balance) = 1000.
        $inspectionPayment = DB::table('payment_attempts')->where('id', $fixture['booking']->payment_attempt_id)->first();
        $this->assertSame('150.000000', (string) $inspectionPayment->confirmed_amount);
        $total = bcadd((string) $inspectionPayment->confirmed_amount, (string) $fundedBalance->confirmed_amount, 6);
        $this->assertSame('1000.000000', $total);

        $read = $this->getJson('/api/v1/bookings/'.$bookingUuid.'/quote', ['Authorization' => 'Bearer '.$fixture['customer']['access_token']]);
        $read->assertStatus(200)->assertJsonPath('data.quote.funding_status', 'FULLY_FUNDED');
    }

    // -----------------------------------------------------------------
    // Section 38 - price-change immutability.
    // -----------------------------------------------------------------

    public function test_credit_remains_historical_after_service_price_change(): void
    {
        $fixture = $this->readyInspectionFixture('150.000000');

        $create = $this->postJson($this->createQuoteUrl($fixture['item']), ['quoted_amount' => 1000], $this->bearer($fixture['admin']['access_token']));
        $create->assertStatus(201);
        $this->assertSame('150.000000', $create->json('data.quote.credit_amount'));

        // Admin later changes the Service's future price to 200.
        $this->postJson("/api/v1/admin/services/{$fixture['service']['uuid']}/current-price", ['current_price' => 200], $this->bearer($fixture['admin']['access_token']))
            ->assertStatus(200);

        $quoteUuid = $create->json('data.quote.uuid');
        $reread = $this->quoteRow($quoteUuid);

        $this->assertSame('150.000000', (string) $reread->credit_amount);
    }

    // -----------------------------------------------------------------
    // Section 39 - revision without double credit.
    // -----------------------------------------------------------------

    public function test_quote_revision_supersedes_without_double_credit(): void
    {
        $fixture = $this->readyInspectionFixture('150.000000');

        $create = $this->postJson($this->createQuoteUrl($fixture['item']), ['quoted_amount' => 1000], $this->bearer($fixture['admin']['access_token']));
        $quoteAUuid = $create->json('data.quote.uuid');
        $this->postJson($this->quoteUrl($quoteAUuid, '/send'), [], $this->bearer($fixture['admin']['access_token']))->assertStatus(200);

        $revise = $this->postJson($this->quoteUrl($quoteAUuid, '/revise'), ['quoted_amount' => 1200], $this->bearer($fixture['admin']['access_token']));
        $revise->assertStatus(201);
        $quoteBUuid = $revise->json('data.quote.uuid');

        $this->assertSame('1200.000000', $revise->json('data.quote.quoted_amount'));
        $this->assertSame('150.000000', $revise->json('data.quote.credit_amount'));
        $this->assertSame('1050.000000', $revise->json('data.quote.balance_due_amount'));

        $quoteA = $this->quoteRow($quoteAUuid);
        $this->assertSame('CANCELLED', DB::table('booking_item_repair_quote_statuses')->where('id', $quoteA->status_id)->value('code'));
        $this->assertNotNull($quoteA->closed_at);

        // No double credit: exactly one credit row per quote, summing to
        // 150 total eligible credit ever granted for this Booking Item -
        // never 300.
        $totalCreditRows = DB::table('repair_quote_credits')
            ->whereIn('quote_id', [UuidBinary::toBinary($quoteAUuid), UuidBinary::toBinary($quoteBUuid)])
            ->get();
        $this->assertCount(2, $totalCreditRows);
        $this->assertSame('150.000000', (string) $totalCreditRows[0]->amount);
        $this->assertSame('150.000000', (string) $totalCreditRows[1]->amount);

        // Quote A is no longer actionable.
        $this->postJson($this->quoteUrl($quoteAUuid, '/send'), [], $this->bearer($fixture['admin']['access_token']))->assertStatus(422);
    }

    public function test_concurrent_revision_attempts_cannot_double_activate_a_quote(): void
    {
        $fixture = $this->readyInspectionFixture('150.000000');

        $create = $this->postJson($this->createQuoteUrl($fixture['item']), ['quoted_amount' => 1000], $this->bearer($fixture['admin']['access_token']));
        $quoteUuid = $create->json('data.quote.uuid');
        $this->postJson($this->quoteUrl($quoteUuid, '/send'), [], $this->bearer($fixture['admin']['access_token']))->assertStatus(200);

        // A second, independent attempt to create a brand-new quote for the
        // SAME Booking Item while one is already active must be rejected -
        // the database's own active-marker UNIQUE constraint is the actual
        // backstop even if the explicit pre-check were ever bypassed.
        $second = $this->postJson($this->createQuoteUrl($fixture['item']), ['quoted_amount' => 1100], $this->bearer($fixture['admin']['access_token']));
        $second->assertStatus(409);

        $activeCount = DB::table('booking_item_repair_quotes')
            ->where('booking_item_id', $fixture['item']->id)
            ->whereNull('closed_at')
            ->count();
        $this->assertSame(1, $activeCount);
    }

    // -----------------------------------------------------------------
    // Section 40 - below-credit rejection.
    // -----------------------------------------------------------------

    public function test_quote_below_eligible_credit_is_rejected(): void
    {
        $fixture = $this->readyInspectionFixture('150.000000');

        $response = $this->postJson($this->createQuoteUrl($fixture['item']), ['quoted_amount' => 100], $this->bearer($fixture['admin']['access_token']));

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('booking_item_repair_quotes')->where('booking_item_id', $fixture['item']->id)->count());
        $this->assertSame(0, DB::table('booking_refunds')->count());
    }

    // -----------------------------------------------------------------
    // Section 41 - zero-balance quote.
    // -----------------------------------------------------------------

    public function test_zero_balance_quote_requires_no_stripe_payment_intent(): void
    {
        $fixture = $this->readyInspectionFixture('150.000000');

        $create = $this->postJson($this->createQuoteUrl($fixture['item']), ['quoted_amount' => 150], $this->bearer($fixture['admin']['access_token']));
        $create->assertStatus(201);
        $this->assertSame('0.000000', $create->json('data.quote.balance_due_amount'));

        $quoteUuid = $create->json('data.quote.uuid');
        $this->postJson($this->quoteUrl($quoteUuid, '/send'), [], $this->bearer($fixture['admin']['access_token']))->assertStatus(200);

        $bookingUuid = UuidBinary::toString($fixture['booking']->id);
        $this->postJson('/api/v1/bookings/'.$bookingUuid.'/quote/accept', [], ['Authorization' => 'Bearer '.$fixture['customer']['access_token']])
            ->assertStatus(200)
            ->assertJsonPath('data.quote.funding_status', 'FULLY_FUNDED');

        $payAttempt = $this->postJson('/api/v1/bookings/'.$bookingUuid.'/quote/pay-balance', [], [
            'Authorization' => 'Bearer '.$fixture['customer']['access_token'],
            'Idempotency-Key' => (string) Str::uuid(),
        ]);
        $payAttempt->assertStatus(422);

        $this->assertSame(0, DB::table('repair_quote_payment_attempts')->where('quote_id', UuidBinary::toBinary($quoteUuid))->count());
    }

    // -----------------------------------------------------------------
    // Section 42 - unpaid inspection (Pay-on-Site source) cannot fund
    // credit.
    // -----------------------------------------------------------------

    public function test_quote_creation_rejected_when_inspection_not_backed_by_confirmed_online_payment(): void
    {
        $service = $this->createCartService(overrides: ['payment_methods' => ['PAY_ON_SITE', 'CARD']]);
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['effect_amount' => '150.000000']);
        DB::table('services')->where('id', UuidBinary::toBinary($service['uuid']))->update(['inspection_quote_credit_enabled' => 1]);

        $specializationId = $this->createSpecialization();
        $this->linkServiceSpecialization($service['uuid'], $specializationId);

        $customer = $this->createAuthenticatedCartCustomer();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid'], 'quantity' => 1])->assertStatus(201);
        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);
        $slot = $this->createAppointmentSlot();
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);

        $confirm = $this->postJson('/api/v1/bookings/pay-on-site', [], [
            'Authorization' => 'Bearer '.$customer['access_token'],
            'Idempotency-Key' => (string) Str::uuid(),
        ]);
        $confirm->assertStatus(201);

        $bookingUuid = $confirm->json('data.booking.uuid');
        $booking = $this->bookingRow($bookingUuid);
        $this->assertNull($booking->payment_attempt_id);

        $item = DB::table('booking_items')->where('booking_id', $booking->id)->first();

        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $technician = $this->createEligibleTechnician($specializationId);
        $itemUuid = UuidBinary::toString($item->id);
        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/assign-technician", ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(201);
        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/start-work", ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);
        $this->postJson("/api/v1/admin/booking-items/{$itemUuid}/complete-work", ['technician_uuid' => $technician['uuid']], $this->bearer($admin['access_token']))->assertStatus(200);

        $response = $this->postJson($this->createQuoteUrl($item), ['quoted_amount' => 1000], $this->bearer($admin['access_token']));

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('booking_item_repair_quotes')->where('booking_item_id', $item->id)->count());
    }

    // -----------------------------------------------------------------
    // Section 43 - customer ownership boundary.
    // -----------------------------------------------------------------

    public function test_customer_ownership_boundary_on_quote_endpoints(): void
    {
        $fixture = $this->readyInspectionFixture('150.000000');

        $create = $this->postJson($this->createQuoteUrl($fixture['item']), ['quoted_amount' => 1000], $this->bearer($fixture['admin']['access_token']));
        $quoteUuid = $create->json('data.quote.uuid');
        $this->postJson($this->quoteUrl($quoteUuid, '/send'), [], $this->bearer($fixture['admin']['access_token']))->assertStatus(200);

        $strangerToken = $this->createAuthenticatedCartCustomer()['access_token'];
        $bookingUuid = UuidBinary::toString($fixture['booking']->id);

        $this->getJson('/api/v1/bookings/'.$bookingUuid.'/quote', ['Authorization' => 'Bearer '.$strangerToken])->assertStatus(404);
        $this->postJson('/api/v1/bookings/'.$bookingUuid.'/quote/accept', [], ['Authorization' => 'Bearer '.$strangerToken])->assertStatus(404);
        $this->postJson('/api/v1/bookings/'.$bookingUuid.'/quote/decline', [], ['Authorization' => 'Bearer '.$strangerToken])->assertStatus(404);
        $this->postJson('/api/v1/bookings/'.$bookingUuid.'/quote/pay-balance', [], [
            'Authorization' => 'Bearer '.$strangerToken,
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Section 44 - Admin step-up required.
    // -----------------------------------------------------------------

    public function test_quote_creation_requires_admin_step_up(): void
    {
        $fixture = $this->readyInspectionFixture('150.000000');
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->postJson($this->createQuoteUrl($fixture['item']), ['quoted_amount' => 1000], $this->bearer($admin['access_token']));

        $response->assertStatus(428);
        $this->assertSame(0, DB::table('booking_item_repair_quotes')->where('booking_item_id', $fixture['item']->id)->count());
    }

    // -----------------------------------------------------------------
    // Additional coverage: decline, double-decline idempotency, cannot
    // accept-after-decline, draft edit/cancel, service policy gate.
    // -----------------------------------------------------------------

    public function test_customer_can_decline_a_sent_quote_and_repeat_decline_is_idempotent(): void
    {
        $fixture = $this->readyInspectionFixture('150.000000');
        $create = $this->postJson($this->createQuoteUrl($fixture['item']), ['quoted_amount' => 1000], $this->bearer($fixture['admin']['access_token']));
        $quoteUuid = $create->json('data.quote.uuid');
        $this->postJson($this->quoteUrl($quoteUuid, '/send'), [], $this->bearer($fixture['admin']['access_token']))->assertStatus(200);

        $bookingUuid = UuidBinary::toString($fixture['booking']->id);
        $headers = ['Authorization' => 'Bearer '.$fixture['customer']['access_token']];

        $first = $this->postJson('/api/v1/bookings/'.$bookingUuid.'/quote/decline', [], $headers);
        $first->assertStatus(200)->assertJsonPath('data.quote.status', 'DECLINED');

        $second = $this->postJson('/api/v1/bookings/'.$bookingUuid.'/quote/decline', [], $headers);
        $second->assertStatus(200)->assertJsonPath('data.quote.status', 'DECLINED');

        $this->postJson('/api/v1/bookings/'.$bookingUuid.'/quote/accept', [], $headers)->assertStatus(422);
    }

    public function test_admin_can_edit_and_cancel_a_draft_quote(): void
    {
        $fixture = $this->readyInspectionFixture('150.000000');
        $create = $this->postJson($this->createQuoteUrl($fixture['item']), ['quoted_amount' => 1000], $this->bearer($fixture['admin']['access_token']));
        $quoteUuid = $create->json('data.quote.uuid');

        $edit = $this->patchJson($this->quoteUrl($quoteUuid), ['quoted_amount' => 900], $this->bearer($fixture['admin']['access_token']));
        $edit->assertStatus(200);
        $this->assertSame('750.000000', $edit->json('data.quote.balance_due_amount'));

        $cancel = $this->postJson($this->quoteUrl($quoteUuid, '/cancel'), [], $this->bearer($fixture['admin']['access_token']));
        $cancel->assertStatus(200)->assertJsonPath('data.quote.status', 'CANCELLED');

        $this->assertSame(422, $this->patchJson($this->quoteUrl($quoteUuid), ['quoted_amount' => 800], $this->bearer($fixture['admin']['access_token']))->status());
    }

    public function test_quote_creation_rejected_when_service_policy_disabled(): void
    {
        $fixture = $this->readyInspectionFixture('150.000000');

        DB::table('services')->where('id', UuidBinary::toBinary($fixture['service']['uuid']))->update(['inspection_quote_credit_enabled' => 0]);

        $response = $this->postJson($this->createQuoteUrl($fixture['item']), ['quoted_amount' => 1000], $this->bearer($fixture['admin']['access_token']));

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('booking_item_repair_quotes')->where('booking_item_id', $fixture['item']->id)->count());
    }

    public function test_admin_can_toggle_service_inspection_quote_policy(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();

        $enable = $this->patchJson("/api/v1/admin/services/{$service['uuid']}/inspection-quote-policy", ['enabled' => true], $this->bearer($admin['access_token']));
        $enable->assertStatus(200)->assertJsonPath('data.service.inspection_quote_policy.enabled', true);

        $detail = $this->getJson("/api/v1/admin/services/{$service['uuid']}", $this->bearer($admin['access_token']));
        $detail->assertJsonPath('data.service.inspection_quote_policy.enabled', true);

        $unauthenticated = $this->patchJson("/api/v1/admin/services/{$service['uuid']}/inspection-quote-policy", ['enabled' => false]);
        $unauthenticated->assertStatus(401);
    }
}
