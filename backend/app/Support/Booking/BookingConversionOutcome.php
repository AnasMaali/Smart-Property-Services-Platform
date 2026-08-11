<?php

namespace App\Support\Booking;

/**
 * Every possible result of CreateBookingFromSuccessfulPaymentAction::handle().
 * Never thrown as an exception for an expected/anticipated case - matching
 * the rest of the codebase's "return a value for expected rejection
 * reasons, throw only for genuine failures" convention (see
 * App\Support\Cart\Concerns\BuildsCartResult).
 */
enum BookingConversionOutcome: string
{
    /** A new Booking was created by this call. */
    case CREATED = 'CREATED';

    /** A Booking already existed for this payment attempt - returned idempotently, nothing written. */
    case ALREADY_EXISTS = 'ALREADY_EXISTS';

    /** The payment attempt is not SUCCESSFUL, or requires_reconciliation was already true on entry. */
    case NOT_ELIGIBLE = 'NOT_ELIGIBLE';

    /**
     * The payment attempt itself was safe to convert, but a structural
     * invariant this call re-checked (cart status, hold identity) was not -
     * safely withheld without mutating anything, including the payment.
     */
    case BLOCKED = 'BLOCKED';

    /**
     * A previously-unknown safety problem was discovered at conversion time
     * (bad snapshot hash, hold expired/released/mismatched since success,
     * or an internal arithmetic mismatch) - payment_attempts was updated to
     * requires_reconciliation = true using an existing reason code so the
     * condition is operationally visible; no Booking was created.
     */
    case RECONCILIATION_REQUIRED = 'RECONCILIATION_REQUIRED';

    /** No matching payment_attempts row exists for the given id. */
    case NOT_FOUND = 'NOT_FOUND';
}
