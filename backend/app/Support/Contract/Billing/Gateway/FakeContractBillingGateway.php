<?php

namespace App\Support\Contract\Billing\Gateway;

use App\Support\Payment\Gateway\VerifiedWebhookResult;
use UnexpectedValueException;

/**
 * Deterministic ContractBillingGateway test double - the only
 * ContractBillingGateway implementation ever bound while running under the
 * "testing" environment (see App\Providers\ContractBillingServiceProvider).
 * Never makes a network call of any kind, so the automated test suite can
 * never reach the real Stripe API - mirrors App\Support\Payment\Gateway\
 * FakePaymentGateway's design exactly, including its own separate fake HMAC
 * "signature" scheme (self::sign()) with its OWN secret, deliberately
 * different from FakePaymentGateway::FAKE_SECRET, so a test payload signed
 * for one fake gateway can never be mistaken for a valid signature against
 * the other - exactly mirroring how the two real Stripe webhook endpoints
 * each have their own signing secret in production.
 *
 * providerCode() deliberately returns "FAKE", not "STRIPE" - fake test rows
 * must never be mistakable for real Stripe data if a database row is ever
 * inspected directly.
 */
final class FakeContractBillingGateway implements ContractBillingGateway
{
    private const FAKE_SECRET = 'fake-contract-billing-gateway-test-secret-not-real';

    /** @var array<int, ContractBillingCheckoutResult> */
    private array $queuedCheckouts = [];

    private ?ContractBillingCheckoutResult $stickyCheckout = null;

    /** @var array<int, ContractBillingCheckoutData> */
    public array $createCheckoutCalls = [];

    /** @var array<int, string> */
    public array $cancelSubscriptionCalls = [];

    /** @var array<int, \Throwable> */
    private array $queuedCancellationFailures = [];

    /** @var array<int, array{0: string, 1: int}> */
    public array $scheduleSubscriptionCancelAtCalls = [];

    /** @var array<int, \Throwable> */
    private array $queuedScheduleCancelAtFailures = [];

    public function providerCode(): string
    {
        return 'FAKE';
    }

    public function queueNextCheckout(ContractBillingCheckoutResult $result): void
    {
        $this->queuedCheckouts[] = $result;
    }

    public function alwaysReturnCheckout(ContractBillingCheckoutResult $result): void
    {
        $this->stickyCheckout = $result;
    }

    public function createSubscriptionCheckout(ContractBillingCheckoutData $data): ContractBillingCheckoutResult
    {
        $this->createCheckoutCalls[] = $data;

        if ($this->queuedCheckouts !== []) {
            return array_shift($this->queuedCheckouts);
        }

        if ($this->stickyCheckout !== null) {
            return $this->stickyCheckout;
        }

        // Deterministic, provider-idempotency-key-derived default: the same
        // logical retry (same providerIdempotencyKey) always yields the
        // same fake session/customer references, mirroring real Stripe
        // idempotent Checkout Session reuse without any internal
        // call-count state.
        return ContractBillingCheckoutResult::created(
            stripeCustomerId: $data->stripeCustomerId ?? 'fake_cus_'.$data->customerIdempotencyKey,
            checkoutSessionId: 'fake_cs_'.$data->providerIdempotencyKey,
            checkoutUrl: 'https://fake-checkout.test/c/pay/fake_cs_'.$data->providerIdempotencyKey,
        );
    }

    /**
     * Queues a simulated provider-delivery failure (network error, timeout,
     * outage - BLUE V1 Phase 11 durable-retry hardening) for the next
     * cancelSubscription() call. The call is still recorded in
     * $cancelSubscriptionCalls (an attempt genuinely reached the provider
     * boundary before failing) but then throws $exception instead of
     * returning - exercising App\Actions\Contract\Billing\
     * CancelContractBillingSubscriptionAction's own catch/record/never-lose-
     * the-request behavior exactly like a real Stripe network failure would.
     * One-shot: only the NEXT call fails, so a test can assert a later retry
     * (e.g. via contracts:retry-pending-billing-cancellations) genuinely
     * succeeds once this queue is exhausted.
     */
    public function queueCancellationFailure(\Throwable $exception): void
    {
        $this->queuedCancellationFailures[] = $exception;
    }

    public function cancelSubscription(string $stripeSubscriptionId): void
    {
        $this->cancelSubscriptionCalls[] = $stripeSubscriptionId;

        if ($this->queuedCancellationFailures !== []) {
            throw array_shift($this->queuedCancellationFailures);
        }
    }

    /**
     * Queues a simulated provider-delivery failure for the next
     * scheduleSubscriptionCancelAt() call - same one-shot semantics as
     * queueCancellationFailure() above, for the term-end cancel_at
     * scheduling operation (BLUE V1 Phase 11 real-Stripe fix - see
     * App\Actions\Contract\Billing\ScheduleContractBillingSubscriptionCancelAtAction).
     */
    public function queueScheduleCancelAtFailure(\Throwable $exception): void
    {
        $this->queuedScheduleCancelAtFailures[] = $exception;
    }

    public function scheduleSubscriptionCancelAt(string $stripeSubscriptionId, int $cancelAtUnixTimestamp): void
    {
        $this->scheduleSubscriptionCancelAtCalls[] = [$stripeSubscriptionId, $cancelAtUnixTimestamp];

        if ($this->queuedScheduleCancelAtFailures !== []) {
            throw array_shift($this->queuedScheduleCancelAtFailures);
        }
    }

    public function verifyWebhook(string $rawBody, array $signatureHeaders): VerifiedWebhookResult
    {
        $signature = $signatureHeaders['X-Fake-Signature'] ?? $signatureHeaders['x-fake-signature'] ?? null;

        if ($signature === null || ! hash_equals(self::sign($rawBody), $signature)) {
            return VerifiedWebhookResult::invalid('Invalid fake webhook signature.');
        }

        $decoded = json_decode($rawBody, true);

        if (! is_array($decoded)) {
            return VerifiedWebhookResult::invalid('Malformed fake webhook payload.');
        }

        return VerifiedWebhookResult::valid($decoded);
    }

    public function parseWebhook(mixed $verifiedProviderEvent): NormalizedContractBillingEvent
    {
        if (! is_array($verifiedProviderEvent)) {
            throw new UnexpectedValueException('Expected a decoded fake webhook payload array.');
        }

        $event = $verifiedProviderEvent;

        return new NormalizedContractBillingEvent(
            providerEventId: (string) $event['event_id'],
            eventType: (string) $event['event_type'],
            contractBillingUuid: $event['contract_billing_uuid'] ?? null,
            stripeSubscriptionId: $event['stripe_subscription_id'] ?? null,
            stripeCustomerId: $event['stripe_customer_id'] ?? null,
            stripeCheckoutSessionId: $event['stripe_checkout_session_id'] ?? null,
            stripePriceId: $event['stripe_price_id'] ?? null,
            stripeProductId: $event['stripe_product_id'] ?? null,
            subscriptionStatus: $event['subscription_status'] ?? null,
            cancelAtPeriodEnd: array_key_exists('cancel_at_period_end', $event) ? (bool) $event['cancel_at_period_end'] : null,
            invoicePaid: array_key_exists('invoice_paid', $event) ? (bool) $event['invoice_paid'] : null,
            currentPeriodStart: $event['current_period_start'] ?? null,
            currentPeriodEnd: $event['current_period_end'] ?? null,
            cancelAt: $event['cancel_at'] ?? null,
            canceledAt: $event['canceled_at'] ?? null,
        );
    }

    /**
     * Deterministic fake HMAC "signature" of a raw webhook body - test
     * fixtures call this to build a request that verifyWebhook() accepts,
     * and can flip a single character to produce a request it rejects
     * (invalid-signature tests).
     */
    public static function sign(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, self::FAKE_SECRET);
    }
}
