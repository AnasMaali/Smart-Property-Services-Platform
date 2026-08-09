<?php

namespace Tests\Feature\Checkout;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Checkout\Concerns\CreatesCheckoutFixtures;
use Tests\TestCase;

class AppointmentSlotsTest extends TestCase
{
    use CreatesCheckoutFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/checkout/appointment-slots')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_no_active_cart_rejected(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->getAppointmentSlots($customer['access_token'])->assertStatus(404);
    }

    public function test_available_future_slots_are_returned(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $slot = $this->createAppointmentSlot();

        $response = $this->getAppointmentSlots($customer['access_token']);

        $response->assertStatus(200);
        $uuids = array_column($response->json('data.appointment_slots'), 'uuid');
        $this->assertContains($slot['uuid'], $uuids);
    }

    public function test_inactive_slot_excluded(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $slot = $this->createAppointmentSlot(['is_active' => 0]);

        $response = $this->getAppointmentSlots($customer['access_token']);

        $uuids = array_column($response->json('data.appointment_slots'), 'uuid');
        $this->assertNotContains($slot['uuid'], $uuids);
    }

    public function test_past_slot_excluded(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $slot = $this->createAppointmentSlot([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subDay()->addHours(2),
        ]);

        $response = $this->getAppointmentSlots($customer['access_token']);

        $uuids = array_column($response->json('data.appointment_slots'), 'uuid');
        $this->assertNotContains($slot['uuid'], $uuids);
    }

    public function test_fully_booked_slot_excluded(): void
    {
        $customerA = $this->createAuthenticatedCartCustomer();
        $customerB = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customerA['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        $this->addCartItem($customerB['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $slot = $this->createAppointmentSlot(['booking_capacity' => 1]);

        $this->createAppointmentHold($customerA['access_token'], $slot['uuid'])->assertStatus(201);

        $response = $this->getAppointmentSlots($customerB['access_token']);

        $uuids = array_column($response->json('data.appointment_slots'), 'uuid');
        $this->assertNotContains($slot['uuid'], $uuids);
    }

    public function test_slot_response_contains_only_safe_fields(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $this->createAppointmentSlot(['internal_note' => 'Staffing note, never customer-facing.']);

        $response = $this->getAppointmentSlots($customer['access_token']);
        $slot = $response->json('data.appointment_slots.0');

        $this->assertSame(['uuid', 'starts_at', 'ends_at', 'remaining_capacity', 'time_window'], array_keys($slot));
        $this->assertSame(['code', 'name'], array_keys($slot['time_window']));
    }
}
