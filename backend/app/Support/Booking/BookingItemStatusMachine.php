<?php

namespace App\Support\Booking;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one place a `booking_items` row's status_id is ever written after
 * Phase 7A creates it (initial PENDING_ASSIGNMENT, written directly by
 * CreateBookingFromSuccessfulPaymentAction inside its own conversion
 * transaction). Mirrors App\Support\Booking\BookingStatusMachine exactly -
 * see that class for the shared "caller must already hold the row lock,
 * safe no-op instead of throwing, single captured timestamp" conventions.
 *
 * The allowed graph mirrors the seeded booking_item_statuses lifecycle
 * (database/blue_v1_seed.sql "22. BOOKING ITEM STATUSES"), which is
 * identical in shape to the Booking-level graph:
 *
 *   PENDING_ASSIGNMENT -> ASSIGNED -> IN_PROGRESS -> COMPLETED
 *   {PENDING_ASSIGNMENT, ASSIGNED, IN_PROGRESS} -> CANCELLED
 *
 * Deliberately independent of App\Support\Booking\BookingStatusMachine -
 * see App\Actions\Booking\TransitionBookingItemStatusAction for why Phase
 * 7B does not couple Booking Item transitions to the parent Booking's
 * status.
 */
final class BookingItemStatusMachine
{
    public function transitionToAssigned(object $item, Carbon $at): bool
    {
        return $this->transition($item, $at, from: 'PENDING_ASSIGNMENT', to: 'ASSIGNED');
    }

    public function transitionToInProgress(object $item, Carbon $at): bool
    {
        return $this->transition($item, $at, from: 'ASSIGNED', to: 'IN_PROGRESS');
    }

    public function transitionToCompleted(object $item, Carbon $at): bool
    {
        return $this->transition($item, $at, from: 'IN_PROGRESS', to: 'COMPLETED', timestampColumn: 'completed_at');
    }

    public function transitionToCancelled(object $item, Carbon $at): bool
    {
        if (! in_array((int) $item->status_id, [
            BookingItemStatuses::id('PENDING_ASSIGNMENT'),
            BookingItemStatuses::id('ASSIGNED'),
            BookingItemStatuses::id('IN_PROGRESS'),
        ], true)) {
            return false;
        }

        return $this->transition($item, $at, from: null, to: 'CANCELLED', timestampColumn: 'cancelled_at');
    }

    public function isInStatus(object $item, string $code): bool
    {
        return (int) $item->status_id === BookingItemStatuses::id($code);
    }

    private function transition(object $item, Carbon $at, ?string $from, string $to, ?string $timestampColumn = null): bool
    {
        if ($from !== null && (int) $item->status_id !== BookingItemStatuses::id($from)) {
            return false;
        }

        $timestamp = $at->format('Y-m-d H:i:s.u');

        $update = [
            'status_id' => BookingItemStatuses::id($to),
            'status_changed_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        if ($timestampColumn !== null) {
            $update[$timestampColumn] = $timestamp;
        }

        DB::table('booking_items')->where('id', $item->id)->update($update);

        return true;
    }
}
