<?php

namespace App\Support\Admin;

use App\Support\Checkout\AppointmentSlotOccupancy;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B27 (Appointment Schedule Management). Presents a dated
 * `appointment_slots` row for the Admin day-view/slot-detail workspace -
 * `occupied_capacity` reuses App\Support\Checkout\AppointmentSlotOccupancy
 * (the same predicate checkout's own capacity engine uses), never a
 * second/divergent calculation. `status` uses the exact
 * AVAILABLE/FULL/CLOSED vocabulary the customer-facing GET
 * /v1/checkout/appointment-slots?date= endpoint also returns, so an Admin
 * and a customer are never shown a differently-named notion of the same
 * fact.
 *
 * Active-hold visibility is deliberately count/timing only (`held_at`,
 * `expires_at`) - never a customer/cart identity, per BLUE V1's "safe
 * operational information only" policy for temporary checkout holds.
 */
final class AdminAppointmentSchedulePresenter
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function presentDay(Collection $slots, Carbon $now): array
    {
        if ($slots->isEmpty()) {
            return [];
        }

        $slotIds = $slots->pluck('id')->all();
        $occupiedBySlot = AppointmentSlotOccupancy::countBySlot($slotIds, $now);
        $activeHoldCounts = self::activeHoldCountsBySlot($slotIds, $now);

        return $slots->map(fn (object $row) => self::presentSlot(
            $row,
            (int) ($occupiedBySlot[$row->id] ?? 0),
            (int) ($activeHoldCounts[$row->id] ?? 0),
        ))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentSlot(object $row, int $occupied, int $activeHoldCount): array
    {
        $capacity = (int) $row->booking_capacity;
        $remaining = max($capacity - $occupied, 0);
        $isActive = (bool) $row->is_active;

        $status = match (true) {
            ! $isActive => 'CLOSED',
            $remaining <= 0 => 'FULL',
            default => 'AVAILABLE',
        };

        return [
            'uuid' => UuidBinary::toString($row->id),
            'starts_at' => Carbon::parse($row->starts_at)->toIso8601String(),
            'ends_at' => Carbon::parse($row->ends_at)->toIso8601String(),
            'booking_capacity' => $capacity,
            'occupied_capacity' => $occupied,
            'remaining_capacity' => $remaining,
            'is_active' => $isActive,
            'availability_status' => $status,
            'active_hold_count' => $activeHoldCount,
            'internal_note' => $row->internal_note,
            'time_window' => [
                'id' => (int) $row->time_window_id,
                'code' => $row->window_code,
                'name' => $row->window_name,
            ],
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse($row->updated_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentSlotDetail(object $row, int $occupied): array
    {
        $slotIdBinary = $row->id;
        $activeHolds = self::activeHoldRows($slotIdBinary, now());

        return self::presentSlot($row, $occupied, count($activeHolds)) + [
            'bookings' => self::bookingsForSlot($slotIdBinary),
            'active_holds' => $activeHolds,
        ];
    }

    /**
     * @param  array<int, string>  $slotIdBinaries
     * @return Collection<string, int>
     */
    private static function activeHoldCountsBySlot(array $slotIdBinaries, Carbon $now): Collection
    {
        if ($slotIdBinaries === []) {
            return collect();
        }

        return DB::table('appointment_holds')
            ->whereIn('appointment_slot_id', $slotIdBinaries)
            ->whereNull('released_at')
            ->whereNull('superseded_at')
            ->whereNull('converted_at')
            ->where('expires_at', '>', $now)
            ->select('appointment_slot_id', DB::raw('COUNT(*) as active_count'))
            ->groupBy('appointment_slot_id')
            ->pluck('active_count', 'appointment_slot_id');
    }

    /**
     * Safe (non-identifying) rows for the temporary holds currently open on
     * one slot - never a customer/cart identity.
     *
     * @return array<int, array{held_at: string, expires_at: string}>
     */
    private static function activeHoldRows(string $slotIdBinary, Carbon $now): array
    {
        return DB::table('appointment_holds')
            ->where('appointment_slot_id', $slotIdBinary)
            ->whereNull('released_at')
            ->whereNull('superseded_at')
            ->whereNull('converted_at')
            ->where('expires_at', '>', $now)
            ->orderBy('held_at')
            ->get(['held_at', 'expires_at'])
            ->map(fn (object $hold) => [
                'held_at' => Carbon::parse($hold->held_at)->toIso8601String(),
                'expires_at' => Carbon::parse($hold->expires_at)->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Bookings actually occupying this slot - safe fields only, reusing
     * App\Support\Admin\AdminBookingPresenter::presentList() unchanged
     * (never a second Booking presentation shape), with the same
     * `carts.customer_user_id`/`carts.currency_id` join that Action
     * already requires.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function bookingsForSlot(string $slotIdBinary): array
    {
        $rows = DB::table('bookings')
            ->join('carts', 'carts.id', '=', 'bookings.cart_id')
            ->where('bookings.appointment_slot_id', $slotIdBinary)
            ->orderByDesc('bookings.created_at')
            ->get(['bookings.*', 'carts.customer_user_id', 'carts.currency_id as cart_currency_id']);

        return AdminBookingPresenter::presentList($rows);
    }
}
