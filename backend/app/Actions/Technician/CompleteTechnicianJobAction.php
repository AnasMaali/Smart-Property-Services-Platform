<?php

namespace App\Actions\Technician;

use App\Actions\Booking\TransitionBookingItemStatusAction;
use App\Actions\Technician\Concerns\TechnicianJobPreconditions;
use App\Support\Booking\BookingItemStatuses;
use App\Support\Technician\TechnicianJobOutcome;
use App\Support\Technician\TechnicianJobResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use App\Actions\Booking\SyncBookingStatusFromItemsAction;
/**
 * The one canonical, server/internal-only entry point for completing work on
 * an in-progress Booking Item (BLUE V1 Phase 8B). Same technician
 * authentication boundary, lock order, and Booking-Item scoping as
 * App\Actions\Technician\StartTechnicianJobAction - see that class's docblock
 * for the shared reasoning, which applies identically here.
 *
 * `completed_at` already exists on `booking_items`
 * (database/blue_v1_schema.sql) and is written by
 * App\Support\Booking\BookingItemStatusMachine::transitionToCompleted() from
 * the single timestamp captured inside
 * App\Actions\Booking\TransitionBookingItemStatusAction::complete()'s own
 * transaction - this Action never captures or writes its own timestamp, so a
 * retried complete() call can never mutate an already-set `completed_at`.
 *
 * Completion evidence (photos, signatures, service reports, technician
 * notes) has no schema support in BLUE V1 - `booking_items`,
 * `booking_item_status_history`, and `technician_assignments` have no column
 * for it, and no dedicated media/evidence table references either row. This
 * Action therefore accepts no such data; see the Phase 8B implementation
 * report "Completion Evidence" for the reported gap.
 */
class CompleteTechnicianJobAction
{
    use TechnicianJobPreconditions;

    public function __construct(
    private readonly TransitionBookingItemStatusAction $itemLifecycle = new TransitionBookingItemStatusAction,
    private readonly SyncBookingStatusFromItemsAction $bookingLifecycleSync = new SyncBookingStatusFromItemsAction,
) {}

    /**
     * Moves a Booking Item IN_PROGRESS -> COMPLETED on behalf of its current
     * active primary Technician. Same authority/idempotency guarantees as
     * StartTechnicianJobAction::start(): $technicianUuid must match the
     * Booking Item's actual active `technician_assignments` row, and a
     * retried call with the same Technician after the item is already
     * COMPLETED returns ALREADY_COMPLETED without writing anything.
     */
    public function complete(string $bookingItemUuid, string $technicianUuid, string $actorUserUuid, ?string $reason = null): TechnicianJobResult
    {
        $itemIdBinary = UuidBinary::toBinary($bookingItemUuid);
        $technicianIdBinary = UuidBinary::toBinary($technicianUuid);
        $actorIdBinary = UuidBinary::toBinary($actorUserUuid);

        $result = DB::transaction(function () use ($itemIdBinary, $technicianIdBinary, $actorIdBinary, $bookingItemUuid, $technicianUuid, $reason): TechnicianJobResult {
            $item = DB::table('booking_items')->where('id', $itemIdBinary)->lockForUpdate()->first();

            if ($item === null) {
                return new TechnicianJobResult(TechnicianJobOutcome::ITEM_NOT_FOUND);
            }

            $actorRejection = $this->rejectUnauthorizedActor($actorIdBinary);

            if ($actorRejection !== null) {
                return $actorRejection;
            }

            $itemStatusCode = BookingItemStatuses::code((int) $item->status_id);

            if ($itemStatusCode === 'COMPLETED') {
                $resolved = $this->resolveActiveAssignment($itemIdBinary, $technicianIdBinary, $bookingItemUuid, $itemStatusCode);

                if ($resolved instanceof TechnicianJobResult) {
                    return $resolved;
                }

                return new TechnicianJobResult(
                    TechnicianJobOutcome::ALREADY_COMPLETED,
                    $bookingItemUuid,
                    $technicianUuid,
                    UuidBinary::toString($resolved->id),
                    $itemStatusCode,
                    $itemStatusCode,
                );
            }

            if ($itemStatusCode !== 'IN_PROGRESS') {
                return new TechnicianJobResult(TechnicianJobOutcome::ITEM_NOT_ELIGIBLE, $bookingItemUuid, itemStatusFrom: $itemStatusCode);
            }

            $resolved = $this->resolveActiveAssignment($itemIdBinary, $technicianIdBinary, $bookingItemUuid, $itemStatusCode);

            if ($resolved instanceof TechnicianJobResult) {
                return $resolved;
            }

            $transition = $this->itemLifecycle->complete($bookingItemUuid, $reason);

            return new TechnicianJobResult(
                TechnicianJobOutcome::COMPLETED,
                $bookingItemUuid,
                $technicianUuid,
                UuidBinary::toString($resolved->id),
                $transition->fromStatus,
                $transition->toStatus,
            );
        });
if (in_array($result->outcome, [
    TechnicianJobOutcome::COMPLETED,
    TechnicianJobOutcome::ALREADY_COMPLETED,
], true)) {
    $this->bookingLifecycleSync->syncForItem($bookingItemUuid);
}

return $result;
    }
}
