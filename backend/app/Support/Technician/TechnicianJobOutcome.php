<?php

namespace App\Support\Technician;

/**
 * Every possible result of App\Actions\Technician\StartTechnicianJobAction and
 * App\Actions\Technician\CompleteTechnicianJobAction (BLUE V1 Phase 8B). Never
 * thrown as an exception for an expected/anticipated case - matches the same
 * "return a value for expected rejection reasons, throw only for genuine
 * failures" convention already used by TechnicianAssignmentOutcome /
 * BookingLifecycleOutcome.
 */
enum TechnicianJobOutcome: string
{
    /** The Booking Item moved ASSIGNED -> IN_PROGRESS. */
    case STARTED = 'STARTED';

    /**
     * The Booking Item was already IN_PROGRESS under the exact same active
     * Technician - returned idempotently, nothing written. Covers a retried
     * start() call after a lost response.
     */
    case ALREADY_STARTED = 'ALREADY_STARTED';

    /** The Booking Item moved IN_PROGRESS -> COMPLETED. */
    case COMPLETED = 'COMPLETED';

    /**
     * The Booking Item was already COMPLETED under the exact same active
     * Technician - returned idempotently, nothing written. Covers a retried
     * complete() call after a lost response.
     */
    case ALREADY_COMPLETED = 'ALREADY_COMPLETED';

    /** No matching booking_items row exists for the given id. */
    case ITEM_NOT_FOUND = 'ITEM_NOT_FOUND';

    /**
     * The Booking Item's current status does not allow this operation:
     * start() requires ASSIGNED (or an idempotent IN_PROGRESS retry - see
     * ALREADY_STARTED); complete() requires IN_PROGRESS (or an idempotent
     * COMPLETED retry - see ALREADY_COMPLETED). PENDING_ASSIGNMENT and
     * CANCELLED are never valid starting points for either method, and
     * COMPLETED/CANCELLED are never valid starting points for start().
     */
    case ITEM_NOT_ELIGIBLE = 'ITEM_NOT_ELIGIBLE';

    /**
     * The Booking Item's status allows this operation, but it has no active
     * primary technician_assignments row at all. Defensive: BookingItemStatusMachine
     * only reaches ASSIGNED/IN_PROGRESS/COMPLETED via
     * AssignTechnicianToBookingItemAction, which always writes an active
     * assignment first - this should not occur through the normal domain
     * flow, but is never assumed.
     */
    case NO_ACTIVE_ASSIGNMENT = 'NO_ACTIVE_ASSIGNMENT';

    /**
     * The supplied Technician does not match the Booking Item's current
     * active primary technician_assignments row. Covers both a genuinely
     * wrong Technician uuid and a stale caller still holding a Technician who
     * has since been released/reassigned away
     * (App\Actions\Technician\AssignTechnicianToBookingItemAction::reassign()) -
     * the active assignment row is always authoritative, never the caller's
     * claim.
     */
    case ASSIGNMENT_MISMATCH = 'ASSIGNMENT_MISMATCH';

    /** No matching users row exists for the given actor id. */
    case ACTOR_NOT_FOUND = 'ACTOR_NOT_FOUND';

    /**
     * The actor user exists but does not hold an ADMIN or SUPER_ADMIN role.
     * Technicians have no system accounts in BLUE V1
     * (docs/03-features-and-requirements/07-technician-assignment.md: "the
     * admin ... updates the service progress on their behalf"), so every
     * caller of these Actions is necessarily an admin actor, exactly like
     * AssignTechnicianToBookingItemAction.
     */
    case ACTOR_NOT_AUTHORIZED = 'ACTOR_NOT_AUTHORIZED';
}
