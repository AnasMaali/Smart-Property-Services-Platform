<?php

namespace App\Support\Booking;

/**
 * Every possible result of a Phase 7B lifecycle transition (Booking or
 * Booking Item). Never thrown as an exception for an expected/anticipated
 * case - mirrors App\Support\Booking\BookingConversionOutcome's "return a
 * value for expected rejection reasons, throw only for genuine failures"
 * convention.
 */
enum BookingLifecycleOutcome: string
{
    /** The row moved from its previous status to the requested target status. */
    case TRANSITIONED = 'TRANSITIONED';

    /**
     * The row was already in the requested target status - returned
     * idempotently, nothing written. Matches the "retry must not duplicate
     * history" requirement for a transition retried after a lost response.
     */
    case ALREADY_IN_TARGET_STATE = 'ALREADY_IN_TARGET_STATE';

    /**
     * The row's current status does not allow moving to the requested
     * target status (wrong starting state, or the current status is
     * terminal) - safely withheld, nothing written.
     */
    case INVALID_TRANSITION = 'INVALID_TRANSITION';

    /** No matching row exists for the given id. */
    case NOT_FOUND = 'NOT_FOUND';
}
