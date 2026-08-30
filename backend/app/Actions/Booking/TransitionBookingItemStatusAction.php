<?php

namespace App\Actions\Booking;

use App\Support\Booking\BookingItemStatuses;
use App\Support\Booking\BookingItemStatusMachine;
use App\Support\Booking\BookingLifecycleOutcome;
use App\Support\Booking\BookingLifecycleTransitionResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * The one canonical, server/internal-only entry point for every
 * `booking_items` status transition after Phase 7A's initial
 * PENDING_ASSIGNMENT row (BLUE V1 Phase 7B). Mirrors
 * App\Actions\Booking\TransitionBookingStatusAction exactly - see that
 * class for the shared "no HTTP route, explicit named methods, idempotent
 * no-op vs. safe rejection" conventions, which apply identically here.
 *
 * Deliberately independent of the parent Booking's own status:
 * docs/03-features-and-requirements/08-request-status-tracking.md documents
 * a Booking Item lifecycle ("if the booking contains multiple services,
 * each service may have its own status") without specifying that the
 * system must automatically derive or block one from the other - so this
 * phase does not invent that coupling. Concretely: transitioning a Booking
 * Item never reads or locks the parent `bookings` row, and
 * TransitionBookingStatusAction never reads or locks any `booking_items`
 * row. A later phase (once technician assignment and admin tooling exist to
 * drive both levels together) can decide whether/how to reconcile them -
 * see the Phase 7B implementation report "Booking Item State" for the full
 * reasoning.
 *
 * Lock order: locks only the single `booking_items` row being transitioned
 * - never `bookings`, `payment_attempts`, `carts`, or `appointment_holds`.
 */
class TransitionBookingItemStatusAction
{
    public function __construct(
        private readonly BookingItemStatusMachine $machine = new BookingItemStatusMachine,
    ) {}

    public function assign(string $bookingItemUuid, ?string $reason = null): BookingLifecycleTransitionResult
    {
        return $this->run($bookingItemUuid, 'ASSIGNED', fn ($item, $at) => $this->machine->transitionToAssigned($item, $at), $reason);
    }

    public function start(string $bookingItemUuid, ?string $reason = null): BookingLifecycleTransitionResult
    {
        return $this->run($bookingItemUuid, 'IN_PROGRESS', fn ($item, $at) => $this->machine->transitionToInProgress($item, $at), $reason);
    }

    /**
     * @param  string|null  $actorUserUuid  Recorded as booking_item_status_history.changed_by_user_id. Every existing caller (technician job Actions, SyncBookingStatusFromItemsAction) omits this and keeps the pre-existing null - only App\Actions\Admin\Booking\AdminForceCompleteBookingAction passes the acting Admin.
     */
    public function complete(string $bookingItemUuid, ?string $reason = null, ?string $actorUserUuid = null): BookingLifecycleTransitionResult
    {
        return $this->run($bookingItemUuid, 'COMPLETED', fn ($item, $at) => $this->machine->transitionToCompleted($item, $at), $reason, $actorUserUuid);
    }

    public function cancel(string $bookingItemUuid, ?string $reason = null): BookingLifecycleTransitionResult
    {
        return $this->run($bookingItemUuid, 'CANCELLED', fn ($item, $at) => $this->machine->transitionToCancelled($item, $at), $reason);
    }

    private function run(string $bookingItemUuid, string $targetCode, callable $attempt, ?string $reason, ?string $actorUserUuid = null): BookingLifecycleTransitionResult
    {
        $itemIdBinary = UuidBinary::toBinary($bookingItemUuid);
        $actorIdBinary = $actorUserUuid === null ? null : UuidBinary::toBinary($actorUserUuid);

        return DB::transaction(function () use ($itemIdBinary, $targetCode, $attempt, $reason, $actorIdBinary): BookingLifecycleTransitionResult {
            $item = DB::table('booking_items')->where('id', $itemIdBinary)->lockForUpdate()->first();

            if ($item === null) {
                return new BookingLifecycleTransitionResult(BookingLifecycleOutcome::NOT_FOUND);
            }

            $fromCode = BookingItemStatuses::code((int) $item->status_id);

            if ($fromCode === $targetCode) {
                return new BookingLifecycleTransitionResult(BookingLifecycleOutcome::ALREADY_IN_TARGET_STATE, $fromCode, $targetCode);
            }

            $now = now();

            if (! $attempt($item, $now)) {
                return new BookingLifecycleTransitionResult(BookingLifecycleOutcome::INVALID_TRANSITION, $fromCode, $targetCode);
            }

            DB::table('booking_item_status_history')->insert([
                'id' => UuidBinary::toBinary(UuidBinary::generate()),
                'booking_item_id' => $itemIdBinary,
                'from_status_id' => (int) $item->status_id,
                'to_status_id' => BookingItemStatuses::id($targetCode),
                'changed_by_user_id' => $actorIdBinary,
                'reason' => $reason,
                'changed_at' => $now->format('Y-m-d H:i:s.u'),
            ]);

            return new BookingLifecycleTransitionResult(BookingLifecycleOutcome::TRANSITIONED, $fromCode, $targetCode);
        });
    }
}
