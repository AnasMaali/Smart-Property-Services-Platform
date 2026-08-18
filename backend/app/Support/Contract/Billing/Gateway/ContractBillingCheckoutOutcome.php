<?php

namespace App\Support\Contract\Billing\Gateway;

/**
 * The three-way outcome of ContractBillingGateway::createSubscriptionCheckout()
 * - mirrors App\Support\Payment\Gateway\PaymentCreationOutcome exactly, for
 * the same reason: a network timeout must never be mistaken for a
 * definitive provider-side failure.
 */
enum ContractBillingCheckoutOutcome: string
{
    /**
     * A provider-side Checkout Session (and Stripe Customer, if one had to
     * be created) exists. Safe to persist the returned references.
     */
    case CREATED = 'CREATED';

    /**
     * Definitively proven that no provider-side Checkout Session was
     * created (e.g. a synchronous 4xx configuration/parameter error).
     */
    case DEFINITIVE_FAILURE = 'DEFINITIVE_FAILURE';

    /**
     * The provider call's outcome could not be determined (timeout,
     * connection failure, 5xx). The billing row must be left exactly as it
     * was found - never marked failed, never assumed created.
     */
    case UNKNOWN = 'UNKNOWN';
}
