<?php

namespace App\Support\Contract\Billing\Gateway;

/**
 * Everything a ContractBillingGateway needs to start (or safely resume)
 * exactly one provider-side subscription Checkout Session for exactly one
 * BLUE Service Contract Billing record. Built entirely from
 * server-authoritative values already frozen on `service_contract_billings`
 * at Admin-approval time (App\Actions\Admin\Contract\AdminApproveContractAction)
 * - never from client input (BLUE V1 Phase 11 "The Customer must never
 * provide amount / currency / Stripe Price ID / Stripe Product ID /
 * subscription status").
 *
 * Deliberately carries NO cancel-at-term-end timestamp: verified against a
 * real Stripe test-mode API call (400 `invalid_request_error` /
 * `parameter_unknown` - "Received unknown parameter: subscription_data[cancel_at]")
 * and the installed stripe-php SDK's own type contract for
 * `checkout.sessions.create()`, Stripe's Checkout Session API does not
 * accept a `subscription_data.cancel_at` parameter at all - only
 * `Subscription::update()` does. Scheduling the Contract's term-end
 * cancellation is therefore a separate, post-creation concern - see
 * App\Actions\Contract\Billing\ScheduleContractBillingSubscriptionCancelAtAction.
 */
final readonly class ContractBillingCheckoutData
{
    public function __construct(
        public string $contractBillingUuid,
        public string $contractUuid,
        public ?string $stripeCustomerId,
        public string $customerEmail,
        public string $recurringAmount,
        public string $currencyCode,
        public int $currencyMinorUnit,
        public string $billingInterval,
        public string $productName,
        public string $successUrl,
        public string $cancelUrl,
        public string $providerIdempotencyKey,
        public string $customerIdempotencyKey,
    ) {}
}
