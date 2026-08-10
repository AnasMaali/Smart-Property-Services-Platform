<?php

namespace App\Support\Payment\Gateway;

use UnexpectedValueException;

/**
 * Deterministic PaymentGateway test double - the only PaymentGateway
 * implementation ever bound while running under the "testing" environment
 * (see App\Providers\PaymentServiceProvider). Never bound in any other
 * environment, and never makes a network call of any kind, so the
 * automated test suite can never reach the real Stripe API.
 *
 * Uses its own fake, deterministic HMAC "signature" scheme (self::sign())
 * instead of Stripe's real signing algorithm - tests exercise the full
 * verify -> parse -> ledger -> state-machine pipeline exactly as production
 * does, just against a fake wire format both this class and test fixtures
 * agree on (a plain associative array, not a Stripe SDK object), which is
 * exactly what keeps StripePaymentGateway's real Stripe::Event handling
 * isolated to that one class.
 *
 * providerCode() deliberately returns "FAKE", not "STRIPE" - fake test
 * rows must never be mistakable for real Stripe data if a database row is
 * ever inspected directly.
 */
final class FakePaymentGateway implements PaymentGateway
{
    private const FAKE_SECRET = 'fake-payment-gateway-test-secret-not-real';

    /** @var array<int, PaymentCreationResult> */
    private array $queuedCreations = [];

    private ?PaymentCreationResult $stickyCreation = null;

    /** @var array<int, ProviderPaymentState> */
    private array $queuedFetches = [];

    private ?ProviderPaymentState $stickyFetch = null;

    /** @var array<int, PaymentCreationData> */
    public array $createPaymentCalls = [];

    /** @var array<int, string> */
    public array $fetchPaymentCalls = [];

    public function providerCode(): string
    {
        return 'FAKE';
    }

    public function queueNextCreation(PaymentCreationResult $result): void
    {
        $this->queuedCreations[] = $result;
    }

    public function alwaysReturnCreation(PaymentCreationResult $result): void
    {
        $this->stickyCreation = $result;
    }

    public function queueNextFetch(ProviderPaymentState $state): void
    {
        $this->queuedFetches[] = $state;
    }

    public function alwaysReturnFetch(ProviderPaymentState $state): void
    {
        $this->stickyFetch = $state;
    }

    public function createPayment(PaymentCreationData $data): PaymentCreationResult
    {
        $this->createPaymentCalls[] = $data;

        if ($this->queuedCreations !== []) {
            return array_shift($this->queuedCreations);
        }

        if ($this->stickyCreation !== null) {
            return $this->stickyCreation;
        }

        // Deterministic, provider-idempotency-key-derived default: the same
        // logical retry (same providerIdempotencyKey) always yields the
        // same fake session reference, mirroring real Stripe idempotent
        // PaymentIntent reuse without any internal call-count state.
        return PaymentCreationResult::created(
            providerSessionReference: 'fake_pi_'.$data->providerIdempotencyKey,
            providerStatusCode: 'requires_payment_method',
            clientSecret: 'fake_pi_'.$data->providerIdempotencyKey.'_secret',
        );
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

    public function parseWebhook(mixed $verifiedProviderEvent): NormalizedPaymentEvent
    {
        if (! is_array($verifiedProviderEvent)) {
            throw new UnexpectedValueException('Expected a decoded fake webhook payload array.');
        }

        $event = $verifiedProviderEvent;

        return new NormalizedPaymentEvent(
            providerEventId: (string) $event['event_id'],
            eventType: (string) $event['event_type'],
            providerSessionReference: $event['provider_session_reference'] ?? null,
            providerTransactionReference: $event['provider_transaction_reference'] ?? null,
            checkoutReference: $event['checkout_reference'] ?? null,
            outcome: NormalizedPaymentOutcome::from($event['outcome']),
            amount: $event['amount'] ?? null,
            currencyCode: $event['currency'] ?? null,
            providerStatusCode: $event['provider_status_code'] ?? null,
            paymentMethodType: $event['payment_method_type'] ?? null,
            failureCode: $event['failure_code'] ?? null,
            failureMessage: $event['failure_message'] ?? null,
        );
    }

    public function fetchPayment(string $providerReference): ProviderPaymentState
    {
        $this->fetchPaymentCalls[] = $providerReference;

        if ($this->queuedFetches !== []) {
            return array_shift($this->queuedFetches);
        }

        if ($this->stickyFetch !== null) {
            return $this->stickyFetch;
        }

        return new ProviderPaymentState(
            providerSessionReference: $providerReference,
            outcome: NormalizedPaymentOutcome::NON_TERMINAL,
            amount: null,
            currencyCode: null,
            providerStatusCode: 'requires_payment_method',
            paymentMethodType: null,
            providerTransactionReference: null,
            failureCode: null,
            failureMessage: null,
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
