<?php

namespace App\Support\Checkout;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B27 (Appointment Schedule Management) - the ONE canonical
 * "is this appointment_holds row currently occupying its slot's capacity"
 * predicate, extracted from App\Support\Checkout\AppointmentSlotAvailability,
 * App\Actions\Checkout\CreateAppointmentHoldAction, and
 * App\Actions\Admin\Booking\AdminRescheduleBookingAction, which had each
 * carried a byte-for-byte identical copy of this WHERE clause. Extracting it
 * changes no behavior - every call site's actual query (including its own
 * `->where('appointment_slot_id', ...)`, `lockForUpdate()`, and
 * cart-exclusion clauses) is unchanged, only the shared predicate itself is
 * now defined once.
 *
 * A hold occupies capacity when it is not released, not superseded (BLUE V1
 * Phase B19's reschedule signal - see phase18_appointment_hold_reschedule_
 * schema_migration.sql - and, as of BLUE V1 Phase B27, also written on
 * Booking cancellation, see App\Actions\Booking\CancelBookingAction), and
 * either already converted into a real Booking or still within its TTL.
 */
final class AppointmentSlotOccupancy
{
    /**
     * A query builder over `appointment_holds`, pre-filtered to only
     * currently-occupying rows. Callers add their own `appointment_slot_id`
     * (or `whereIn`), any cart exclusion, and `lockForUpdate()` as needed -
     * this method never calls `->count()` itself, so every existing locking
     * behavior at each call site is preserved exactly.
     */
    public static function query(Carbon $now): Builder
    {
        return DB::table('appointment_holds')
            ->whereNull('released_at')
            ->whereNull('superseded_at')
            ->where(function ($query) use ($now) {
                $query->whereNotNull('converted_at')->orWhere('expires_at', '>', $now);
            });
    }

    /**
     * Bulk occupied-count per slot, for a list of slots at once (never one
     * query per slot) - used by AppointmentSlotAvailability::bookableSlots()
     * and the Admin Appointment Schedule day view.
     *
     * @param  array<int, string>  $slotIdBinaries
     * @return Collection<string, int> keyed by binary appointment_slot_id
     */
    public static function countBySlot(array $slotIdBinaries, Carbon $now): Collection
    {
        if ($slotIdBinaries === []) {
            return collect();
        }

        return self::query($now)
            ->whereIn('appointment_slot_id', $slotIdBinaries)
            ->select('appointment_slot_id', DB::raw('COUNT(*) as occupied'))
            ->groupBy('appointment_slot_id')
            ->pluck('occupied', 'appointment_slot_id');
    }
}
