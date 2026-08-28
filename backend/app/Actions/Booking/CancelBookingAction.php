<?php

namespace App\Actions\Booking;

use App\Support\Booking\BookingItemStatuses;
use App\Support\Booking\BookingItemStatusMachine;
use App\Support\Booking\BookingStatuses;
use App\Support\Booking\BookingStatusMachine;
use App\Support\Booking\RefundEligibilityCalculator;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CancelBookingAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly BookingStatusMachine $bookingMachine = new BookingStatusMachine,
        private readonly BookingItemStatusMachine $itemMachine = new BookingItemStatusMachine,
    ) {}

    /**
     * Shared cancellation cascade for both the customer self-service
     * cancel endpoint and the Admin "Cancel Booking" operation
     * (App\Actions\Admin\Booking\AdminCancelBookingAction) - never
     * duplicated between the two callers.
     *
     * This action:
     * - optionally verifies Booking ownership (customer flow only -
     *   $requireOwnerUuid; the Admin caller passes null, since an Admin may
     *   cancel any customer's Booking)
     * - cancels the parent Booking
     * - cancels every non-terminal Booking Item
     * - releases active Technician assignments
     * - calculates the amount due for manual refund exactly ONCE, at the
     *   first real cancellation, and persists it as a historical snapshot
     *   (`bookings.cancellation_refund_percentage` /
     *   `cancellation_refund_amount`) - a retry reads that snapshot back
     *   rather than recalculating it, so a later change to
     *   `config('cancellation.*')` can never retroactively change what an
     *   already-cancelled Booking is shown to owe
     *
     * It NEVER calls Stripe and NEVER changes payment_attempts status.
     *
     * $onRealCancellation, when given, is invoked inside this same
     * transaction ONLY on a genuine PAID/ASSIGNED/IN_PROGRESS -> CANCELLED
     * transition (never on an idempotent replay of an already-CANCELLED
     * Booking) - this is how AdminCancelBookingAction writes its Admin
     * audit event atomically with the state change, without this shared
     * Action taking any dependency on Admin-only infrastructure itself.
     *
     * @param  string  $actorUserUuid  Recorded as changed_by_user_id / released_by_user_id - the customer themselves, or the acting Admin.
     * @param  string|null  $requireOwnerUuid  When given, the Booking is 404 unless its Cart belongs to this Customer. Null skips the check (Admin flow).
     * @return array<string, mixed>
     */
    public function handle(
        string $actorUserUuid,
        string $bookingUuid,
        ?string $requireOwnerUuid = null,
        string $reason = 'Customer cancelled booking.',
        ?Closure $onRealCancellation = null,
    ): array {
        try {
            $bookingIdBinary = UuidBinary::toBinary($bookingUuid);
            $actorIdBinary = UuidBinary::toBinary($actorUserUuid);
            $requireOwnerIdBinary = $requireOwnerUuid === null ? null : UuidBinary::toBinary($requireOwnerUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Booking not found.');
        }

        return DB::transaction(function () use (
            $bookingIdBinary,
            $actorIdBinary,
            $requireOwnerIdBinary,
            $bookingUuid,
            $reason,
            $onRealCancellation,
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
             * - with no extra write required here.
             */
            $isContractBooking = $booking->service_contract_id !== null;

            /*
             * Payment and appointment are read only (STANDARD Bookings
             * only).
             *
             * confirmed_amount is authoritative after successful payment.
             * requested_amount is only a defensive fallback.
             */
            $payment = null;
            $slot = null;

            if (! $isContractBooking) {
                $payment = DB::table('payment_attempts')
                    ->where('id', $booking->payment_attempt_id)
                    ->first([
                        'confirmed_amount',
                        'requested_amount',
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
                    'execution' => 'MANUAL',
                ];
            } else {
                $paidAmount = (string) (
                    $payment->confirmed_amount
                    ?? $payment->requested_amount
                );

                $refund = RefundEligibilityCalculator::calculate(
                    (string) $slot->starts_at,
                    (string) $effectiveCancellationAt,
                    $paidAmount
                );

                DB::table('bookings')
                    ->where('id', $bookingIdBinary)
                    ->update([
                        'cancellation_refund_percentage' => $refund['percentage'],
                        'cancellation_refund_amount' => $refund['amount'],
                        'updated_at' => $now->format('Y-m-d H:i:s.u'),
                    ]);
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
    }
}
