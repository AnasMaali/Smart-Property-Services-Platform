<?php

namespace App\Support\Payment\Gateway;

/**
 * The one provider-neutral boundary between BLUE's Payment domain and any
 * concrete payment provider. Checkout, Cart, PricingEngine, and the future
 * Booking domain never depend on this interface or any of its
 * implementations - only App\Actions\Payment\* does. No provider-specific
 * array shape, SDK object, or exception type may leak past an
 * implementation of this interface; every method returns one of the typed
 * DTOs in this namespace.
 */
interface PaymentGateway
{
    /**
     * The exact payment_attempts.provider_code / payment_webhook_events.
     * provider_code value this implementation writes (e.g. "STRIPE").
     */
    public function providerCode(): string;

    /**
     * Starts (or safely resumes) one provider-side payment for the given
     * BLUE payment attempt. Must be called with a stable, attempt-derived
     * provider idempotency key so a retry with the same PaymentCreationData
     * never creates a second provider-side charge object - see
     * PaymentCreationData::$providerIdempotencyKey.
     *
     * Must never throw for an ordinary declined/failed payment outcome -
     * that is still PaymentCreationOutcome::CREATED (the provider object
     * exists; the customer's card was simply declined). Only a definitive
     * proof that no provider object was created (PaymentCreationOutcome::
     * DEFINITIVE_FAILURE) or a genuinely ambiguous network/timeout outcome
     * (PaymentCreationOutcome::UNKNOWN) escape the CREATED case.
     */
    public function createPayment(PaymentCreationData $data): PaymentCreationResult;

    /**
     * Authenticity check ONLY - must be called with the raw, unmodified
     * request body (never a re-encoded/normalized JSON string) and the
     * provider's signature header(s). Must fail safely (return an invalid
     * result, never throw and never treat as valid) when the provider
     * webhook secret is not configured.
     *
     * @param  array<string, string>  $signatureHeaders
     */
    public function verifyWebhook(string $rawBody, array $signatureHeaders): VerifiedWebhookResult;

    /**
     * Normalizes an already-verified provider event
     * (VerifiedWebhookResult::$providerEvent) into the one safe,
     * provider-neutral shape the webhook Action consumes. Must never be
     * called with an unverified payload.
     */
    public function parseWebhook(mixed $verifiedProviderEvent): NormalizedPaymentEvent;

    /**
     * Reconciliation/recovery path: fetches the provider's current view of
     * a payment by the reference stored on the local payment attempt
     * (payment_attempts.provider_session_reference), independent of any
     * webhook delivery. Used when a create-payment or webhook outcome is
     * UNKNOWN and the provider's own truth must be consulted directly.
     */
    public function fetchPayment(string $providerReference): ProviderPaymentState;
}
