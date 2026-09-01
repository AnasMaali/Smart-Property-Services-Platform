<?php

namespace App\Actions\Booking;

use App\Actions\Payment\ExecuteBookingRefundAction;
use App\Support\Booking\BookingItemStatuses;
use App\Support\Booking\BookingItemStatusMachine;
use App\Support\Booking\BookingRefundStatuses;
use App\Support\Booking\BookingStatuses;
use App\Support\Booking\BookingStatusMachine;
use App\Support\Booking\RefundEligibilityCalculator;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class CancelBookingAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly ExecuteBookingRefundAction $executeBookingRefundAction,
        private readonly BookingStatusMachine $bookingMachine = new BookingStatusMachine,
        private readonly BookingItemStatusMachine $itemMachine = new BookingItemStatusMachine,
    ) {}

    /**
     * Shared cancellation cascade for both the customer self-service
     * cancel endpoint and the Admin "Cancel Booking" operation
     * (App\Actions\Admin\Booking\AdminCancelBookingAction) - never
     * duplicated between the two callers, and the ONE place BLUE V1's
     * cancellation/refund policy (App\Support\Booking\
     * RefundEligibilityCalculator) is ever evaluated or acted on.
     *
     * This action:
     * - optionally verifies Booking ownership (customer flow only -
     *   $requireOwnerUuid; the Admin caller passes null, since an Admin may
     *   cancel any customer's Booking)
     * - for a STANDARD (payment-backed) Booking, rejects cancellation
     *   outright - with NO mutation of any kind - once the appointment has
     *   started (BLUE V1's cancellation policy: at/after `starts_at`,
     *   cancellation is never allowed, for Customer or Admin alike)
     * - cancels the parent Booking
     * - cancels every non-terminal Booking Item
     * - releases active Technician assignments
     * - (BLUE V1 Phase B27) supersedes this Booking's converted
     *   `appointment_holds` row, so its slot's occupied capacity count
     *   drops by one - `converted_at` is never touched (a permanent
     *   historical record of when this Booking originally occupied the
     *   slot); only `superseded_at` is set, mirroring the exact signal
     *   App\Actions\Admin\Booking\AdminRescheduleBookingAction already
     *   uses to free a slot a Booking moved away from. A standalone refund
     *   with no Booking cancellation never reaches this code and never
     *   releases capacity on its own
     * - calculates the refund policy result exactly ONCE, at the first
     *   real cancellation, and persists it as a historical snapshot
     *   (`bookings.cancellation_refund_percentage` /
     *   `cancellation_refund_amount`) - a retry reads that snapshot back
     *   rather than recalculating it, so a later change to
     *   `config('cancellation.*')` can never retroactively change what an
     *   already-cancelled Booking is shown to owe
     * - for a STANDARD Booking, atomically persists exactly one
     *   `booking_refunds` EXECUTION obligation (PENDING) in the SAME
     *   transaction as the cancellation itself - see BookingRefundStatuses
     *   and database/phase19_booking_refund_automation_migration.sql for
     *   why this is a separate table from the frozen policy snapshot above
     *
     * A DB transaction and a Stripe API call can never be one atomic unit,
     * so this method NEVER calls Stripe from inside its transaction. Once
     * the transaction above commits, it makes ONE best-effort, synchronous
     * attempt to execute the newly-created (or still-PENDING, on an
     * idempotent replay) refund obligation via
     * App\Actions\Payment\ExecuteBookingRefundAction - deliberately
     * OUTSIDE the transaction, so a Stripe-side failure can never roll
     * back or otherwise affect the Booking cancellation that already
     * safely committed. Any exception from that attempt is reported and
     * swallowed - the cancellation response is unaffected, and the
     * obligation remains safely PENDING and recoverable via
     * `php artisan bookings:execute-pending-refunds` (see
     * App\Console\Commands\ExecutePendingBookingRefunds).
     *
     * It NEVER changes `payment_attempts` - the original successful
     * payment record is never rewritten as if it had not happened.
     *
     * $onRealCancellation, when given, is invoked inside the DB
     * transaction ONLY on a genuine PAID/ASSIGNED/IN_PROGRESS ->
     * CANCELLED transition (never on an idempotent replay of an
     * already-CANCELLED Booking, and never when cancellation is rejected)
     * - this is how AdminCancelBookingAction writes its Admin audit event
     * atomically with the state change, without this shared Action taking
     * any dependency on Admin-only infrastructure itself.
     *
     * @param  string  $actorUserUuid  Recorded as changed_by_user_id / released_by_user_id / booking_refunds.initiated_by_user_id - the customer themselves, or the acting Admin.
     * @param  string|null  $requireOwnerUuid  When given, the Booking is 404 unless its Cart belongs to this Customer. Null skips the check (Admin flow).
     * @param  string  $initiatedAs  'CUSTOMER' or 'ADMIN' - recorded on the refund obligation, server-derived from which caller invoked this Action, never client input.
     * @return array<string, mixed>
     */
    public function handle(
        string $actorUserUuid,
        string $bookingUuid,
        ?string $requireOwnerUuid = null,
        string $reason = 'Customer cancelled booking.',
        string $initiatedAs = 'CUSTOMER',
        ?Closure $onRealCancellation = null,
    ): array {
        try {
            $bookingIdBinary = UuidBinary::toBinary($bookingUuid);
            $actorIdBinary = UuidBinary::toBinary($actorUserUuid);
            $requireOwnerIdBinary = $requireOwnerUuid === null ? null : UuidBinary::toBinary($requireOwnerUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Booking not found.');
        }

        $refundObligationUuid = null;

        $result = DB::transaction(function () use (
            $bookingIdBinary,
            $actorIdBinary,
            $requireOwnerIdBinary,
            $bookingUuid,
            $reason,
            $initiatedAs,
            $onRealCancellation,
            &$refundObligationUuid,
        ): array {
            /*
             * Root lock first.
             */
            $booking = DB::table('bookings')
                ->where('id', $bookingIdBinary)
                ->lockForUpdate()
                ->first();

            if ($booking === null) {
                return $this->notFound('Booking not found.');
            }

            /*
             * Same ownership rule as GetBookingAction:
             *
             * Booking -> Cart -> customer_user_id
             *
             * Foreign and unknown Bookings are intentionally both 404.
             * Skipped entirely for the Admin flow ($requireOwnerIdBinary
             * === null) - an Admin may cancel any customer's Booking.
             */
            if ($requireOwnerIdBinary !== null) {
                $ownsBooking = DB::table('carts')
                    ->where('id', $booking->cart_id)
                    ->where('customer_user_id', $requireOwnerIdBinary)
                    ->exists();

                if (! $ownsBooking) {
                    return $this->notFound('Booking not found.');
                }
            }

            $currentStatus = BookingStatuses::code((int) $booking->status_id);

            /*
             * COMPLETED is terminal and cannot be cancelled.
             */
            if ($currentStatus === 'COMPLETED') {
                return $this->conflict(
                    'A completed Booking cannot be cancelled.'
                );
            }

            /*
             * Only the states already supported by BookingStatusMachine
             * are cancellable.
             */
            if (! in_array(
                $currentStatus,
                ['PAID', 'ASSIGNED', 'IN_PROGRESS', 'CANCELLED'],
                true
            )) {
                return $this->conflict(
                    'This Booking cannot be cancelled from its current status.'
                );
            }

            /*
             * Lock all Booking Items in deterministic order.
             */
            $items = DB::table('booking_items')
                ->where('booking_id', $bookingIdBinary)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            /*
             * Lock every currently-active Technician assignment belonging
             * to this Booking after the Booking Items are locked.
             */
            $itemIds = $items->pluck('id')->all();

            $activeAssignments = collect();

            if ($itemIds !== []) {
                $activeAssignments = DB::table('technician_assignments')
                    ->whereIn('booking_item_id', $itemIds)
                    ->whereNull('released_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }

            /*
             * A CONTRACT-sourced Booking (BLUE V1 Phase 10F) has no
             * payment_attempt_id at all - it was never paid for directly,
             * so there is nothing to refund. Cancelling it only needs to
             * move the Booking/Booking Item/assignment state; the
             * refund-eligibility snapshot below is skipped entirely and
             * stays permanently NULL, which chk_bookings_cancellation_refund_data
             * already allows. The entitlement this Booking consumed is
             * automatically and permanently freed the moment its status
             * stops being counted as "used" - see
             * App\Support\Contract\ContractEntitlementCalculator's docblock
             * - with no extra write required here. BLUE V1 Phase B20's
             * appointment-started cancellation restriction and automatic
             * Stripe refund therefore never apply to a Contract Booking
             * either - its cancellation behavior is entirely unchanged.
             */
            $isContractBooking = $booking->service_contract_id !== null;

            /*
             * Payment and appointment are read only (STANDARD Bookings
             * only).
             *
             * confirmed_amount is the ONLY financial source of truth for an
             * automated refund - see the confirmed_amount-not-null gate
             * below, evaluated only for a genuinely new cancellation.
             */
            $payment = null;
            $slot = null;

            if (! $isContractBooking) {
                $payment = DB::table('payment_attempts')
                    ->where('id', $booking->payment_attempt_id)
                    ->first([
                        'confirmed_amount',
                        'currency_id',
                    ]);

                $slot = DB::table('appointment_slots')
                    ->where('id', $booking->appointment_slot_id)
                    ->first([
                        'starts_at',
                    ]);

                if ($payment === null || $slot === null) {
                    return $this->conflict(
                        'Booking cancellation data is incomplete.'
                    );
                }
            }

            $now = now();

            /*
             * Idempotency:
             *
             * If this Booking had already been cancelled, calculate the
             * refund from its ORIGINAL cancelled_at timestamp, never from
             * the retry time.
             */
            $effectiveCancellationAt = $currentStatus === 'CANCELLED'
                ? $booking->cancelled_at
                : $now;

            /*
             * Cancellation-policy gate for a genuinely NEW cancellation of
             * a STANDARD Booking - evaluated and enforced BEFORE any
             * mutation below, so a rejected cancellation (appointment
             * already started) leaves the Booking, its Items, and its
             * Technician assignments completely untouched. An idempotent
             * replay of an already-CANCELLED Booking never re-evaluates
             * this - it already happened at the moment of first
             * cancellation and must not be re-derived from "now".
             */
            $refundEvaluation = null;

            if (! $isContractBooking && $currentStatus !== 'CANCELLED') {
                // BLUE V1 Phase B20 fix - payment_attempts.confirmed_amount
                // is the ONLY financial source of truth for an automated
                // refund. A STANDARD Booking only ever exists from a payment
                // that already reached SUCCESSFUL (see
                // CreateBookingFromSuccessfulPaymentAction), so
                // confirmed_amount is always expected here; a Booking whose
                // payment somehow lacks it is a reconciliation failure, not
                // a case to guess an automated refund amount from
                // requested_amount. Rejecting here leaves the Booking, its
                // Items, and its Technician assignments completely
                // untouched, exactly like the appointment-started rejection
                // below.
                if ($payment->confirmed_amount === null) {
                    return $this->conflict(
                        'This Booking\'s payment is missing its confirmed amount - cancellation cannot proceed automatically.'
                    );
                }

                $paidAmountForEvaluation = (string) $payment->confirmed_amount;

                $currencyMinorUnit = (int) DB::table('currencies')
                    ->where('id', $payment->currency_id)
                    ->value('minor_unit');

                $refundEvaluation = RefundEligibilityCalculator::evaluate(
                    (string) $slot->starts_at,
                    (string) $now,
                    $paidAmountForEvaluation,
                    $currencyMinorUnit
                );

                if (! $refundEvaluation['cancellable']) {
                    return $this->conflict(
                        'This Booking cannot be cancelled because its appointment has already started.'
                    );
                }
            }

            /*
             * Cancel the parent only on the first real cancellation.
             */
            if ($currentStatus !== 'CANCELLED') {
                if (! $this->bookingMachine->transitionToCancelled($booking, $now)) {
                    return $this->conflict(
                        'This Booking cannot be cancelled from its current status.'
                    );
                }

                DB::table('booking_status_history')->insert([
                    'id' => UuidBinary::toBinary(UuidBinary::generate()),
                    'booking_id' => $bookingIdBinary,
                    'from_status_id' => (int) $booking->status_id,
                    'to_status_id' => BookingStatuses::id('CANCELLED'),
                    'changed_by_user_id' => $actorIdBinary,
                    'reason' => $reason,
                    'changed_at' => $now->format('Y-m-d H:i:s.u'),
                ]);

                // BLUE V1 Phase B27 - retire this Booking's converted
                // appointment_holds row from its slot's occupied-capacity
                // count, using the exact same superseded_at signal
                // App\Actions\Admin\Booking\AdminRescheduleBookingAction
                // already uses to free a slot a Booking moved away from
                // (see that Action's docblock and
                // phase18_appointment_hold_reschedule_schema_migration.sql).
                // converted_at is never touched - the hold remains a
                // permanent, untouched historical record of when this
                // Booking originally occupied this slot; only the "is this
                // still occupying capacity" fact changes. Guarded by
                // whereNull('superseded_at') so a retried cancellation (an
                // idempotent replay never reaches this branch at all, since
                // it's inside `if ($currentStatus !== 'CANCELLED')`) can
                // never double-write or error. A refund alone - with no
                // Booking cancellation - never reaches this code path, so
                // capacity is never released by money movement alone (see
                // App\Actions\Payment\ExecuteBookingRefundAction, which has
                // no appointment_holds reference at all).
                DB::table('appointment_holds')
                    ->where('cart_id', $booking->cart_id)
                    ->where('appointment_slot_id', $booking->appointment_slot_id)
                    ->whereNotNull('converted_at')
                    ->whereNull('superseded_at')
                    ->update(['superseded_at' => $now->format('Y-m-d H:i:s.u'), 'updated_at' => $now->format('Y-m-d H:i:s.u')]);

                // BLUE V1 Phase B24 - a PAY_ON_SITE Booking whose cash was
                // ALREADY collected before this cancellation is never given
                // an automated refund (physical cash cannot be
                // electronically returned) - flag it honestly instead of
                // silently implying nothing is owed back. An uncollected
                // settlement (collected_at still NULL) needs no flag: there
                // is nothing to refund.
                DB::table('booking_on_site_settlements')
                    ->where('booking_id', $bookingIdBinary)
                    ->whereNotNull('collected_at')
                    ->whereNull('refund_status')
                    ->update(['refund_status' => 'MANUAL_REFUND_REQUIRED', 'updated_at' => $now->format('Y-m-d H:i:s.u')]);
            }

            /*
             * Cancel every Booking Item that has not already reached a
             * terminal state.
             *
             * COMPLETED stays COMPLETED.
             * CANCELLED stays CANCELLED.
             */
            foreach ($items as $item) {
                $itemStatus = BookingItemStatuses::code(
                    (int) $item->status_id
                );

                if (in_array($itemStatus, ['COMPLETED', 'CANCELLED'], true)) {
                    continue;
                }

                if ($this->itemMachine->transitionToCancelled($item, $now)) {
                    DB::table('booking_item_status_history')->insert([
                        'id' => UuidBinary::toBinary(UuidBinary::generate()),
                        'booking_item_id' => $item->id,
                        'from_status_id' => (int) $item->status_id,
                        'to_status_id' => BookingItemStatuses::id('CANCELLED'),
                        'changed_by_user_id' => $actorIdBinary,
                        'reason' => $reason,
                        'changed_at' => $now->format('Y-m-d H:i:s.u'),
                    ]);
                }
            }

            /*
             * Release every active Technician assignment.
             *
             * The schema requires released_at, released_by_user_id and
             * release_reason to be populated together.
             */
            $releaseTimestamp = $now->format('Y-m-d H:i:s.u');

            foreach ($activeAssignments as $assignment) {
                DB::table('technician_assignments')
                    ->where('id', $assignment->id)
                    ->whereNull('released_at')
                    ->update([
                        'released_at' => $releaseTimestamp,
                        'released_by_user_id' => $actorIdBinary,
                        'release_reason' => $reason,
                        'updated_at' => $releaseTimestamp,
                    ]);
            }

            $storageTimezone = (string) config(
                'app.timezone',
                'UTC'
            );

            /*
             * Refund eligibility is calculated exactly ONCE, at the first
             * real cancellation, then persisted as a historical snapshot
             * (`bookings.cancellation_refund_percentage` /
             * `cancellation_refund_amount`). A later change to
             * `config('cancellation.*')` must never change what an
             * already-cancelled Booking is shown to owe - so a retry NEVER
             * recalculates; it only reads the snapshot back. The
             * Customer/Admin Booking read APIs (BookingPresenter /
             * AdminBookingPresenter) read this same persisted snapshot,
             * never App\Support\Booking\RefundEligibilityCalculator
             * directly.
             */
            if ($isContractBooking) {
                $refund = null;
            } elseif ($currentStatus === 'CANCELLED') {
                $refund = [
                    'percentage' => (int) $booking->cancellation_refund_percentage,
                    'amount' => (string) $booking->cancellation_refund_amount,
                    'execution' => 'AUTOMATIC',
                ];

                /*
                 * Idempotent replay: if a refund obligation already exists
                 * for this Booking and is still PENDING, hand it back to
                 * the caller for another best-effort post-commit attempt -
                 * never created twice (uq_booking_refunds_booking), never
                 * re-evaluated.
                 */
                $existingObligation = DB::table('booking_refunds')
                    ->where('booking_id', $bookingIdBinary)
                    ->first(['id', 'status_id']);

                if ($existingObligation !== null && (int) $existingObligation->status_id === BookingRefundStatuses::id('PENDING')) {
                    $refundObligationUuid = UuidBinary::toString($existingObligation->id);
                }
            } else {
                // $refundEvaluation is guaranteed cancellable === true here
                // - the gate above already rejected and returned early
                // otherwise.
                DB::table('bookings')
                    ->where('id', $bookingIdBinary)
                    ->update([
                        'cancellation_refund_percentage' => $refundEvaluation['percentage'],
                        'cancellation_refund_amount' => $refundEvaluation['amount'],
                        'updated_at' => $now->format('Y-m-d H:i:s.u'),
                    ]);

                $refund = [
                    'percentage' => $refundEvaluation['percentage'],
                    'amount' => $refundEvaluation['amount'],
                    'execution' => 'AUTOMATIC',
                ];

                $refundIdBinary = UuidBinary::toBinary(UuidBinary::generate());
                $refundUuid = UuidBinary::toString($refundIdBinary);
                $idempotencyKey = 'blue_refund_'.$refundUuid;

                DB::table('booking_refunds')->insert([
                    'id' => $refundIdBinary,
                    'booking_id' => $bookingIdBinary,
                    'payment_attempt_id' => $booking->payment_attempt_id,
                    'currency_id' => $payment->currency_id,
                    'status_id' => BookingRefundStatuses::id('PENDING'),
                    'policy_percentage' => $refundEvaluation['percentage'],
                    'requested_amount' => $refundEvaluation['amount'],
                    'provider_code' => $this->executeBookingRefundAction->providerCode(),
                    'idempotency_key' => $idempotencyKey,
                    'initiated_by_user_id' => $actorIdBinary,
                    'initiated_as' => $initiatedAs,
                    'reason' => $reason,
                    'requested_at' => $now->format('Y-m-d H:i:s.u'),
                    'created_at' => $now->format('Y-m-d H:i:s.u'),
                    'updated_at' => $now->format('Y-m-d H:i:s.u'),
                ]);

                $refundObligationUuid = $refundUuid;
            }

            if ($currentStatus !== 'CANCELLED') {
                $onRealCancellation?->__invoke();
            }

            return $this->ok(
                200,
                $currentStatus === 'CANCELLED'
                    ? 'Booking was already cancelled.'
                    : 'Booking cancelled successfully.',
                [
                    'booking' => [
                        'uuid' => $bookingUuid,
                        'status' => 'CANCELLED',
                        'cancelled_at' => CarbonImmutable::parse(
                            (string) $effectiveCancellationAt,
                            $storageTimezone
                        )->toISOString(),
                    ],
                    'refund_due' => $refund,
                ]
            );
        });

        if ($refundObligationUuid !== null) {
            try {
                $this->executeBookingRefundAction->handle($refundObligationUuid);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $result;
    }
}
