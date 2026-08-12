<?php

namespace App\Support\Technician;

/**
 * Every possible result of App\Actions\Technician\AssignTechnicianToBookingItemAction
 * (both assign() and reassign()). Never thrown as an exception for an
 * expected/anticipated case - matches the "return a value for expected
 * rejection reasons, throw only for genuine failures" convention already
 * used by BookingConversionOutcome / BookingLifecycleOutcome (BLUE V1 Phase
 * 8A).
 */
enum TechnicianAssignmentOutcome: string
{
    /** A new active primary assignment was created (assign(), first time). */
    case ASSIGNED = 'ASSIGNED';

    /** A new active primary assignment replaced the previous one (reassign()). */
    case REASSIGNED = 'REASSIGNED';

    /**
     * The Booking Item already has an active primary assignment for the
     * exact same Technician - returned idempotently, nothing written.
     * Covers both a retried assign() call and a reassign() call that names
     * the Technician already active.
     */
    case ALREADY_ASSIGNED = 'ALREADY_ASSIGNED';

    /** No matching booking_items row exists for the given id. */
    case ITEM_NOT_FOUND = 'ITEM_NOT_FOUND';

    /**
     * The Booking Item's current status does not allow this operation:
     * assign() requires PENDING_ASSIGNMENT (or an idempotent ASSIGNED
     * retry - see ALREADY_ASSIGNED); reassign() requires ASSIGNED or
     * IN_PROGRESS. IN_PROGRESS/COMPLETED/CANCELLED are never valid starting
     * points for assign(), and PENDING_ASSIGNMENT/COMPLETED/CANCELLED are
     * never valid starting points for reassign().
     */
    case ITEM_NOT_ELIGIBLE = 'ITEM_NOT_ELIGIBLE';

    /**
     * assign() was called on a Booking Item that already has a different
     * Technician actively assigned - assign() never silently overwrites an
     * existing assignment; the caller must use reassign() instead.
     */
    case ASSIGNED_TO_ANOTHER_TECHNICIAN = 'ASSIGNED_TO_ANOTHER_TECHNICIAN';

    /** reassign() was called on a Booking Item with no active primary assignment to replace. */
    case NO_ACTIVE_ASSIGNMENT = 'NO_ACTIVE_ASSIGNMENT';

    /** No matching technicians row exists for the given id. */
    case TECHNICIAN_NOT_FOUND = 'TECHNICIAN_NOT_FOUND';

    /** The Technician's current technician_statuses row has is_assignable = 0. */
    case TECHNICIAN_NOT_ELIGIBLE = 'TECHNICIAN_NOT_ELIGIBLE';

    /** No matching users row exists for the given assigned_by/released_by actor id. */
    case ACTOR_NOT_FOUND = 'ACTOR_NOT_FOUND';

    /** The actor user exists but does not hold an ADMIN or SUPER_ADMIN role. */
    case ACTOR_NOT_AUTHORIZED = 'ACTOR_NOT_AUTHORIZED';

    /**
     * The Booking Item's service has no active service_specializations row
     * at all, so no specialization_id can be resolved to satisfy
     * technician_assignments.specialization_id (NOT NULL) - a catalog
     * data-completeness gap, not a technician eligibility problem.
     */
    case SERVICE_SPECIALIZATION_NOT_CONFIGURED = 'SERVICE_SPECIALIZATION_NOT_CONFIGURED';

    /** The Technician holds no active specialization matching any active service_specializations row for this service. */
    case SPECIALIZATION_MISMATCH = 'SPECIALIZATION_MISMATCH';

    /**
     * The Technician already has another active assignment, on a different
     * Booking, whose appointment_slot period overlaps this Booking's
     * appointment_slot period.
     */
    case TECHNICIAN_DOUBLE_BOOKED = 'TECHNICIAN_DOUBLE_BOOKED';
}
