<?php

namespace App\Actions\Appointment;

use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Checkout\AppointmentSlotAvailability;

/**
 * Cart-free bookable appointment-slot listing for authenticated customers
 * (GET /v1/appointment-slots). Used by Service Contract visit booking, which
 * has no ACTIVE cart and must not go through checkout's cart-gated
 * GET /v1/checkout/appointment-slots. Reuses the same
 * AppointmentSlotAvailability computation as Admin reschedule and checkout —
 * never a second availability formula.
 */
final class ListBookableAppointmentSlotsAction
{
    use BuildsCartResult;

    public function __construct(private readonly AppointmentSlotAvailability $availability = new AppointmentSlotAvailability) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return $this->ok(200, 'Appointment slots retrieved successfully.', [
            'appointment_slots' => $this->availability->bookableSlots(),
        ]);
    }
}
