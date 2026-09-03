<?php

namespace Tests\Feature\Appointment;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Checkout\Concerns\CreatesCheckoutFixtures;
use Tests\TestCase;

class BookableAppointmentSlotsTest extends TestCase
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
        $this->getJson('/api/v1/appointment-slots')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_returns_bookable_slots_without_active_cart(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $slot = $this->createAppointmentSlot();

        $response = $this->getJson('/api/v1/appointment-slots', [
            'Authorization' => 'Bearer '.$customer['access_token'],
        ]);

        $response->assertStatus(200);
        $uuids = array_column($response->json('data.appointment_slots'), 'uuid');
        $this->assertContains($slot['uuid'], $uuids);
    }
}
