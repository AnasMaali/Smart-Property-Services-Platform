<?php

namespace App\Support\Payment\Gateway;

/**
 * The three-way outcome of PaymentGateway::refundPayment() - mirrors
 * PaymentCreationOutcome exactly, for the same reason: a network timeout
 * must never be mistaken for a definitive provider-side rejection, and a
 * definitive rejection must never be silently retried with a fresh
 * idempotency key.
 */
enum RefundCreationOutcome: string
{
    /**
     * A provider-side refund object was created (its own status may still
     * be "pending" - Stripe confirms final settlement asynchronously via
     * webhook). Safe to store the provider refund reference; the
     * `booking_refunds` row stays PENDING until the provider confirms
     * "succeeded".
     */
    case CREATED = 'CREATED';

    /**
     * Definitively proven no provider-side refund object was created (e.g.
     * the charge was already fully refunded, or a bad request). Safe to
     * mark the `booking_refunds` row FAILED - never retryable with the same
     * idempotency key.
     */
    case DEFINITIVE_FAILURE = 'DEFINITIVE_FAILURE';

    /**
     * The provider call's outcome could not be determined (timeout,
     * connection failure, 5xx, or any other ambiguous result). The
     * `booking_refunds` row MUST stay PENDING and recoverable via the same
     * persisted idempotency key - never treated as failure, never retried
     * with a new key.
     */
    case UNKNOWN = 'UNKNOWN';
}
