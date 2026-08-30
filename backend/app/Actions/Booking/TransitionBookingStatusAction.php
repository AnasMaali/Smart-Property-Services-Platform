<?php

namespace App\Actions\Booking;

use App\Support\Booking\BookingLifecycleOutcome;
use App\Support\Booking\BookingLifecycleTransitionResult;
use App\Support\Booking\BookingStatuses;
use App\Support\Booking\BookingStatusMachine;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * The one canonical, server/internal-only entry point for every `bookings`
 * status transition after Phase 7A's initial PAID row (BLUE V1 Phase 7B).
 * Not reachable from any HTTP route - docs/03-features-and-requirements/
 * 08-request-status-tracking.md is explicit that "the admin manages and
 * updates request statuses in Version 1" and "the customer can only view
 * request statuses," and no admin authentication layer exists yet (see
 * Phase 7B implementation report "Staff Assignment Readiness"), so there is
 * currently no safe caller for this outside server-side code (a future
 * admin-panel Action, an Artisan command, or an internal job). Every public
 * method here is one explicit, named business action - never a generic
 * "transition to $anyCode" endpoint - matching the same convention
 * App\Actions\Payment\ProcessPaymentWebhookAction already uses on top of
 * App\Support\Payment\PaymentAttemptStateMachine.
 *
 * Each method: locks the `bookings` row -> resolves its current status by
 * CODE -> asks BookingStatusMachine to attempt the specific transition ->
 * on success, writes exactly one booking_status_history row from the same
 * captured timestamp used for the status write - all inside one
 * transaction. A row already in the requested target status is a safe,
 * idempotent no-op (ALREADY_IN_TARGET_STATE, nothing written) - retrying a
 * transition whose response was lost never duplicates history. A row whose
 * current status does not allow the requested transition is safely
 * rejected (INVALID_TRANSITION, nothing written) - never an exception for
 * this expected case, matching BookingConversionOutcome's convention.
 *
 * Never touches `payment_attempts`, `carts`, or `appointment_holds` -
 * Booking lifecycle is fulfillment state, not financial state (see Phase
 * 7B "Payment Separation"). Never calls PricingEngine and never rewrites
 * `booking_items` pricing snapshot columns.
 *
 * Lock order: this class only ever locks the single `bookings` row it is
 * transitioning - never `payment_attempts`/`carts`/`appointment_holds` (so
 * it cannot conflict with Phase 7A's PAYMENT_ATTEMPT -> CART ->
 * APPOINTMENT_HOLD root) and never `booking_items` (so it cannot conflict
 * with TransitionBookingItemStatusAction, which locks a `booking_items` row
 * directly and independently - see that class).
 */
class TransitionBookingStatusAction
{
    public function __construct(
        private readonly BookingStatusMachine $machine = new BookingStatusMachine,
    ) {}

    public function assign(string $bookingUuid, ?string $reason = null, ?string $actorUserUuid = null): BookingLifecycleTransitionResult
    {
        return $this->run($bookingUuid, 'ASSIGNED', fn ($booking, $at) => $this->machine->transitionToAssigned($booking, $at), $reason, $actorUserUuid);
    }

    public function start(string $bookingUuid, ?string $reason = null, ?string $actorUserUuid = null): BookingLifecycleTransitionResult
    {
        return $this->run($bookingUuid, 'IN_PROGRESS', fn ($booking, $at) => $this->machine->transitionToInProgress($booking, $at), $reason, $actorUserUuid);
    }

    /**
     * @param  string|null  $actorUserUuid  Recorded as booking_status_history.changed_by_user_id. SyncBookingStatusFromItemsAction (the only other caller of any method here) omits this and keeps the pre-existing null; only App\Actions\Admin\Booking\AdminForceCompleteBookingAction passes the acting Admin.
     */
    public function complete(string $bookingUuid, ?string $reason = null, ?string $actorUserUuid = null): BookingLifecycleTransitionResult
    {
        return $this->run($bookingUuid, 'COMPLETED', fn ($booking, $at) => $this->machine->transitionToCompleted($booking, $at), $reason, $actorUserUuid);
    }

    /**
     * Booking cancellation is a fulfillment-state change only - it never
     * touches `payment_attempts` and never triggers a refund (Phase 7B
     * scope; see docs/api-contracts/bookings-v1.md "Booking lifecycle").
     * A cancelled paid Booking may still have a SUCCESSFUL payment until a
     * later, separate refund process exists.
     */
    public function cancel(string $bookingUuid, ?string $reason = null): BookingLifecycleTransitionResult
    {
        return $this->run($bookingUuid, 'CANCELLED', fn ($booking, $at) => $this->machine->transitionToCancelled($booking, $at), $reason);
    }

    private function run(string $bookingUuid, string $targetCode, callable $attempt, ?string $reason, ?string $actorUserUuid = null): BookingLifecycleTransitionResult
    {
        $bookingIdBinary = UuidBinary::toBinary($bookingUuid);
        $actorIdBinary = $actorUserUuid === null ? null : UuidBinary::toBinary($actorUserUuid);

        return DB::transaction(function () use ($bookingIdBinary, $targetCode, $attempt, $reason, $actorIdBinary): BookingLifecycleTransitionResult {
            $booking = DB::table('bookings')->where('id', $bookingIdBinary)->lockForUpdate()->first();

            if ($booking === null) {
                return new BookingLifecycleTransitionResult(BookingLifecycleOutcome::NOT_FOUND);
            }

            $fromCode = BookingStatuses::code((int) $booking->status_id);

            if ($fromCode === $targetCode) {
                return new BookingLifecycleTransitionResult(BookingLifecycleOutcome::ALREADY_IN_TARGET_STATE, $fromCode, $targetCode);
            }

            $now = now();

            if (! $attempt($booking, $now)) {
                return new BookingLifecycleTransitionResult(BookingLifecycleOutcome::INVALID_TRANSITION, $fromCode, $targetCode);
            }

            DB::table('booking_status_history')->insert([
                'id' => UuidBinary::toBinary(UuidBinary::generate()),
                'booking_id' => $bookingIdBinary,
                'from_status_id' => (int) $booking->status_id,
                'to_status_id' => BookingStatuses::id($targetCode),
                'changed_by_user_id' => $actorIdBinary,
                'reason' => $reason,
                'changed_at' => $now->format('Y-m-d H:i:s.u'),
            ]);

            return new BookingLifecycleTransitionResult(BookingLifecycleOutcome::TRANSITIONED, $fromCode, $targetCode);
        });
    }
}
