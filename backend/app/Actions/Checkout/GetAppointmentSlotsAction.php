<?php

namespace App\Actions\Checkout;

use App\Models\Cart;
use App\Support\Cart\CartStatuses;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Checkout\AppointmentSlotAvailability;
use App\Support\Uuid\UuidBinary;

/**
 * Read-only availability list. appointment_slots carries no service/zone
 * dimension in the schema (no service_id or area_id column), so BLUE V1
 * cannot scope slots to "this cart's services" beyond what the table
 * itself already represents - every currently bookable slot is returned
 * identically regardless of cart contents. A slot is bookable when it is
 * active, still in the future, has an active appointment_time_windows row,
 * and has remaining capacity once every currently-occupying hold
 * (converted, or unexpired and unreleased) is counted - no capacity/
 * duration/staffing rule is invented here.
 *
 * BLUE V1 Phase B27 (Appointment Schedule Management) added an OPTIONAL
 * `$date` parameter, additive and fully backward-compatible: when omitted
 * (as every existing caller/test does today), behavior is byte-for-byte
 * unchanged (bookableSlots() - flat, bookable-only, no date scoping).
 * When given, this switches to AppointmentSlotAvailability::slotsForDate()
 * - the full day's schedule (including FULL/CLOSED slots) for one Dubai
 * calendar date, matching docs/api-contracts (Flutter builds a per-date
 * screen, never downloads an unbounded future list).
 */
class GetAppointmentSlotsAction
{
    use BuildsCartResult;

    public function __construct(private readonly AppointmentSlotAvailability $availability = new AppointmentSlotAvailability) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $userUuid, ?string $date = null): array
    {
        $cart = Cart::where('customer_user_id', UuidBinary::toBinary($userUuid))
            ->where('status_id', CartStatuses::id('ACTIVE'))
            ->first();

        if ($cart === null) {
            return $this->notFound('No active cart to check out.');
        }

        if ($date === null) {
            return $this->ok(200, 'Appointment slots retrieved successfully.', ['appointment_slots' => $this->availability->bookableSlots()]);
        }

        $slots = $this->availability->slotsForDate($date);

        if ($slots === null) {
            return $this->unprocessable('The given data was invalid.', ['date' => ['The date must be a real calendar date in Y-m-d format.']]);
        }

        return $this->ok(200, 'Appointment slots retrieved successfully.', ['date' => $date, 'appointment_slots' => $slots]);
    }
}
