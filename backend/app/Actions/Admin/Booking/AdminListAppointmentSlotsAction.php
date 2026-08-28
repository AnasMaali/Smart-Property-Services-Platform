<?php

namespace App\Actions\Admin\Booking;

use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Checkout\AppointmentSlotAvailability;

/**
 * Read-only bookable-slot listing for the Admin Reschedule Booking picker
 * (BLUE V1 Phase B19) - reuses the exact same
 * App\Support\Checkout\AppointmentSlotAvailability computation the customer
 * checkout endpoint (GET /v1/checkout/appointment-slots) already uses,
 * minus that endpoint's Cart requirement (an Admin has no Cart). Never a
 * second, divergent availability calculation.
 */
final class AdminListAppointmentSlotsAction
{
    use BuildsCartResult;

    public function __construct(private readonly AppointmentSlotAvailability $availability = new AppointmentSlotAvailability) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return $this->ok(200, 'Appointment slots retrieved successfully.', ['appointment_slots' => $this->availability->bookableSlots()]);
    }
}
