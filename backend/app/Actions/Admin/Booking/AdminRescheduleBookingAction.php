<?php

namespace App\Actions\Admin\Booking;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Booking\BookingStatuses;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Technician\TechnicianOverlapChecker;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Admin "Reschedule Booking" (BLUE V1 Phase B19) - moves a non-terminal
 * Booking to a different appointment_slot through the SAME capacity/hold
 * model checkout already uses, never a raw `bookings.appointment_slot_id`
 * write.
 *
 * Schema finding this Action relies on: `appointment_holds` is keyed by
 * `cart_id` (never `booking_id`), and a CONVERTED hold can never be
 * released - `chk_appointment_holds_final_state` forbids `released_at` and
 * `converted_at` both being non-null. This is not a gap to work around: it
 * means the ORIGINAL slot's capacity is a permanent historical fact (the
 * exact same behavior App\Actions\Booking\CancelBookingAction already
 * accepts - it never releases the original hold on cancellation either), and
 * a reschedule is recorded as a SECOND, independent converted hold for the
 * SAME `cart_id` at the NEW `appointment_slot_id`. Nothing prevents a cart
 * from holding more than one converted hold over time, so this needs no new
 * table - `appointment_holds` (queried by `cart_id`) plus this Action's own
 * `admin_audit_logs` row already form a complete, reconstructable
 * old-slot/new-slot/actor/reason/timestamp history.
 *
 * Eligibility: CANCELLED/COMPLETED/IN_PROGRESS are rejected outright (an
 * in-progress job's time cannot retroactively move). PAID/ASSIGNED are
 * reschedulable, gated on: the new slot existing, active, in the future,
 * having remaining capacity (reusing the exact
 * App\Support\Checkout\AppointmentSlotAvailability-style occupancy count),
 * and - reusing App\Support\Technician\TechnicianOverlapChecker, never a
 * second overlap implementation - every currently active Technician
 * assignment on this Booking's non-cancelled items not conflicting with the
 * new period on any OTHER Booking. Technicians are never reassigned or
 * released here; a conflict is simply rejected.
 *
 * Never touches payment_attempts, booking_items, Contract
 * entitlement/status, or Booking/Booking-Item lifecycle status.
 */
final class AdminRescheduleBookingAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly TechnicianOverlapChecker $overlapChecker = new TechnicianOverlapChecker,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request, User $actor, string $bookingUuid, string $newSlotUuid, string $reason): array
    {
        try {
            $bookingIdBinary = UuidBinary::toBinary($bookingUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Booking not found.');
        }

        try {
            $newSlotIdBinary = UuidBinary::toBinary($newSlotUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Appointment slot not found.');
        }

        return DB::transaction(function () use ($request, $actor, $bookingIdBinary, $bookingUuid, $newSlotIdBinary, $newSlotUuid, $reason): array {
            $booking = DB::table('bookings')->where('id', $bookingIdBinary)->lockForUpdate()->first();

            if ($booking === null) {
                return $this->notFound('Booking not found.');
            }

            $status = BookingStatuses::code((int) $booking->status_id);

            if (in_array($status, ['CANCELLED', 'COMPLETED'], true)) {
                return $this->conflict('A cancelled or completed Booking cannot be rescheduled.');
            }

            if ($status === 'IN_PROGRESS') {
                return $this->conflict('A Booking already in progress cannot be rescheduled.');
            }

            if ($newSlotIdBinary === $booking->appointment_slot_id) {
                return $this->ok(200, 'Booking is already scheduled for this appointment slot.', [
                    'booking' => ['uuid' => $bookingUuid, 'appointment_slot_uuid' => $newSlotUuid],
                ]);
            }

            $newSlot = DB::table('appointment_slots')
                ->join('appointment_time_windows', 'appointment_time_windows.id', '=', 'appointment_slots.time_window_id')
                ->where('appointment_slots.id', $newSlotIdBinary)
                ->where('appointment_slots.is_active', 1)
                ->where('appointment_time_windows.is_active', 1)
                ->lockForUpdate()
                ->first(['appointment_slots.*']);

            if ($newSlot === null) {
                return $this->notFound('Appointment slot not found.');
            }

            $now = now();

            if (Carbon::parse($newSlot->starts_at)->lessThanOrEqualTo($now)) {
                return $this->unprocessable('This appointment slot has already passed.');
            }

            $occupied = DB::table('appointment_holds')
                ->where('appointment_slot_id', $newSlotIdBinary)
                ->whereNull('released_at')
                ->whereNull('superseded_at')
                ->where(function ($query) use ($now) {
                    $query->whereNotNull('converted_at')->orWhere('expires_at', '>', $now);
                })
                ->lockForUpdate()
                ->count();

            if ($occupied >= (int) $newSlot->booking_capacity) {
                return $this->conflict('This appointment slot is fully booked.');
            }

            $activeTechnicianIds = DB::table('technician_assignments')
                ->join('booking_items', 'booking_items.id', '=', 'technician_assignments.booking_item_id')
                ->join('booking_item_statuses', 'booking_item_statuses.id', '=', 'booking_items.status_id')
                ->where('booking_items.booking_id', $bookingIdBinary)
                ->where('booking_item_statuses.code', '!=', 'CANCELLED')
                ->whereNull('technician_assignments.released_at')
                ->distinct()
                ->pluck('technician_assignments.technician_id');

            foreach ($activeTechnicianIds as $technicianIdBinary) {
                if ($this->overlapChecker->hasOverlap($technicianIdBinary, $bookingIdBinary, $newSlot->starts_at, $newSlot->ends_at)) {
                    return $this->conflict('Rescheduling would double-book an assigned Technician.');
                }
            }

            $timestamp = $now->format('Y-m-d H:i:s.u');
            $ttlMinutes = (int) config('checkout.appointment_hold_ttl_minutes');

            // Frees the OLD slot's capacity. The original hold's own
            // converted_at/released_at are never touched (permanent
            // historical fact - see this class's docblock); superseded_at
            // is the new, distinct signal that this specific commitment no
            // longer occupies its slot because the Booking moved elsewhere.
            DB::table('appointment_holds')
                ->where('cart_id', $booking->cart_id)
                ->where('appointment_slot_id', $booking->appointment_slot_id)
                ->whereNotNull('converted_at')
                ->whereNull('superseded_at')
                ->update(['superseded_at' => $timestamp, 'updated_at' => $timestamp]);

            DB::table('appointment_holds')->insert([
                'id' => UuidBinary::toBinary(UuidBinary::generate()),
                'cart_id' => $booking->cart_id,
                'appointment_slot_id' => $newSlotIdBinary,
                'held_at' => $timestamp,
                'expires_at' => $now->copy()->addMinutes($ttlMinutes)->format('Y-m-d H:i:s.u'),
                'converted_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $oldSlotUuid = UuidBinary::toString($booking->appointment_slot_id);

            DB::table('bookings')->where('id', $bookingIdBinary)->update([
                'appointment_slot_id' => $newSlotIdBinary,
                'updated_at' => $timestamp,
            ]);

            AdminAuditLogger::record(
                request: $request,
                actor: $actor,
                actionCode: 'BOOKING_RESCHEDULED',
                entityType: 'BOOKING',
                entityIdentifier: $bookingUuid,
                oldValues: ['appointment_slot_uuid' => $oldSlotUuid],
                newValues: ['appointment_slot_uuid' => $newSlotUuid, 'reason' => $reason],
            );

            return $this->ok(200, 'Booking rescheduled successfully.', [
                'booking' => ['uuid' => $bookingUuid, 'appointment_slot_uuid' => $newSlotUuid],
            ]);
        });
    }
}
