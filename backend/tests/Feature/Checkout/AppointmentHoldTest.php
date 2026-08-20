<?php

namespace Tests\Feature\Checkout;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Checkout\Concerns\CreatesCheckoutFixtures;
use Tests\TestCase;

class AppointmentHoldTest extends TestCase
{
    use CreatesCheckoutFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    public function test_create_requires_auth(): void
    {
        $this->postJson('/api/v1/checkout/appointment-hold', ['appointment_slot_uuid' => UuidBinary::generate()])
            ->assertStatus(401);
    }

    public function test_delete_requires_auth(): void
    {
        $this->deleteJson('/api/v1/checkout/appointment-hold')->assertStatus(401);
    }

    public function test_valid_hold_succeeds(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        $slot = $this->createAppointmentSlot();

        $response = $this->createAppointmentHold($customer['access_token'], $slot['uuid']);

        $response->assertStatus(201);
        $this->assertSame($slot['uuid'], $response->json('data.checkout.appointment.slot.uuid'));
        $this->assertNotNull($response->json('data.checkout.appointment.hold_uuid'));
        $this->assertNotNull($response->json('data.checkout.appointment.expires_at'));
    }

    public function test_hold_belongs_to_the_customers_own_cart(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        $slot = $this->createAppointmentSlot();

        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);

        $cartId = DB::table('carts')->where('customer_user_id', UuidBinary::toBinary($customer['user_uuid']))->value('id');

        $this->assertDatabaseHas('appointment_holds', [
            'cart_id' => $cartId,
            'appointment_slot_id' => UuidBinary::toBinary($slot['uuid']),
        ]);
    }

    public function test_invalid_slot_rejected(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $response = $this->createAppointmentHold($customer['access_token'], UuidBinary::generate());

        $response->assertStatus(404);
    }

    public function test_past_slot_rejected(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        $slot = $this->createAppointmentSlot(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);

        $response = $this->createAppointmentHold($customer['access_token'], $slot['uuid']);

        $response->assertStatus(422);
    }

    public function test_selecting_a_new_slot_replaces_the_carts_previous_open_hold(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $slotA = $this->createAppointmentSlot();
        $slotB = $this->createAppointmentSlot(['starts_at' => now()->addDays(2)]);

        $firstResponse = $this->createAppointmentHold($customer['access_token'], $slotA['uuid']);
        $firstHoldUuid = $firstResponse->json('data.checkout.appointment.hold_uuid');

        $secondResponse = $this->createAppointmentHold($customer['access_token'], $slotB['uuid']);

        $secondResponse->assertStatus(201);
        $this->assertSame($slotB['uuid'], $secondResponse->json('data.checkout.appointment.slot.uuid'));

        $this->assertDatabaseHas('appointment_holds', ['id' => UuidBinary::toBinary($firstHoldUuid)]);
        $this->assertNotNull(
            DB::table('appointment_holds')->where('id', UuidBinary::toBinary($firstHoldUuid))->value('released_at'),
            'Selecting a new slot must release the cart\'s previous open hold.'
        );

        $checkout = $this->getCheckout($customer['access_token']);
        $this->assertSame($slotB['uuid'], $checkout->json('data.checkout.appointment.slot.uuid'));
    }

    public function test_expired_hold_is_reported_as_no_appointment_and_frees_capacity(): void
    {
        $customerA = $this->createAuthenticatedCartCustomer();
        $customerB = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customerA['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        $this->addCartItem($customerB['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $slot = $this->createAppointmentSlot(['booking_capacity' => 1]);
        $this->createAppointmentHold($customerA['access_token'], $slot['uuid'])->assertStatus(201);

        DB::table('appointment_holds')
            ->where('appointment_slot_id', UuidBinary::toBinary($slot['uuid']))
            ->update(['held_at' => now()->subHour(), 'expires_at' => now()->subMinute()]);

        $checkoutA = $this->getCheckout($customerA['access_token']);
        $this->assertNull($checkoutA->json('data.checkout.appointment'), 'An expired hold must not be reported as an active appointment.');

        // Capacity is freed for another customer once the hold has expired.
        $this->createAppointmentHold($customerB['access_token'], $slot['uuid'])->assertStatus(201);
    }

    public function test_concurrency_capacity_limited_slot_rejects_second_customer(): void
    {
        $customerA = $this->createAuthenticatedCartCustomer();
        $customerB = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customerA['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        $this->addCartItem($customerB['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $slot = $this->createAppointmentSlot(['booking_capacity' => 1]);

        $this->createAppointmentHold($customerA['access_token'], $slot['uuid'])->assertStatus(201);
        $response = $this->createAppointmentHold($customerB['access_token'], $slot['uuid']);

        $response->assertStatus(422);
        $this->assertSame(1, DB::table('appointment_holds')
            ->where('appointment_slot_id', UuidBinary::toBinary($slot['uuid']))
            ->whereNull('released_at')
            ->count());
    }

    public function test_release_is_a_safe_no_op_with_no_active_hold(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->releaseAppointmentHold($customer['access_token'])->assertStatus(200);
    }

    public function test_release_removes_the_customers_own_hold(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        $slot = $this->createAppointmentSlot();

        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);

        $response = $this->releaseAppointmentHold($customer['access_token']);

        $response->assertStatus(200);
        $this->assertNull($response->json('data.checkout.appointment'));
    }

    public function test_another_customers_hold_cannot_be_deleted(): void
    {
        $customerA = $this->createAuthenticatedCartCustomer();
        $customerB = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customerA['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        $this->addCartItem($customerB['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $slot = $this->createAppointmentSlot();
        $holdResponse = $this->createAppointmentHold($customerA['access_token'], $slot['uuid']);
        $holdUuid = $holdResponse->json('data.checkout.appointment.hold_uuid');

        // Customer B has no active hold of their own - releasing only ever touches the caller's cart.
        $this->releaseAppointmentHold($customerB['access_token'])->assertStatus(200);

        $this->assertDatabaseHas('appointment_holds', [
            'id' => UuidBinary::toBinary($holdUuid),
            'released_at' => null,
        ]);

        $checkoutA = $this->getCheckout($customerA['access_token']);
        $this->assertSame($holdUuid, $checkoutA->json('data.checkout.appointment.hold_uuid'));
    }

    // CreateAppointmentHoldAction, SaveCheckoutLocationAction, and
    // GetAppointmentSlotsAction all resolve their cart via `status_id =
    // ACTIVE` only - once a cart is frozen to CHECKOUT (e.g. by
    // CreatePaymentAttemptAction), none of them can find it any more, so a
    // customer can never attach a new hold or a new location to a cart a
    // payment attempt has already frozen. Release stays a safe no-op,
    // matching Cart's Clear-on-no-ACTIVE-cart convention.
    public function test_frozen_checkout_cart_rejects_new_location_and_appointment_hold(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $cartUuid = $this->getCart($customer['access_token'])->json('data.cart.uuid');
        DB::table('carts')->where('id', UuidBinary::toBinary($cartUuid))->update([
            'status_id' => (int) DB::table('cart_statuses')->where('code', 'CHECKOUT')->value('id'),
        ]);

        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))
            ->assertStatus(404)
            ->assertJsonPath('message', 'No active cart to check out.');
        $this->assertDatabaseCount('cart_locations', 0);

        $slot = $this->createAppointmentSlot();
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'No active cart to check out.');
        $this->assertDatabaseCount('appointment_holds', 0);

        $this->getAppointmentSlots($customer['access_token'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'No active cart to check out.');

        $this->releaseAppointmentHold($customer['access_token'])
            ->assertStatus(200)
            ->assertJsonPath('message', 'No active cart.');
    }
}
