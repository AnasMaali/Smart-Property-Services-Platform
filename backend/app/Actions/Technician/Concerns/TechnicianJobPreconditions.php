<?php

namespace App\Actions\Technician\Concerns;

use App\Support\Technician\TechnicianJobOutcome;
use App\Support\Technician\TechnicianJobResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * Shared precondition checks for App\Actions\Technician\StartTechnicianJobAction
 * and App\Actions\Technician\CompleteTechnicianJobAction (BLUE V1 Phase 8B).
 * Both Actions call these from inside their own `booking_items` row lock, so
 * every read here observes the same transaction snapshot the caller already
 * established after acquiring that lock.
 */
trait TechnicianJobPreconditions
{
    /**
     * Actor authorization mirrors
     * App\Actions\Technician\AssignTechnicianToBookingItemAction exactly:
     * technicians have no system accounts in BLUE V1
     * (docs/03-features-and-requirements/07-technician-assignment.md), so the
     * only valid caller of Start/Complete Work is an authenticated ADMIN or
     * SUPER_ADMIN acting on the Technician's behalf. Returns null when the
     * actor is authorized, otherwise the rejection result to return
     * immediately.
     */
    private function rejectUnauthorizedActor(string $actorIdBinary): ?TechnicianJobResult
    {
        $actorExists = DB::table('users')->where('id', $actorIdBinary)->exists();

        if (! $actorExists) {
            return new TechnicianJobResult(TechnicianJobOutcome::ACTOR_NOT_FOUND);
        }

        $actorIsAdmin = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $actorIdBinary)
            ->where('roles.is_active', 1)
            ->whereIn('roles.code', ['ADMIN', 'SUPER_ADMIN'])
            ->exists();

        if (! $actorIsAdmin) {
            return new TechnicianJobResult(TechnicianJobOutcome::ACTOR_NOT_AUTHORIZED);
        }

        return null;
    }

    /**
     * The Booking Item's current active primary assignment - mirrors
     * AssignTechnicianToBookingItemAction::activePrimaryAssignment() exactly.
     * A plain (non-locking) read is intentional and sufficient: both this
     * trait's callers and AssignTechnicianToBookingItemAction::reassign()
     * always lock the `booking_items` row FOR UPDATE first, so two
     * concurrent calls for the same item serialize on that lock; the loser
     * only reads `technician_assignments` after being unblocked, at which
     * point InnoDB REPEATABLE READ establishes a fresh snapshot for this
     * transaction's first consistent read and correctly observes the
     * winner's already-committed row (including any reassignment release).
     */
    private function activePrimaryAssignment(string $itemIdBinary): ?object
    {
        return DB::table('technician_assignments')
            ->where('booking_item_id', $itemIdBinary)
            ->where('is_primary', 1)
            ->whereNull('released_at')
            ->first();
    }

    /**
     * Resolves the authoritative active Technician for the Booking Item and
     * verifies it matches the Technician the caller believes is executing
     * the job. Never trusts the caller's Technician uuid alone (BLUE V1
     * Phase 8B "Technician Assignment Must Remain Authoritative") - a
     * Technician who was released or reassigned away no longer matches, and
     * is rejected as ASSIGNMENT_MISMATCH rather than silently succeeding.
     *
     * @return TechnicianJobResult|object the rejection result, or the active technician_assignments row on success
     */
    private function resolveActiveAssignment(string $itemIdBinary, string $technicianIdBinary, string $bookingItemUuid, string $itemStatusCode): mixed
    {
        $assignment = $this->activePrimaryAssignment($itemIdBinary);

        if ($assignment === null) {
            return new TechnicianJobResult(TechnicianJobOutcome::NO_ACTIVE_ASSIGNMENT, $bookingItemUuid, itemStatusFrom: $itemStatusCode);
        }

        if ($assignment->technician_id !== $technicianIdBinary) {
            return new TechnicianJobResult(
                TechnicianJobOutcome::ASSIGNMENT_MISMATCH,
                $bookingItemUuid,
                UuidBinary::toString($assignment->technician_id),
                UuidBinary::toString($assignment->id),
                $itemStatusCode,
            );
        }

        return $assignment;
    }
}
