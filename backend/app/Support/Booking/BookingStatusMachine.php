<?php

namespace App\Support\Booking;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one place a `bookings` row's status_id is ever written after Phase 7A
 * creates it (App\Actions\Booking\CreateBookingFromSuccessfulPaymentAction
 * writes the initial PAID row directly, since that happens inside its own
 * unrelated conversion transaction - every transition *after* that goes
 * through here). Mirrors App\Support\Payment\PaymentAttemptStateMachine
 * exactly: every method requires the caller to have already locked the row
 * (SELECT ... FOR UPDATE), is a safe no-op - never a throw - when the row is
 * not currently in the required starting status, and every write uses a
 * datetime(6)-safe pre-formatted timestamp ($at->format('Y-m-d H:i:s.u')).
 *
 * The allowed graph mirrors the seeded booking_statuses lifecycle
 * (database/blue_v1_seed.sql "22. BOOKING STATUSES") and
 * docs/03-features-and-requirements/08-request-status-tracking.md:
 *
 *   PAID -> ASSIGNED -> IN_PROGRESS -> COMPLETED
 *   {PAID, ASSIGNED, IN_PROGRESS} -> CANCELLED
 *
 * No other transition is structurally possible. COMPLETED and CANCELLED are
 * both is_terminal=1 in the seed data, so neither method here ever accepts
 * a row already in either state as a starting point.
 */
final class BookingStatusMachine
{
    public function transitionToAssigned(object $booking, Carbon $at): bool
    {
        return $this->transition($booking, $at, from: 'PAID', to: 'ASSIGNED');
    }

    public function transitionToInProgress(object $booking, Carbon $at): bool
    {
        return $this->transition($booking, $at, from: 'ASSIGNED', to: 'IN_PROGRESS');
    }

    /**
     * Sets completed_at from the same captured $now used for
     * status_changed_at - never two different timestamps for the same
     * transition.
     */
    public function transitionToCompleted(object $booking, Carbon $at): bool
    {
        return $this->transition($booking, $at, from: 'IN_PROGRESS', to: 'COMPLETED', timestampColumn: 'completed_at');
    }

    /**
     * Sets cancelled_at from the same captured $now used for
     * status_changed_at. Reachable from any non-terminal Booking status -
     * never from COMPLETED (chk_bookings_single_final_state also protects
     * this at the schema level: completed_at and cancelled_at can never
     * both be set).
     */
    public function transitionToCancelled(object $booking, Carbon $at): bool
    {
        if (! in_array((int) $booking->status_id, [
            BookingStatuses::id('PAID'),
            BookingStatuses::id('ASSIGNED'),
            BookingStatuses::id('IN_PROGRESS'),
        ], true)) {
            return false;
        }

        return $this->transition($booking, $at, from: null, to: 'CANCELLED', timestampColumn: 'cancelled_at');
    }

    public function isInStatus(object $booking, string $code): bool
    {
        return (int) $booking->status_id === BookingStatuses::id($code);
    }

    private function transition(object $booking, Carbon $at, ?string $from, string $to, ?string $timestampColumn = null): bool
    {
        if ($from !== null && (int) $booking->status_id !== BookingStatuses::id($from)) {
            return false;
        }

        $timestamp = $at->format('Y-m-d H:i:s.u');

        $update = [
            'status_id' => BookingStatuses::id($to),
            'status_changed_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        if ($timestampColumn !== null) {
            $update[$timestampColumn] = $timestamp;
        }

        DB::table('bookings')->where('id', $booking->id)->update($update);

        return true;
    }
}
