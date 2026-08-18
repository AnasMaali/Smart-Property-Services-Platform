<?php

namespace App\Support\Contract\Billing\Gateway;

/**
 * The one safe, provider-neutral result of
 * ContractBillingGateway::createSubscriptionCheckout(). $checkoutUrl (or any
 * other client-initiation field) is the only value from this DTO ever
 * returned to Flutter - never $failureMessage verbatim (may carry provider
 * internals) and never any Stripe secret.
 */
final readonly class ContractBillingCheckoutResult
{
    private function __construct(
        public ContractBillingCheckoutOutcome $outcome,
        public ?string $stripeCustomerId,
        public ?string $checkoutSessionId,
        public ?string $checkoutUrl,
        public ?string $failureCode,
        public ?string $failureMessage,
    ) {}

    public static function created(string $stripeCustomerId, string $checkoutSessionId, ?string $checkoutUrl): self
    {
        return new self(ContractBillingCheckoutOutcome::CREATED, $stripeCustomerId, $checkoutSessionId, $checkoutUrl, null, null);
    }

    public static function definitiveFailure(string $failureCode, string $failureMessage): self
    {
        return new self(ContractBillingCheckoutOutcome::DEFINITIVE_FAILURE, null, null, null, $failureCode, $failureMessage);
    }

    public static function unknown(?string $failureMessage = null): self
    {
        return new self(ContractBillingCheckoutOutcome::UNKNOWN, null, null, null, null, $failureMessage);
    }
}
