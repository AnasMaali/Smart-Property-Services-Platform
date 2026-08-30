<?php

namespace App\Support\Technician;

use Illuminate\Support\Facades\DB;

/**
 * Extracted from App\Actions\Technician\AssignTechnicianToBookingItemAction
 * (BLUE V1 Phase B19) so App\Actions\Admin\Booking\AdminRescheduleBookingAction
 * can reuse the exact same "does this Technician already hold an active,
 * overlapping assignment on a DIFFERENT, non-cancelled Booking?" rule against
 * a candidate appointment period that is not necessarily the Booking's
 * CURRENT slot - the one thing AssignTechnicianToBookingItemAction's own
 * private hasOverlappingAssignment() could not do, since it always resolves
 * the period from the Booking's own `appointment_slot_id`. Never a second,
 * divergent implementation of "overlap."
 */
final class TechnicianOverlapChecker
{
    /**
     * True if $technicianIdBinary already holds another active
     * (released_at is null) assignment - on any Booking other than
     * $excludeBookingIdBinary - whose appointment_slot period overlaps
     * [$slotStartsAt, $slotEndsAt). Cancelled Booking Items and cancelled
     * Bookings are excluded - a cancelled job no longer occupies the
     * Technician's calendar.
     */
    public function hasOverlap(
        string $technicianIdBinary,
        string $excludeBookingIdBinary,
        string $slotStartsAt,
        string $slotEndsAt,
    ): bool {
        return DB::table('technician_assignments')
            ->join('booking_items', 'booking_items.id', '=', 'technician_assignments.booking_item_id')
            ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
            ->join('appointment_slots', 'appointment_slots.id', '=', 'bookings.appointment_slot_id')
            ->join('booking_item_statuses', 'booking_item_statuses.id', '=', 'booking_items.status_id')
            ->join('booking_statuses', 'booking_statuses.id', '=', 'bookings.status_id')
            ->where('technician_assignments.technician_id', $technicianIdBinary)
            ->whereNull('technician_assignments.released_at')
            ->where('bookings.id', '!=', $excludeBookingIdBinary)
            ->where('booking_item_statuses.code', '!=', 'CANCELLED')
            ->where('booking_statuses.code', '!=', 'CANCELLED')
            ->where('appointment_slots.starts_at', '<', $slotEndsAt)
            ->where('appointment_slots.ends_at', '>', $slotStartsAt)
            ->exists();
    }
}
