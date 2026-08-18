<?php

namespace App\Support\Contract\Billing\Gateway;

/**
 * The one safe, provider-neutral shape ContractBillingGateway::parseWebhook()
 * produces - mirrors App\Support\Payment\Gateway\NormalizedPaymentEvent's
 * role exactly, but for the very different set of Stripe object shapes a
 * subscription webhook endpoint receives (Checkout Session, Subscription,
 * Invoice - never a PaymentIntent). Only the fields relevant to the actual
 * $eventType are ever populated by a given gateway implementation; every
 * other field is null. App\Actions\Contract\Billing\
 * ProcessContractBillingWebhookAction never reads a field the event type
 * does not guarantee.
 */
final readonly class NormalizedContractBillingEvent
{
    public function __construct(
        public string $providerEventId,
        public string $eventType,
        public ?string $contractBillingUuid,
        public ?string $stripeSubscriptionId,
        public ?string $stripeCustomerId,
        public ?string $stripeCheckoutSessionId,
        public ?string $stripePriceId,
        public ?string $stripeProductId,
        public ?string $subscriptionStatus,
        public ?bool $cancelAtPeriodEnd,
        public ?bool $invoicePaid,
        public ?string $currentPeriodStart,
        public ?string $currentPeriodEnd,
        public ?string $cancelAt,
        public ?string $canceledAt,
    ) {}
}
