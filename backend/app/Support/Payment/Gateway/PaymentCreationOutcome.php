<?php

namespace App\Support\Payment\Gateway;

/**
 * The three-way outcome of PaymentGateway::createPayment() -
 * CreatePaymentAttemptAction's Transaction B / compensation logic branches
 * on this, never on a raw exception, so a network timeout can never be
 * mistaken for a definitive provider-side failure (see docs/api-contracts/
 * payments-v1.md "External call / DB transaction boundary").
 */
enum PaymentCreationOutcome: string
{
    /**
     * A provider-side payment object exists (regardless of its own
     * lifecycle state - even an immediately-declined card still means a
     * PaymentIntent was created). Safe to store provider references and
     * leave the local attempt PENDING.
     */
    case CREATED = 'CREATED';

    /**
     * Definitively proven that no provider-side object was created (e.g. a
     * synchronous 4xx configuration/parameter error from the provider).
     * Only this outcome may drive the compensation transaction: local
     * PENDING -> FAILED, hold released, cart CHECKOUT -> ACTIVE.
     */
    case DEFINITIVE_FAILURE = 'DEFINITIVE_FAILURE';

    /**
     * The provider call's outcome could not be determined (timeout,
     * connection failure, 5xx, or any other ambiguous result). The local
     * attempt MUST remain PENDING and recoverable via the same provider
     * idempotency key - never treated as failure, never retried with a new
     * key.
     */
    case UNKNOWN = 'UNKNOWN';
}
