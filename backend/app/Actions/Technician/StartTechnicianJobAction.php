<?php

namespace App\Actions\Technician;

use App\Actions\Booking\TransitionBookingItemStatusAction;
use App\Actions\Booking\SyncBookingStatusFromItemsAction;
use App\Actions\Technician\Concerns\TechnicianJobPreconditions;
use App\Support\Booking\BookingItemStatuses;
use App\Support\Technician\TechnicianJobOutcome;
use App\Support\Technician\TechnicianJobResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * The one canonical, server/internal-only entry point for starting work on
 * an already-assigned Booking Item (BLUE V1 Phase 8B). Never reachable from
 * any HTTP route, for the same reason
 * App\Actions\Technician\AssignTechnicianToBookingItemAction is internal:
 * docs/03-features-and-requirements/07-technician-assignment.md is explicit
 * that "the admin ... updates the service progress on their behalf" -
 * technicians have no system accounts in Version 1, so there is no safe
 * technician-authenticated caller for this endpoint yet. A future Technician
 * Authentication/API phase (or an admin panel, once admin authentication
 * exists) is expected to call start() directly.
 *
 * Booking-Item-scoped only, exactly like assignment: this never reads or
 * locks the parent `bookings` row, matching Phase 7B's deliberate
 * independence between Booking and Booking Item lifecycles (see
 * App\Actions\Booking\TransitionBookingItemStatusAction). One item starting
 * never affects a sibling item's status.
 *
 * Never touches `payment_attempts`, `carts`, `checkout_snapshot`, or the
 * Booking Item's own pricing snapshot columns, and never calls
 * PricingEngine/Stripe - starting work is fulfillment state, not financial
 * state.
 *
 * No dedicated `started_at` column exists on `booking_items`
 * (database/blue_v1_schema.sql) - `status_changed_at`, written by
 * BookingItemStatusMachine, is the authoritative "work started" timestamp.
 * Inventing a separate column here would duplicate that value without any
 * documented need for it.
 *
 * Lock order: `booking_items` only. `technician_assignments` is read with a
 * plain (non-locking) SELECT after that lock is held - see
 * App\Actions\Technician\Concerns\TechnicianJobPreconditions for why this is
 * race-safe against a concurrent
 * AssignTechnicianToBookingItemAction::reassign() call, which locks the same
 * `booking_items` row first. This never locks `bookings`, `payment_attempts`,
 * `carts`, or `appointment_holds`.
 *
 * No appointment-timing validation: nothing in the Version 1 requirements
 * docs restricts when work may start relative to
 * `appointment_slots.starts_at`/`ends_at`, so none is invented here.
 */
class StartTechnicianJobAction
{
    use TechnicianJobPreconditions;

    public function __construct(
    private readonly TransitionBookingItemStatusAction $itemLifecycle = new TransitionBookingItemStatusAction,
    private readonly SyncBookingStatusFromItemsAction $bookingLifecycleSync = new SyncBookingStatusFromItemsAction,
) {}

    /**
     * Moves a Booking Item ASSIGNED -> IN_PROGRESS on behalf of its current
     * active primary Technician. $technicianUuid is never trusted as proof
     * of identity by itself - it must match the Booking Item's actual active
     * `technician_assignments` row (see
     * TechnicianJobPreconditions::resolveActiveAssignment()), so a
     * Technician who has since been released or reassigned away can never
     * successfully start the job under their own uuid.
     *
     * Idempotent: retrying with the exact same Technician after a lost
     * response returns ALREADY_STARTED and writes nothing. Retrying with a
     * Technician who no longer holds the active assignment (e.g. the item
     * was reassigned in between) is rejected as ASSIGNMENT_MISMATCH rather
     * than silently reporting success.
     */
    public function start(
        string $bookingItemUuid,
        string $technicianUuid,
        string $actorUserUuid,
        ?string $reason = null,
        ?callable $afterMutation = null,
    ): TechnicianJobResult
    {
        $itemIdBinary = UuidBinary::toBinary($bookingItemUuid);
        $technicianIdBinary = UuidBinary::toBinary($technicianUuid);
        $actorIdBinary = UuidBinary::toBinary($actorUserUuid);

        $result = DB::transaction(function () use ($itemIdBinary, $technicianIdBinary, $actorIdBinary, $bookingItemUuid, $technicianUuid, $reason, $afterMutation): TechnicianJobResult {
            $item = DB::table('booking_items')->where('id', $itemIdBinary)->lockForUpdate()->first();

            if ($item === null) {
                return new TechnicianJobResult(TechnicianJobOutcome::ITEM_NOT_FOUND);
            }

            $actorRejection = $this->rejectUnauthorizedActor($actorIdBinary);

            if ($actorRejection !== null) {
                return $actorRejection;
            }

            $itemStatusCode = BookingItemStatuses::code((int) $item->status_id);

            if ($itemStatusCode === 'IN_PROGRESS') {
                $resolved = $this->resolveActiveAssignment($itemIdBinary, $technicianIdBinary, $bookingItemUuid, $itemStatusCode);

                if ($resolved instanceof TechnicianJobResult) {
                    return $resolved;
                }

                return new TechnicianJobResult(
                    TechnicianJobOutcome::ALREADY_STARTED,
                    $bookingItemUuid,
                    $technicianUuid,
                    UuidBinary::toString($resolved->id),
                    $itemStatusCode,
                    $itemStatusCode,
                );
            }

            if ($itemStatusCode !== 'ASSIGNED') {
                return new TechnicianJobResult(TechnicianJobOutcome::ITEM_NOT_ELIGIBLE, $bookingItemUuid, itemStatusFrom: $itemStatusCode);
            }

            $resolved = $this->resolveActiveAssignment($itemIdBinary, $technicianIdBinary, $bookingItemUuid, $itemStatusCode);

            if ($resolved instanceof TechnicianJobResult) {
                return $resolved;
            }

            $transition = $this->itemLifecycle->start($bookingItemUuid, $reason);

            $result = new TechnicianJobResult(
                TechnicianJobOutcome::STARTED,
                $bookingItemUuid,
                $technicianUuid,
                UuidBinary::toString($resolved->id),
                $transition->fromStatus,
                $transition->toStatus,
            );

            if ($afterMutation !== null) {
                $afterMutation($result);
            }

            return $result;
        });
if (in_array($result->outcome, [
    TechnicianJobOutcome::STARTED,
    TechnicianJobOutcome::ALREADY_STARTED,
], true)) {
    $this->bookingLifecycleSync->syncForItem($bookingItemUuid);
}

return $result;
    }
}
