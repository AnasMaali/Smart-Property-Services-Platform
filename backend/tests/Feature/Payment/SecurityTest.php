<?php

namespace Tests\Feature\Payment;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Payment\Concerns\CreatesPaymentFixtures;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use CreatesPaymentFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    public function test_rejected_creation_leaves_no_partial_state_behind(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        // No location, no hold - readiness check fails inside Transaction A,
        // after the User/Cart locks but before any write.

        $cartUuid = $this->getCart($customer['access_token'])->json('data.cart.uuid');

        $this->createPayment($customer['access_token'], (string) Str::uuid())->assertStatus(422);

        $this->assertSame(0, DB::table('payment_attempts')->count());
        $this->assertSame('ACTIVE', $this->cartStatusCode($cartUuid));
        $this->assertSame(0, DB::table('appointment_holds')->count());
    }

    public function test_hold_renewal_never_happens_when_readiness_fails(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $slot = $this->createAppointmentSlot();
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);
        // Location still missing - not ready_for_payment.

        $holdUuid = $this->getCheckout($customer['access_token'])->json('data.checkout.appointment.hold_uuid');
        $expiresBefore = DB::table('appointment_holds')->where('id', UuidBinary::toBinary($holdUuid))->value('expires_at');

        $this->createPayment($customer['access_token'], (string) Str::uuid())->assertStatus(422);

        $expiresAfter = DB::table('appointment_holds')->where('id', UuidBinary::toBinary($holdUuid))->value('expires_at');
        $this->assertSame($expiresBefore, $expiresAfter);
    }

    public function test_payment_attempts_schema_has_no_card_data_columns(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->whereIn('table_name', ['payment_attempts', 'payment_webhook_events'])
            ->get()
            ->map(fn ($row) => strtolower((string) (get_object_vars($row)['COLUMN_NAME'] ?? get_object_vars($row)['column_name'] ?? '')))
            ->all();

        foreach (['card_number', 'cvv', 'cvc', 'pan', 'card_pan', 'stripe_secret_key', 'raw_payload'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    public function test_cross_customer_idempotency_key_reuse_never_leaks_the_original_payment(): void
    {
        $ownerA = $this->readyForPaymentCustomer();
        // appointment_slots enforces a unique (starts_at, ends_at) period -
        // give the second customer a distinct slot instead of the shared
        // default so the two fixtures don't collide.
        $ownerB = $this->readyForPaymentCustomer(['starts_at' => now()->addDays(2)]);
        $sharedKey = (string) Str::uuid();

        $first = $this->createPayment($ownerA['access_token'], $sharedKey);
        $first->assertStatus(201);

        $second = $this->createPayment($ownerB['access_token'], $sharedKey);

        $second->assertStatus(422);
        $this->assertArrayNotHasKey('payment', $second->json('data') ?? []);
    }
}
