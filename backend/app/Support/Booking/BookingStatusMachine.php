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
 *   {PAID, CONFIRMED} -> ASSIGNED -> IN_PROGRESS -> COMPLETED
 *   {PAID, CONFIRMED, ASSIGNED, IN_PROGRESS} -> CANCELLED
 *
 * BLUE V1 Phase B24 adds CONFIRMED (a Booking accepted/scheduled for
 * on-site cash payment - see App\Actions\Booking\
 * CreatePayOnSiteBookingAction) as a second valid entry status alongside
 * PAID, treated identically for every downstream operational transition -
 * technician assignment/fulfillment/cancellation eligibility never cares
 * WHETHER a Booking is prepaid or pay-on-site, only that it is confirmed.
 * Never PAID -> CONFIRMED or CONFIRMED -> PAID: the two are permanently
 * distinct historical facts about how the Booking was created (see
 * `bookings.payment_method_code`), not a lifecycle stage a Booking moves
 * through.
 *
 * No other transition is structurally possible. COMPLETED and CANCELLED are
 * both is_terminal=1 in the seed data, so neither method here ever accepts
 * a row already in either state as a starting point.
 */
final class BookingStatusMachine
{
    public function transitionToAssigned(object $booking, Carbon $at): bool
    {
        return $this->transition($booking, $at, from: ['PAID', 'CONFIRMED'], to: 'ASSIGNED');
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
            BookingStatuses::id('CONFIRMED'),
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

    /**
     * @param  string|array<int, string>|null  $from
     */
    private function transition(object $booking, Carbon $at, string|array|null $from, string $to, ?string $timestampColumn = null): bool
    {
        if ($from !== null) {
            $allowedIds = array_map(BookingStatuses::id(...), is_array($from) ? $from : [$from]);

            if (! in_array((int) $booking->status_id, $allowedIds, true)) {
                return false;
            }
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
