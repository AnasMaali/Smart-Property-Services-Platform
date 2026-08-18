<?php

namespace App\Support\Contract\Billing\Gateway;

use App\Support\Payment\Gateway\MinorUnitConverter;
use App\Support\Payment\Gateway\VerifiedWebhookResult;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Event as StripeEvent;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Invoice as StripeInvoice;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;
use Stripe\Webhook as StripeWebhook;
use UnexpectedValueException;

/**
 * The BLUE V1 Phase 11 approved Contract Billing provider adapter, targeting
 * Stripe Checkout (mode=subscription) + Subscriptions + Invoices - never a
 * PaymentIntent, that remains App\Support\Payment\Gateway\
 * StripePaymentGateway's exclusive concern. No Stripe SDK object or array
 * ever leaves this class - every method returns one of the typed DTOs in
 * this namespace.
 *
 * Pricing strategy ("recurring Price/Product creation" vs. "Checkout
 * line_items.price_data recurring" - BLUE V1 Phase 11 "CREATE SUBSCRIPTION
 * CHECKOUT"): this gateway uses inline `price_data` on the Checkout Session
 * line item, never a pre-created/searched-and-reused Price object. A
 * Contract's recurring_amount/currency/billing_interval is a fully
 * server-authoritative, immutable snapshot already frozen on
 * `service_contract_billings` at Admin-approval time (see
 * App\Actions\Admin\Contract\AdminApproveContractAction) - there is nothing
 * to "reuse" a Price for, and pre-creating one would only add a second
 * place that snapshot could drift from, plus a race between two concurrent
 * checkout attempts each trying to find-or-create "the" Price for the same
 * amount. Stripe itself creates exactly one real, persisted Price (and
 * Product) from the price_data the moment the Subscription is created, and
 * every future renewal invoice reuses that same Stripe-managed Price
 * automatically - so this class still ends up with a stable, reusable
 * Price for the life of the subscription, without this codebase ever having
 * to manage that object's lifecycle itself. $stripe_product_id / $stripe_
 * price_id on `service_contract_billings` are simply observability columns,
 * backfilled from the Subscription object once Stripe reports them (see
 * fromSubscription() below) - never required to exist before a Checkout
 * Session can be created.
 *
 * No Stripe account/keys exist yet for every BLUE V1 environment: every
 * method that needs the network first checks its required key is
 * configured and throws ContractBillingGatewayNotConfiguredException
 * instead of attempting the call - this class never fabricates a
 * successful result.
 *
 * Term-end cancellation ("the subscription must never bill past the
 * Contract's own `ends_at`"): createSubscriptionCheckout() deliberately
 * never sends a `subscription_data.cancel_at` parameter - a real Stripe
 * test-mode call proved Checkout Session creation rejects it outright (400
 * `invalid_request_error` / `parameter_unknown`, "Received unknown
 * parameter: subscription_data[cancel_at]"), and the installed stripe-php
 * SDK's own type contract for `checkout.sessions.create()` confirms
 * `subscription_data` has no such field. scheduleSubscriptionCancelAt()
 * applies it afterward instead, via `Subscription::update()` (which DOES
 * accept `cancel_at`), the moment a Subscription id is first known - see
 * App\Actions\Contract\Billing\ScheduleContractBillingSubscriptionCancelAtAction.
 */
final class StripeContractBillingGateway implements ContractBillingGateway
{
    public function __construct(
        private readonly ?string $secretKey,
        private readonly ?string $webhookSecret,
    ) {}

    public function providerCode(): string
    {
        return (string) config('contract_billing.stripe_provider_code', 'STRIPE');
    }

    public function createSubscriptionCheckout(ContractBillingCheckoutData $data): ContractBillingCheckoutResult
    {
        $client = $this->client();

        try {
            $customerId = $data->stripeCustomerId;

            if ($customerId === null) {
                $customer = $client->customers->create(
                    ['email' => $data->customerEmail],
                    ['idempotency_key' => $data->customerIdempotencyKey],
                );

                $customerId = $customer->id;
            }

            $metadata = [
                'service_contract_uuid' => $data->contractUuid,
                'service_contract_billing_uuid' => $data->contractBillingUuid,
            ];

            $session = $client->checkout->sessions->create(
                array_filter([
                    'mode' => 'subscription',
                    'customer' => $customerId,
                    'line_items' => [[
                        'quantity' => 1,
                        'price_data' => [
                            'currency' => strtolower($data->currencyCode),
                            'unit_amount' => MinorUnitConverter::toMinorUnits($data->recurringAmount, $data->currencyMinorUnit),
                            'recurring' => ['interval' => $data->billingInterval === 'YEARLY' ? 'year' : 'month'],
                            'product_data' => ['name' => $data->productName],
                        ],
                    ]],
                    'subscription_data' => ['metadata' => $metadata],
                    'metadata' => $metadata,
                    'success_url' => $data->successUrl,
                    'cancel_url' => $data->cancelUrl,
                ], static fn (mixed $value): bool => $value !== null),
                ['idempotency_key' => $data->providerIdempotencyKey],
            );

            return ContractBillingCheckoutResult::created(
                stripeCustomerId: $customerId,
                checkoutSessionId: $session->id,
                checkoutUrl: $session->url,
            );
        } catch (ApiErrorException $e) {
            return self::classifyCreationFailure($e);
        }
    }

    /**
     * The DEFINITIVE_FAILURE-vs-UNKNOWN classifier for a failed Checkout
     * Session (or Customer) create call - mirrors
     * StripePaymentGateway::classifyCreationFailure() exactly, including
     * the same RateLimitException-before-InvalidRequestException ordering
     * (RateLimitException extends InvalidRequestException in stripe-php).
     */
    public static function classifyCreationFailure(ApiErrorException $e): ContractBillingCheckoutResult
    {
        return match (true) {
            $e instanceof RateLimitException, $e instanceof ApiConnectionException => ContractBillingCheckoutResult::unknown($e->getMessage()),
            $e instanceof InvalidRequestException, $e instanceof AuthenticationException => ContractBillingCheckoutResult::definitiveFailure('STRIPE_REQUEST_REJECTED', $e->getMessage()),
            ($e->getHttpStatus() ?? 0) >= 500 => ContractBillingCheckoutResult::unknown($e->getMessage()),
            default => ContractBillingCheckoutResult::definitiveFailure('STRIPE_API_ERROR', $e->getMessage()),
        };
    }

    public function cancelSubscription(string $stripeSubscriptionId): void
    {
        try {
            // Stripe's own cancel-Subscription call is itself safe to
            // repeat against an already-canceled Subscription (a no-op
            // provider-side) - exactly the idempotent/safely-retryable
            // provider behavior App\Actions\Contract\Billing\
            // RetryPendingContractBillingCancellationsAction depends on for
            // at-least-once delivery (see the interface docblock - this is
            // NEVER exactly-once, by design, since it is a network call).
            $this->client()->subscriptions->cancel($stripeSubscriptionId);
        } catch (ContractBillingGatewayNotConfiguredException|ApiErrorException $e) {
            // Best-effort side channel only - see the interface docblock.
            // Swallowed here (rather than left to escape) only because this
            // implementation has nowhere better to surface it; the caller
            // (CancelContractBillingSubscriptionAction) durably tracks the
            // attempt regardless and the retry command will try again. The
            // eventual customer.subscription.deleted webhook (or a manual
            // Stripe-side reconciliation) remains the sole authority for
            // the provider's actual final state either way.
            report($e);
        }
    }

    public function scheduleSubscriptionCancelAt(string $stripeSubscriptionId, int $cancelAtUnixTimestamp): void
    {
        try {
            // Real Stripe test-mode verification (BLUE V1 Phase 11 fix):
            // checkout.sessions.create()'s subscription_data has NO
            // cancel_at parameter - Stripe rejects it with 400
            // parameter_unknown ("Received unknown parameter:
            // subscription_data[cancel_at]"). Subscription::update() DOES
            // accept cancel_at (confirmed against the installed stripe-php
            // SDK's own type contract), and is itself safe to call
            // repeatedly with the same value - exactly the idempotent/
            // safely-retryable provider behavior
            // App\Actions\Contract\Billing\
            // RetryPendingContractBillingCancelAtSchedulingAction depends
            // on for at-least-once delivery (see the interface docblock -
            // this is NEVER exactly-once, by design).
            $this->client()->subscriptions->update($stripeSubscriptionId, ['cancel_at' => $cancelAtUnixTimestamp]);
        } catch (ContractBillingGatewayNotConfiguredException|ApiErrorException $e) {
            // Best-effort side channel only - see the interface docblock.
            // Swallowed here for the same reason cancelSubscription() does:
            // the caller (ScheduleContractBillingSubscriptionCancelAtAction)
            // has nothing state-changing to roll back, and the retry
            // command will try again for as long as `cancel_at` stays
            // unconfirmed locally.
            report($e);
        }
    }

    public function verifyWebhook(string $rawBody, array $signatureHeaders): VerifiedWebhookResult
    {
        if (empty($this->webhookSecret)) {
            return VerifiedWebhookResult::invalid('Webhook secret not configured.');
        }

        $signature = $signatureHeaders['Stripe-Signature'] ?? $signatureHeaders['stripe-signature'] ?? null;

        if ($signature === null) {
            return VerifiedWebhookResult::invalid('Missing Stripe-Signature header.');
        }

        try {
            $event = StripeWebhook::constructEvent($rawBody, $signature, $this->webhookSecret);

            return VerifiedWebhookResult::valid($event);
        } catch (UnexpectedValueException|SignatureVerificationException) {
            return VerifiedWebhookResult::invalid('Invalid Stripe webhook signature or payload.');
        }
    }

    public function parseWebhook(mixed $verifiedProviderEvent): NormalizedContractBillingEvent
    {
        if (! $verifiedProviderEvent instanceof StripeEvent) {
            throw new UnexpectedValueException('Expected a verified Stripe\\Event instance.');
        }

        $event = $verifiedProviderEvent;
        $object = $event->data->object ?? null;

        return match (true) {
            $object instanceof StripeCheckoutSession => $this->fromCheckoutSession($event, $object),
            $object instanceof StripeSubscription => $this->fromSubscription($event, $object),
            $object instanceof StripeInvoice => $this->fromInvoice($event, $object),
            default => $this->unrecognized($event),
        };
    }

    private function fromCheckoutSession(StripeEvent $event, StripeCheckoutSession $session): NormalizedContractBillingEvent
    {
        return new NormalizedContractBillingEvent(
            providerEventId: $event->id,
            eventType: $event->type,
            contractBillingUuid: is_string($session->metadata['service_contract_billing_uuid'] ?? null) ? $session->metadata['service_contract_billing_uuid'] : null,
            stripeSubscriptionId: is_string($session->subscription ?? null) ? $session->subscription : null,
            stripeCustomerId: is_string($session->customer ?? null) ? $session->customer : null,
            stripeCheckoutSessionId: $session->id,
            stripePriceId: null,
            stripeProductId: null,
            subscriptionStatus: null,
            cancelAtPeriodEnd: null,
            invoicePaid: null,
            currentPeriodStart: null,
            currentPeriodEnd: null,
            cancelAt: null,
            canceledAt: null,
        );
    }

    /**
     * Real Stripe test-mode API version note (this account defaults to
     * `2026-07-29.dahlia` or later - confirmed against the installed
     * stripe-php SDK's own type contract): `current_period_start` /
     * `current_period_end` no longer exist on the Subscription object
     * itself - Stripe moved them onto each SubscriptionItem (to support
     * multiple prices per subscription with independently-billed periods),
     * so they are read from the same first line item this method already
     * reads price/product from, never from `$subscription` directly.
     */
    private function fromSubscription(StripeEvent $event, StripeSubscription $subscription): NormalizedContractBillingEvent
    {
        $item = $subscription->items->data[0] ?? null;
        $price = $item->price ?? null;

        return new NormalizedContractBillingEvent(
            providerEventId: $event->id,
            eventType: $event->type,
            contractBillingUuid: is_string($subscription->metadata['service_contract_billing_uuid'] ?? null) ? $subscription->metadata['service_contract_billing_uuid'] : null,
            stripeSubscriptionId: $subscription->id,
            stripeCustomerId: is_string($subscription->customer ?? null) ? $subscription->customer : null,
            stripeCheckoutSessionId: null,
            stripePriceId: is_string($price->id ?? null) ? $price->id : null,
            stripeProductId: is_string($price->product ?? null) ? $price->product : null,
            subscriptionStatus: $subscription->status,
            cancelAtPeriodEnd: (bool) ($subscription->cancel_at_period_end ?? false),
            invoicePaid: null,
            currentPeriodStart: self::tsToDatetime($item->current_period_start ?? null),
            currentPeriodEnd: self::tsToDatetime($item->current_period_end ?? null),
            cancelAt: self::tsToDatetime($subscription->cancel_at ?? null),
            canceledAt: self::tsToDatetime($subscription->canceled_at ?? null),
        );
    }

    /**
     * Real Stripe test-mode API version note (same account/SDK version as
     * fromSubscription() above): the Invoice object no longer has a
     * top-level `subscription` property at all - it moved to
     * `$invoice->parent->subscription_details->subscription` (confirmed
     * against the installed stripe-php SDK's own type contract, and
     * discovered live: a real invoice.paid webhook's `$invoice->subscription`
     * read as null, which made resolveBilling() permanently unable to match
     * ANY invoice event to a local billing row - not merely an
     * out-of-order-delivery timing gap, since even a perfectly-ordered
     * invoice.paid would never have resolved on this API version).
     * `subscription_details` conveniently also carries the same
     * `service_contract_billing_uuid` metadata the Subscription/Checkout
     * Session already do, so this is populated as a second, independent
     * resolveBilling() match path for invoice events specifically -
     * previously always null - making out-of-order delivery (the
     * `contracts:retry-pending-cancel-at-scheduling`-style webhook-order
     * risk this whole domain is otherwise built to tolerate) strictly safer
     * for invoices too. `$invoice->paid` (bool) also no longer exists on
     * this API version (superseded by `$invoice->status`) - `invoicePaid`
     * is derived from `status` instead, even though nothing in
     * App\Actions\Contract\Billing\ProcessContractBillingWebhookAction
     * currently reads this field (kept structurally honest regardless, for
     * whichever code reads it next).
     *
     * A THIRD real, live-discovered API version bug in the same method:
     * `$invoice->period_start`/`period_end` are no longer a meaningful
     * service-period range on this API version either - a real invoice.paid
     * event observed them EQUAL to each other (an anchor instant, not a
     * range), which fails `chk_service_contract_billings_period`
     * (`current_period_end > current_period_start`) the moment this event
     * is processed. The SDK's own docblock for these properties says so
     * directly ("Use the line item period to get the service period for
     * each price") - the real per-price billing period range now lives on
     * the first line item, `$invoice->lines->data[0]->period->{start,end}`,
     * exactly like Subscription's period fields moved to SubscriptionItem
     * above.
     */
    private function fromInvoice(StripeEvent $event, StripeInvoice $invoice): NormalizedContractBillingEvent
    {
        $subscriptionDetails = $invoice->parent->subscription_details ?? null;
        $subscriptionRef = $subscriptionDetails->subscription ?? null;
        $metadata = $subscriptionDetails->metadata ?? null;
        $linePeriod = $invoice->lines->data[0]->period ?? null;

        return new NormalizedContractBillingEvent(
            providerEventId: $event->id,
            eventType: $event->type,
            contractBillingUuid: is_string($metadata['service_contract_billing_uuid'] ?? null) ? $metadata['service_contract_billing_uuid'] : null,
            stripeSubscriptionId: is_string($subscriptionRef) ? $subscriptionRef : null,
            stripeCustomerId: is_string($invoice->customer ?? null) ? $invoice->customer : null,
            stripeCheckoutSessionId: null,
            stripePriceId: null,
            stripeProductId: null,
            subscriptionStatus: null,
            cancelAtPeriodEnd: null,
            invoicePaid: $event->type === 'invoice.paid' ? ($invoice->status ?? null) === 'paid' : ($event->type === 'invoice.payment_failed' ? false : null),
            currentPeriodStart: self::tsToDatetime($linePeriod->start ?? null),
            currentPeriodEnd: self::tsToDatetime($linePeriod->end ?? null),
            cancelAt: null,
            canceledAt: null,
        );
    }

    private function unrecognized(StripeEvent $event): NormalizedContractBillingEvent
    {
        return new NormalizedContractBillingEvent(
            providerEventId: $event->id,
            eventType: $event->type,
            contractBillingUuid: null,
            stripeSubscriptionId: null,
            stripeCustomerId: null,
            stripeCheckoutSessionId: null,
            stripePriceId: null,
            stripeProductId: null,
            subscriptionStatus: null,
            cancelAtPeriodEnd: null,
            invoicePaid: null,
            currentPeriodStart: null,
            currentPeriodEnd: null,
            cancelAt: null,
            canceledAt: null,
        );
    }

    private static function tsToDatetime(?int $unixTimestamp): ?string
    {
        if ($unixTimestamp === null) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $unixTimestamp);
    }

    private function client(): StripeClient
    {
        if (empty($this->secretKey)) {
            throw new ContractBillingGatewayNotConfiguredException(
                'Stripe secret key is not configured. Set STRIPE_SECRET_KEY once a Stripe account exists.'
            );
        }

        return new StripeClient($this->secretKey);
    }
}
