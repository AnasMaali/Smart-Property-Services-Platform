<?php

namespace Tests\Feature\Payment;

use App\Support\Payment\CheckoutReferenceGenerator;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Payment\Concerns\CreatesPaymentFixtures;
use Tests\TestCase;

class CreatePaymentTest extends TestCase
{
    use CreatesPaymentFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    public function test_authentication_is_required(): void
    {
        $this->postJson('/api/v1/payments', [], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(401);
    }

    public function test_idempotency_key_header_is_required(): void
    {
        $customer = $this->readyForPaymentCustomer();

        $this->postJson('/api/v1/payments', [], ['Authorization' => 'Bearer '.$customer['access_token']])
            ->assertStatus(422);
    }

    public function test_malformed_idempotency_key_is_rejected(): void
    {
        $customer = $this->readyForPaymentCustomer();

        $this->createPayment($customer['access_token'], 'not-a-uuid')->assertStatus(422);

        $this->assertSame(0, DB::table('payment_attempts')->count());
    }

    public function test_checkout_readiness_is_required(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        // No location, no appointment hold - not ready_for_payment.

        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('payment_attempts')->count());
    }

    public function test_no_active_cart_returns_not_found(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->createPayment($customer['access_token'], (string) Str::uuid())->assertStatus(404);
    }

    public function test_successful_creation_returns_pending_payment(): void
    {
        $customer = $this->readyForPaymentCustomer();

        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.payment.status', 'PENDING');
        $this->assertNotNull($response->json('data.payment.uuid'));
        $this->assertNotNull($response->json('data.payment.checkout_reference'));
        $this->assertSame('100.000000', $response->json('data.payment.requested_amount'));
        $this->assertSame('AED', $response->json('data.payment.currency.code'));
        $this->assertSame('FAKE', $response->json('data.payment.provider'));
    }

    public function test_same_idempotency_key_returns_same_attempt_without_creating_a_second_row(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $key = (string) Str::uuid();

        $first = $this->createPayment($customer['access_token'], $key);
        $first->assertStatus(201);

        $second = $this->createPayment($customer['access_token'], $key);
        $second->assertStatus(200);

        $this->assertSame($first->json('data.payment.uuid'), $second->json('data.payment.uuid'));
        $this->assertSame(1, DB::table('payment_attempts')->count());
    }

    public function test_same_idempotency_key_never_calls_the_gateway_twice(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $key = (string) Str::uuid();

        $this->createPayment($customer['access_token'], $key)->assertStatus(201);
        $this->createPayment($customer['access_token'], $key)->assertStatus(200);

        $this->assertCount(1, $this->fakeGateway()->createPaymentCalls);
    }

    public function test_only_one_open_attempt_per_cart(): void
    {
        $customer = $this->readyForPaymentCustomer();

        $this->createPayment($customer['access_token'], (string) Str::uuid())->assertStatus(201);

        // A second, genuinely different Idempotency-Key for the same
        // (now CHECKOUT) cart must not create a second concurrent open
        // attempt - it's a known, owned financial state, not a not-found.
        $second = $this->createPayment($customer['access_token'], (string) Str::uuid());

        $second->assertStatus(409);
        $this->assertSame(1, DB::table('payment_attempts')->count());
        $this->assertCount(1, $this->fakeGateway()->createPaymentCalls);
    }

    public function test_different_key_while_open_payment_exists_never_leaks_another_customers_payment(): void
    {
        $owner = $this->readyForPaymentCustomer();
        $ownerFirst = $this->createPayment($owner['access_token'], (string) Str::uuid());
        $ownerFirst->assertStatus(201);

        $stranger = $this->readyForPaymentCustomer(['starts_at' => now()->addDays(2)]);
        $strangerFirst = $this->createPayment($stranger['access_token'], (string) Str::uuid());
        $strangerFirst->assertStatus(201);

        // A different customer's own second, different-key attempt must
        // report conflict against their own open payment only - never
        // resolve to, or otherwise expose, the owner's attempt.
        $strangerSecond = $this->createPayment($stranger['access_token'], (string) Str::uuid());

        $strangerSecond->assertStatus(409);
        $this->assertNull($strangerSecond->json('data.payment.uuid'));
        $this->assertSame(2, DB::table('payment_attempts')->count());
    }

    public function test_open_cart_marker_backstops_concurrent_different_key_attempts(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $first = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $first->assertStatus(201);

        $firstRow = $this->paymentRow($first->json('data.payment.uuid'));

        $secondId = UuidBinary::toBinary(UuidBinary::generate());
        $now = now()->format('Y-m-d H:i:s.u');

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('payment_attempts')->insert([
            'id' => $secondId,
            'cart_id' => $firstRow->cart_id,
            'appointment_hold_id' => $firstRow->appointment_hold_id,
            'status_id' => $firstRow->status_id,
            'currency_id' => $firstRow->currency_id,
            'checkout_reference' => CheckoutReferenceGenerator::generate(),
            'idempotency_key' => hash('sha256', Str::uuid()->toString(), true),
            'provider_code' => 'FAKE',
            'requested_amount' => $firstRow->requested_amount,
            'checkout_snapshot' => $firstRow->checkout_snapshot,
            'checkout_snapshot_hash' => $firstRow->checkout_snapshot_hash,
            'expires_at' => $firstRow->expires_at,
            'status_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_requested_amount_is_server_authoritative_and_ignores_client_amount(): void
    {
        $customer = $this->readyForPaymentCustomer();

        $response = $this->postJson('/api/v1/payments', ['amount' => '1.000000'], [
            'Authorization' => 'Bearer '.$customer['access_token'],
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('payment_attempts')->count());
    }

    public function test_financial_request_fields_are_rejected(): void
    {
        $customer = $this->readyForPaymentCustomer();

        foreach (['currency' => 'USD', 'payment_status' => 'SUCCESSFUL', 'provider_status' => 'succeeded', 'success' => true, 'checkout_reference' => 'client-supplied', 'cart_uuid' => UuidBinary::generate()] as $field => $value) {
            $response = $this->postJson('/api/v1/payments', [$field => $value], [
                'Authorization' => 'Bearer '.$customer['access_token'],
                'Idempotency-Key' => (string) Str::uuid(),
            ]);

            $response->assertStatus(422);
        }

        $this->assertSame(0, DB::table('payment_attempts')->count());
    }

    public function test_cart_transitions_from_active_to_checkout(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $cartUuid = $this->getCheckout($customer['access_token'])->json('data.checkout.cart_uuid');

        $this->assertSame('ACTIVE', $this->cartStatusCode($cartUuid));

        $this->createPayment($customer['access_token'], (string) Str::uuid())->assertStatus(201);

        $this->assertSame('CHECKOUT', $this->cartStatusCode($cartUuid));
    }

    /**
     * "Blocked" here means the CHECKOUT cart itself - its items, location,
     * and frozen payment snapshot - can never be mutated again, NOT that
     * the customer is prohibited from starting a completely separate new
     * order. AddCartItemAction only ever resolves/mutates the customer's
     * ACTIVE cart, so once the original cart is CHECKOUT (frozen), a new
     * cart mutation transparently opens a brand new, independent ACTIVE
     * cart instead of erroring or touching the frozen one - that new-cart
     * fallback is the actual mutation-blocking mechanism, and the old
     * payment must still be fully recoverable via GET /payments/{payment}
     * throughout.
     */
    public function test_checkout_cart_is_immutable_once_frozen_but_a_new_active_cart_may_be_opened(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $originalCartUuid = $this->getCheckout($customer['access_token'])->json('data.checkout.cart_uuid');

        $paymentUuid = $this->createPayment($customer['access_token'], (string) Str::uuid())
            ->assertStatus(201)
            ->json('data.payment.uuid');
        $this->assertSame('CHECKOUT', $this->cartStatusCode($originalCartUuid));

        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $newCartUuid = $this->getCart($customer['access_token'])->json('data.cart.uuid');

        // A completely separate, independent future order.
        $this->assertNotSame($originalCartUuid, $newCartUuid);
        $this->assertSame(
            1,
            DB::table('cart_items')->where('cart_id', UuidBinary::toBinary($originalCartUuid))->count(),
        );

        // The frozen cart's own state is untouched by the new order.
        $this->assertSame('CHECKOUT', $this->cartStatusCode($originalCartUuid));

        // The old payment remains fully recoverable throughout.
        $this->getPayment($customer['access_token'], $paymentUuid)
            ->assertStatus(200)
            ->assertJsonPath('data.payment.uuid', $paymentUuid);
    }

    public function test_hold_is_renewed_and_expires_at_matches_payment_expiry(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $checkoutBefore = $this->getCheckout($customer['access_token']);
        $holdUuidBefore = $checkoutBefore->json('data.checkout.appointment.hold_uuid');
        $expiresBefore = $this->paymentRowExpiryFromHold($holdUuidBefore);

        $this->travel(5)->minutes();

        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $response->assertStatus(201);

        $paymentRow = $this->paymentRow($response->json('data.payment.uuid'));
        $holdRow = DB::table('appointment_holds')->where('id', $paymentRow->appointment_hold_id)->first();

        $this->assertSame(UuidBinary::toString($paymentRow->appointment_hold_id), $holdUuidBefore);
        $this->assertNotSame($expiresBefore, $holdRow->expires_at);
        $this->assertSame(
            Carbon::parse($holdRow->expires_at)->format('Y-m-d H:i:s'),
            Carbon::parse($paymentRow->expires_at)->format('Y-m-d H:i:s'),
        );
    }

    public function test_appointment_hold_id_is_persisted_on_the_attempt(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $holdUuid = $this->getCheckout($customer['access_token'])->json('data.checkout.appointment.hold_uuid');

        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $paymentRow = $this->paymentRow($response->json('data.payment.uuid'));

        $this->assertSame($holdUuid, UuidBinary::toString($paymentRow->appointment_hold_id));
    }

    public function test_payment_ownership_is_enforced_on_get(): void
    {
        $owner = $this->readyForPaymentCustomer();
        $stranger = $this->createAuthenticatedCartCustomer();

        $created = $this->createPayment($owner['access_token'], (string) Str::uuid());
        $paymentUuid = $created->json('data.payment.uuid');

        $this->getPayment($owner['access_token'], $paymentUuid)->assertStatus(200);
        $this->getPayment($stranger['access_token'], $paymentUuid)->assertStatus(404);
    }

    public function test_unknown_payment_uuid_returns_not_found(): void
    {
        $customer = $this->readyForPaymentCustomer();

        $this->getPayment($customer['access_token'], UuidBinary::generate())->assertStatus(404);
    }


    public function test_malformed_payment_uuid_returns_clean_not_found(): void
    {
        $customer = $this->readyForPaymentCustomer();

        $this->getPayment(
            $customer['access_token'],
            'not-a-uuid'
        )->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Payment not found.',
            ]);
    }

    public function test_foreign_and_unknown_payment_are_publicly_indistinguishable(): void
    {
        $owner = $this->readyForPaymentCustomer();
        $stranger = $this->createAuthenticatedCartCustomer();

        $created = $this->createPayment(
            $owner['access_token'],
            (string) Str::uuid()
        );

        $foreignPaymentUuid = $created->json('data.payment.uuid');

        $foreign = $this->getPayment(
            $stranger['access_token'],
            $foreignPaymentUuid
        );

        $unknown = $this->getPayment(
            $stranger['access_token'],
            UuidBinary::generate()
        );

        $foreign->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Payment not found.',
            ]);

        $unknown->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Payment not found.',
            ]);

        $this->assertSame(
            $foreign->json('message'),
            $unknown->json('message')
        );
    }

    public function test_get_payment_requires_authentication(): void
    {
        $this->getJson(
            '/api/v1/payments/'.UuidBinary::generate()
        )->assertStatus(401);
    }

    public function test_get_payment_response_never_leaks_hashes_or_snapshot(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $created = $this->createPayment($customer['access_token'], (string) Str::uuid());

        $response = $this->getPayment($customer['access_token'], $created->json('data.payment.uuid'));

        $payload = $response->json('data.payment');
        $this->assertArrayNotHasKey('checkout_snapshot', $payload);
        $this->assertArrayNotHasKey('checkout_snapshot_hash', $payload);
        $this->assertArrayNotHasKey('idempotency_key', $payload);
        $this->assertArrayNotHasKey('client_secret', $payload);
        $this->assertArrayNotHasKey('publishable_key', $payload);
    }

    // Allowlist companion to test_get_payment_response_never_leaks_hashes_or_
    // snapshot() above - that test proves specific forbidden fields are
    // absent, this proves the response contains EXACTLY the documented
    // public shape (docs/api-contracts/payments-v1.md "Get Payment") and
    // nothing else, so any future field added to PaymentPresenter for an
    // internal reason is caught here even if nobody thinks to forbid it by
    // name first.
    public function test_get_payment_response_exposes_only_the_documented_public_field_set(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $created = $this->createPayment($customer['access_token'], (string) Str::uuid());

        $response = $this->getPayment($customer['access_token'], $created->json('data.payment.uuid'));

        $response->assertStatus(200);
        $payload = $response->json('data.payment');

        $this->assertSame(
            ['uuid', 'checkout_reference', 'status', 'requested_amount', 'currency', 'expires_at', 'provider'],
            array_keys($payload)
        );
        $this->assertSame(['code', 'symbol', 'decimal_places'], array_keys($payload['currency']));
    }

    // docs/api-contracts/payments-v1.md "Get Payment": client_secret is
    // "never reconstructed on a later read" - that guarantee applies just
    // as much to a same-key retry that resolves the existing attempt
    // without calling the gateway again as it does to a plain GET.
    // client_secret is never persisted, so a retry response built only
    // from the stored row can never contain it, even though the very
    // first response for the same Idempotency-Key did.
    public function test_idempotent_retry_response_never_re_exposes_the_original_client_secret(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $key = (string) Str::uuid();

        $first = $this->createPayment($customer['access_token'], $key);
        $first->assertStatus(201);
        $this->assertNotNull($first->json('data.payment.client_secret'));

        $second = $this->createPayment($customer['access_token'], $key);
        $second->assertStatus(200);

        $this->assertSame($first->json('data.payment.uuid'), $second->json('data.payment.uuid'));
        $this->assertArrayNotHasKey('client_secret', $second->json('data.payment'));
        $this->assertArrayNotHasKey('publishable_key', $second->json('data.payment'));
    }

    public function test_raw_idempotency_key_is_never_stored(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $key = (string) Str::uuid();

        $response = $this->createPayment($customer['access_token'], $key);
        $paymentRow = $this->paymentRow($response->json('data.payment.uuid'));

        $this->assertNotSame($key, $paymentRow->idempotency_key);
        $this->assertSame(32, strlen($paymentRow->idempotency_key));
        $this->assertSame(hash('sha256', strtolower($key), true), $paymentRow->idempotency_key);
    }

    public function test_timestamps_preserve_microsecond_precision(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $paymentRow = $this->paymentRow($response->json('data.payment.uuid'));

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $paymentRow->created_at);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $paymentRow->expires_at);
    }

    private function paymentRowExpiryFromHold(string $holdUuid): string
    {
        return DB::table('appointment_holds')->where('id', UuidBinary::toBinary($holdUuid))->value('expires_at');
    }
}
