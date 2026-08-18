<?php

namespace App\Support\Contract\Billing\Gateway;

use App\Support\Payment\Gateway\VerifiedWebhookResult;

/**
 * The one provider-neutral boundary between BLUE's Service Contract Billing
 * domain (BLUE V1 Phase 11) and any concrete subscription-billing provider -
 * mirrors App\Support\Payment\Gateway\PaymentGateway's role exactly, kept as
 * a fully separate interface (never merged with PaymentGateway) because a
 * Contract subscription and a Booking PaymentIntent are different Stripe
 * object families with different webhook events and different local
 * ledgers; only App\Actions\Contract\Billing\* ever depends on this
 * interface or any of its implementations.
 *
 * Reuses App\Support\Payment\Gateway\VerifiedWebhookResult - that DTO only
 * wraps "was this raw body authentically signed" plus an opaque verified
 * provider event, which is exactly as true for a Subscription/Invoice
 * webhook delivery as it is for a PaymentIntent one; duplicating it would
 * add nothing.
 */
interface ContractBillingGateway
{
    /**
     * The exact service_contract_billings.provider_code / service_contract_
     * billing_webhook_events.provider_code value this implementation writes.
     */
    public function providerCode(): string;

    /**
     * Starts (or safely resumes) exactly one provider-side subscription
     * Checkout Session for the given BLUE Contract Billing record. Must be
     * called with a stable, billing-record-derived provider idempotency key
     * so a retry with the same ContractBillingCheckoutData never creates a
     * second provider-side Checkout Session or a second Stripe Customer -
     * see ContractBillingCheckoutData::$providerIdempotencyKey /
     * $customerIdempotencyKey.
     *
     * Must never throw for an ordinary provider-side rejection that still
     * proves no object was created - that is DEFINITIVE_FAILURE, not an
     * exception. Only a genuinely ambiguous network/timeout outcome escapes
     * as UNKNOWN.
     */
    public function createSubscriptionCheckout(ContractBillingCheckoutData $data): ContractBillingCheckoutResult;

    /**
     * Best-effort request to cancel the given provider-side Subscription.
     * This is deliberately NOT exactly-once delivery - it cannot be, since
     * it is an external network operation: a timeout or connection failure
     * is genuinely ambiguous (the provider may or may not have received/
     * processed the request). The guarantee BLUE V1 Phase 11 actually makes
     * is:
     *   - exactly ONE logical internal cancellation request is ever created
     *     per Contract (App\Actions\Contract\Billing\
     *     CancelContractBillingSubscriptionAction::markCancellationRequested());
     *   - that request is delivered to the provider with AT-LEAST-ONCE
     *     durability - it is retried (App\Actions\Contract\Billing\
     *     RetryPendingContractBillingCancellationsAction /
     *     `contracts:retry-pending-billing-cancellations`) for as long as it
     *     stays unreconciled, so a single failed/ambiguous attempt can never
     *     permanently strand a Subscription un-cancelled;
     *   - the provider-side cancel operation itself must be safe to call
     *     more than once for the same Subscription (cancelling an
     *     already-canceled Stripe Subscription is a safe no-op
     *     provider-side), i.e. idempotent/safely retryable from the
     *     provider's own perspective;
     *   - the eventual customer.subscription.deleted webhook - never this
     *     call's return value or any exception it raises - is the sole
     *     authority that reconciles service_contract_billings.status_id to
     *     CANCELLED.
     * An implementation MAY swallow its own errors and always return
     * normally (as App\Support\Contract\Billing\Gateway\
     * StripeContractBillingGateway does), or MAY let a genuine delivery
     * failure escape as a Throwable (as App\Support\Contract\Billing\
     * Gateway\FakeContractBillingGateway optionally does, to let tests
     * simulate a provider outage) - every caller
     * (CancelContractBillingSubscriptionAction) must treat this as a pure
     * fire-and-forget side channel either way and never let a failure here
     * affect its own transaction or response.
     */
    public function cancelSubscription(string $stripeSubscriptionId): void;

    /**
     * Best-effort request to schedule the given provider-side Subscription
     * to automatically cancel at $cancelAtUnixTimestamp - the ONLY place a
     * Contract's business guarantee ("the Stripe subscription must never
     * continue billing past the Contract's own `ends_at`") is enforced
     * provider-side. Deliberately NOT part of createSubscriptionCheckout()/
     * ContractBillingCheckoutData - Stripe's Checkout Session API has no
     * `subscription_data.cancel_at` parameter (verified against a real
     * Stripe test-mode 400 `parameter_unknown` response and the installed
     * stripe-php SDK's own type contract), so this can only ever be applied
     * AFTER the Subscription already exists, via the provider's Subscription
     * update operation - see App\Actions\Contract\Billing\
     * ScheduleContractBillingSubscriptionCancelAtAction, which calls this
     * the moment a Subscription id becomes known (from either
     * checkout.session.completed or customer.subscription.created/updated -
     * whichever webhook arrives first).
     *
     * Same at-least-once, never-exactly-once delivery guarantee as
     * cancelSubscription() - see that method's docblock. Must be safe to
     * call more than once with the same ($stripeSubscriptionId,
     * $cancelAtUnixTimestamp) pair (setting a Subscription's `cancel_at` to
     * the value it already has is a safe no-op provider-side). Never writes
     * service_contract_billings.cancel_at itself - only the eventual
     * customer.subscription.updated webhook this call itself triggers
     * reports the new `cancel_at` back, which
     * App\Actions\Contract\Billing\ProcessContractBillingWebhookAction's
     * existing subscription-sync handling already records - so this side
     * channel can never race or conflict with it.
     */
    public function scheduleSubscriptionCancelAt(string $stripeSubscriptionId, int $cancelAtUnixTimestamp): void;

    /**
     * Authenticity check ONLY - must be called with the raw, unmodified
     * request body and the provider's signature header(s), against this
     * domain's OWN webhook signing secret (never the Payment domain's - see
     * BLUE V1 Phase 11 "WEBHOOKS", a shared verified transport layer with
     * separate handlers, never a shared secret). Must fail safely when the
     * webhook secret is not configured.
     *
     * @param  array<string, string>  $signatureHeaders
     */
    public function verifyWebhook(string $rawBody, array $signatureHeaders): VerifiedWebhookResult;

    /**
     * Normalizes an already-verified provider event into the one safe,
     * provider-neutral shape App\Actions\Contract\Billing\
     * ProcessContractBillingWebhookAction consumes. Must never be called
     * with an unverified payload.
     */
    public function parseWebhook(mixed $verifiedProviderEvent): NormalizedContractBillingEvent;
}
