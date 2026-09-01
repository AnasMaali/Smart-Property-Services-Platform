<?php

namespace Tests\Feature\Checkout;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Checkout\Concerns\CreatesCheckoutFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B27 (Appointment Schedule Management) - the OPTIONAL
 * `?date=` filter on GET /v1/checkout/appointment-slots
 * (App\Actions\Checkout\GetAppointmentSlotsAction /
 * App\Support\Checkout\AppointmentSlotAvailability::slotsForDate()).
 * Additive only: every test in AppointmentSlotsTest.php (no `date` param)
 * continues to exercise the original, byte-for-byte unchanged
 * bookableSlots() path - not duplicated here.
 */
class AppointmentSlotsDateFilterTest extends TestCase
{
    use CreatesCheckoutFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    private function readyCustomer(): array
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        return $customer;
    }

    public function test_date_scoped_response_includes_available_full_and_closed_slots(): void
    {
        $customer = $this->readyCustomer();
        $day = now()->addDays(15);

        $available = $this->createAppointmentSlot(['starts_at' => $day->copy()->setTime(9, 0), 'ends_at' => $day->copy()->setTime(11, 0), 'booking_capacity' => 3]);
        $full = $this->createAppointmentSlot(['starts_at' => $day->copy()->setTime(11, 0), 'ends_at' => $day->copy()->setTime(13, 0), 'booking_capacity' => 1]);
        $closed = $this->createAppointmentSlot(['starts_at' => $day->copy()->setTime(13, 0), 'ends_at' => $day->copy()->setTime(15, 0), 'is_active' => 0]);

        $blocker = $this->readyCustomer();
        $this->createAppointmentHold($blocker['access_token'], $full['uuid'])->assertStatus(201);

        $response = $this->getJson('/api/v1/checkout/appointment-slots?date='.$day->format('Y-m-d'), ['Authorization' => 'Bearer '.$customer['access_token']])
            ->assertStatus(200);

        $byUuid = collect($response->json('data.appointment_slots'))->keyBy('uuid');

        $this->assertSame('AVAILABLE', $byUuid[$available['uuid']]['availability_status']);
        $this->assertTrue($byUuid[$available['uuid']]['is_available']);

        $this->assertSame('FULL', $byUuid[$full['uuid']]['availability_status']);
        $this->assertFalse($byUuid[$full['uuid']]['is_available']);
        $this->assertSame(0, $byUuid[$full['uuid']]['remaining_capacity']);

        $this->assertSame('CLOSED', $byUuid[$closed['uuid']]['availability_status']);
        $this->assertFalse($byUuid[$closed['uuid']]['is_available']);

        // Every field the contract promises, nothing extra/unsafe.
        $this->assertSame(
            ['uuid', 'starts_at', 'ends_at', 'booking_capacity', 'occupied_capacity', 'remaining_capacity', 'is_available', 'availability_status', 'time_window'],
            array_keys($byUuid[$available['uuid']])
        );
    }

    public function test_malformed_date_returns_422(): void
    {
        $customer = $this->readyCustomer();

        $this->getJson('/api/v1/checkout/appointment-slots?date=not-a-date', ['Authorization' => 'Bearer '.$customer['access_token']])->assertStatus(422);
        $this->getJson('/api/v1/checkout/appointment-slots?date=2026-02-30', ['Authorization' => 'Bearer '.$customer['access_token']])->assertStatus(422);
        $this->getJson('/api/v1/checkout/appointment-slots?date=2026-9-5', ['Authorization' => 'Bearer '.$customer['access_token']])->assertStatus(422);
    }

    public function test_hold_creation_remains_authoritative_even_when_the_date_list_showed_capacity(): void
    {
        // Two customers both see remaining_capacity=1 for the same slot via
        // the date-scoped list; only one of them can actually convert that
        // into a hold - the list is never trusted as a reservation.
        $day = now()->addDays(16);
        $slot = $this->createAppointmentSlot(['starts_at' => $day->copy()->setTime(9, 0), 'ends_at' => $day->copy()->setTime(11, 0), 'booking_capacity' => 1]);

        $customerA = $this->readyCustomer();
        $customerB = $this->readyCustomer();

        $listA = $this->getJson('/api/v1/checkout/appointment-slots?date='.$day->format('Y-m-d'), ['Authorization' => 'Bearer '.$customerA['access_token']])->assertStatus(200);
        $listB = $this->getJson('/api/v1/checkout/appointment-slots?date='.$day->format('Y-m-d'), ['Authorization' => 'Bearer '.$customerB['access_token']])->assertStatus(200);

        $rowA = collect($listA->json('data.appointment_slots'))->firstWhere('uuid', $slot['uuid']);
        $rowB = collect($listB->json('data.appointment_slots'))->firstWhere('uuid', $slot['uuid']);
        $this->assertSame(1, $rowA['remaining_capacity']);
        $this->assertSame(1, $rowB['remaining_capacity']);

        $this->createAppointmentHold($customerA['access_token'], $slot['uuid'])->assertStatus(201);
        $this->createAppointmentHold($customerB['access_token'], $slot['uuid'])->assertStatus(422);
    }

    public function test_past_date_is_a_safe_informational_lookup_not_an_error(): void
    {
        $customer = $this->readyCustomer();
        $pastDay = now()->subDays(5);

        $slot = $this->createAppointmentSlot(['starts_at' => $pastDay->copy()->setTime(9, 0), 'ends_at' => $pastDay->copy()->setTime(11, 0)]);

        $response = $this->getJson('/api/v1/checkout/appointment-slots?date='.$pastDay->format('Y-m-d'), ['Authorization' => 'Bearer '.$customer['access_token']])
            ->assertStatus(200);

        $uuids = array_column($response->json('data.appointment_slots'), 'uuid');
        $this->assertContains($slot['uuid'], $uuids);
    }

    public function test_omitting_date_preserves_the_original_flat_bookable_only_response(): void
    {
        $customer = $this->readyCustomer();
        $slot = $this->createAppointmentSlot();

        $response = $this->getJson('/api/v1/checkout/appointment-slots', ['Authorization' => 'Bearer '.$customer['access_token']])->assertStatus(200);

        $row = collect($response->json('data.appointment_slots'))->firstWhere('uuid', $slot['uuid']);
        $this->assertNotNull($row);
        $this->assertSame(['uuid', 'starts_at', 'ends_at', 'remaining_capacity', 'time_window'], array_keys($row));
        $this->assertArrayNotHasKey('date', $response->json('data'));
    }

    public function test_no_active_cart_is_still_404_regardless_of_date_param(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $this->getJson('/api/v1/checkout/appointment-slots?date='.now()->addDay()->format('Y-m-d'), ['Authorization' => 'Bearer '.$customer['access_token']])->assertStatus(404);
    }
}
