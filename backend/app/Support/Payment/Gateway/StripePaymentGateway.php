<?php

namespace App\Support\Payment\Gateway;

use Illuminate\Support\Facades\DB;
use Stripe\Event as StripeEvent;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Refund as StripeRefund;
use Stripe\StripeClient;
use Stripe\Webhook as StripeWebhook;
use UnexpectedValueException;

/**
 * The BLUE V1 Phase 6A approved payment provider adapter, targeting Stripe
 * PaymentIntent semantics (docs/api-contracts/payments-v1.md "PaymentIntent
 * direction"). No Stripe SDK object or array ever leaves this class -
 * every method returns one of the typed DTOs in this namespace, matching
 * the PaymentGateway contract exactly.
 *
 * No Stripe account/keys exist yet for BLUE V1: every method that needs the
 * network first checks its required key is configured and throws
 * PaymentGatewayNotConfiguredException instead of attempting the call. This
 * class never fabricates a successful result - an unconfigured or failing
 * Stripe integration always surfaces as a safe failure/unknown outcome,
 * never as PaymentCreationOutcome::CREATED or NormalizedPaymentOutcome::
 * SUCCEEDED.
 */
final class StripePaymentGateway implements PaymentGateway
{
    public function __construct(
        private readonly ?string $secretKey,
        private readonly ?string $webhookSecret,
    ) {}

    public function providerCode(): string
    {
        return (string) config('payment.stripe_provider_code', 'STRIPE');
    }

    public function createPayment(PaymentCreationData $data): PaymentCreationResult
    {
        $client = $this->client();
        $minorUnits = MinorUnitConverter::toMinorUnits($data->amount, $this->minorUnitFor($data->currencyCode));

        try {
            $intent = $client->paymentIntents->create(
                [
                    'amount' => $minorUnits,
                    'currency' => strtolower($data->currencyCode),
                    'automatic_payment_methods' => ['enabled' => true],
                    'description' => 'BLUE payment '.$data->checkoutReference,
                    'metadata' => [
                        'checkout_reference' => $data->checkoutReference,
                        'payment_attempt_uuid' => $data->paymentAttemptUuid,
                    ],
                ],
                ['idempotency_key' => $data->providerIdempotencyKey],
            );

            return PaymentCreationResult::created(
                providerSessionReference: $intent->id,
                providerStatusCode: $intent->status,
                clientSecret: $intent->client_secret,
            );
        } catch (ApiErrorException $e) {
            return self::classifyCreationFailure($e);
        }
    }

    /**
     * The one centralized DEFINITIVE_FAILURE-vs-UNKNOWN classifier for a
     * failed PaymentIntent create call - public/static so it is directly
     * unit-testable against real Stripe SDK exception instances (built from
     * a mocked HTTP response, never a live call) without going through a
     * network-dependent createPayment() call. See docs/api-contracts/
     * payments-v1.md "Stripe error classification".
     *
     * Ordering matters: `RateLimitException extends InvalidRequestException`
     * in stripe-php, so a naive `catch (InvalidRequestException $e)` (or an
     * instanceof check in that order) would silently misclassify a
     * recoverable 429 as a definitive rejection. Rate limiting and
     * connection failures are checked first, before the broader
     * InvalidRequestException/AuthenticationException case, specifically to
     * avoid that trap.
     */
    public static function classifyCreationFailure(ApiErrorException $e): PaymentCreationResult
    {
        return match (true) {
            // Ambiguous/retryable: no proof the PaymentIntent was or was
            // not created. Never a compensation trigger.
            $e instanceof RateLimitException, $e instanceof ApiConnectionException => PaymentCreationResult::unknown($e->getMessage()),

            // Stripe never creates the object when the request itself is
            // rejected (bad params, bad/revoked key) - safe to compensate.
            $e instanceof InvalidRequestException, $e instanceof AuthenticationException => PaymentCreationResult::definitiveFailure('STRIPE_REQUEST_REJECTED', $e->getMessage()),

            // Any other Stripe API error: a 5xx means Stripe's own server
            // failed and the outcome is ambiguous; anything else (e.g. a
            // synchronous 4xx this gateway doesn't specifically recognize)
            // is a safe-to-compensate rejection.
            ($e->getHttpStatus() ?? 0) >= 500 => PaymentCreationResult::unknown($e->getMessage()),
            default => PaymentCreationResult::definitiveFailure('STRIPE_API_ERROR', $e->getMessage()),
        };
    }

    /**
     * Issues (or safely resumes) exactly one Stripe refund against the
     * original PaymentIntent - see RefundCreationData's docblock for why
     * `payment_intent` (never a Charge id) is the identifier used. The
     * `$data->providerIdempotencyKey` is booking_refunds.idempotency_key,
     * generated once and persisted at obligation-creation time - a retry
     * (network timeout, process crash, or the recovery Artisan command)
     * always passes the SAME key, so Stripe returns the SAME refund object
     * instead of creating a second one.
     */
    public function refundPayment(RefundCreationData $data): RefundCreationResult
    {
        $client = $this->client();
        $minorUnits = MinorUnitConverter::toMinorUnits($data->amount, $data->currencyMinorUnit);

        try {
            $refund = $client->refunds->create(
                [
                    'payment_intent' => $data->providerPaymentReference,
                    'amount' => $minorUnits,
                    'metadata' => [
                        'booking_refund_uuid' => $data->bookingRefundUuid,
                    ],
                ],
                ['idempotency_key' => $data->providerIdempotencyKey],
            );

            return RefundCreationResult::created(
                providerRefundReference: $refund->id,
                providerStatusCode: $refund->status,
            );
        } catch (ApiErrorException $e) {
            return self::classifyRefundFailure($e);
        }
    }

    /**
     * The refund-side counterpart to classifyCreationFailure() - identical
     * classification rules (see that method's docblock for the RateLimit/
     * ApiConnection-before-InvalidRequest ordering rationale), just
     * returning a RefundCreationResult instead. Kept as a separate method
     * (not a shared generic) because the two call sites return distinct
     * typed DTOs the rest of the codebase depends on never seeing a
     * mismatched shape.
     */
    public static function classifyRefundFailure(ApiErrorException $e): RefundCreationResult
    {
        return match (true) {
            $e instanceof RateLimitException, $e instanceof ApiConnectionException => RefundCreationResult::unknown($e->getMessage()),
            $e instanceof InvalidRequestException, $e instanceof AuthenticationException => RefundCreationResult::definitiveFailure('STRIPE_REQUEST_REJECTED', $e->getMessage()),
            ($e->getHttpStatus() ?? 0) >= 500 => RefundCreationResult::unknown($e->getMessage()),
            default => RefundCreationResult::definitiveFailure('STRIPE_API_ERROR', $e->getMessage()),
        };
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

    public function parseWebhook(mixed $verifiedProviderEvent): NormalizedPaymentEvent|NormalizedRefundEvent
    {
        if (! $verifiedProviderEvent instanceof StripeEvent) {
            throw new UnexpectedValueException('Expected a verified Stripe\\Event instance.');
        }

        $event = $verifiedProviderEvent;
        $object = $event->data->object ?? null;

        /*
         * A refund-lifecycle event (refund.created / refund.updated /
         * refund.failed) always carries a \Stripe\Refund object regardless
         * of exact event type - checking the object's own class, not the
         * event type string, is the same "trust the typed SDK object"
         * principle the PaymentIntent branch below already uses, and keeps
         * this gateway correct even if Stripe adds another refund event
         * type later.
         */
        if ($object instanceof StripeRefund) {
            return new NormalizedRefundEvent(
                providerEventId: $event->id,
                eventType: $event->type,
                providerRefundReference: $object->id,
                providerPaymentReference: is_string($object->payment_intent ?? null) ? $object->payment_intent : null,
                status: (string) $object->status,
                amount: $object->amount === null ? null : MinorUnitConverter::toDecimalString((int) $object->amount, $this->minorUnitFor(strtoupper($object->currency))),
                currencyCode: $object->currency === null ? null : strtoupper($object->currency),
                failureCode: is_string($object->failure_reason ?? null) ? $object->failure_reason : null,
                failureMessage: null,
            );
        }

        if (! $object instanceof PaymentIntent) {
            return new NormalizedPaymentEvent(
                providerEventId: $event->id,
                eventType: $event->type,
                providerSessionReference: null,
                providerTransactionReference: null,
                checkoutReference: null,
                outcome: NormalizedPaymentOutcome::UNRECOGNIZED,
                amount: null,
                currencyCode: null,
                providerStatusCode: null,
                paymentMethodType: null,
                failureCode: null,
                failureMessage: null,
            );
        }

        return new NormalizedPaymentEvent(
            providerEventId: $event->id,
            eventType: $event->type,
            providerSessionReference: $object->id,
            providerTransactionReference: is_string($object->latest_charge ?? null) ? $object->latest_charge : $object->id,
            checkoutReference: is_string($object->metadata['checkout_reference'] ?? null) ? $object->metadata['checkout_reference'] : null,
            outcome: self::normalizeOutcome($object->status),
            amount: MinorUnitConverter::toDecimalString((int) $object->amount, $this->minorUnitFor(strtoupper($object->currency))),
            currencyCode: strtoupper($object->currency),
            providerStatusCode: $object->status,
            paymentMethodType: $this->normalizePaymentMethodType($object),
            failureCode: $object->last_payment_error->code ?? null,
            failureMessage: $object->last_payment_error->message ?? null,
        );
    }

    public function fetchPayment(string $providerReference): ProviderPaymentState
    {
        $client = $this->client();

        $intent = $client->paymentIntents->retrieve($providerReference);

        return new ProviderPaymentState(
            providerSessionReference: $intent->id,
            outcome: self::normalizeOutcome($intent->status),
            amount: MinorUnitConverter::toDecimalString((int) $intent->amount, $this->minorUnitFor(strtoupper($intent->currency))),
            currencyCode: strtoupper($intent->currency),
            providerStatusCode: $intent->status,
            paymentMethodType: $this->normalizePaymentMethodType($intent),
            providerTransactionReference: is_string($intent->latest_charge ?? null) ? $intent->latest_charge : $intent->id,
            failureCode: $intent->last_payment_error->code ?? null,
            failureMessage: $intent->last_payment_error->message ?? null,
        );
    }

    /**
     * Pure Stripe PaymentIntent.status -> NormalizedPaymentOutcome mapping,
     * public/static so it is directly unit-testable without any Stripe API
     * access - see docs/api-contracts/payments-v1.md "Stripe -> BLUE
     * normalization principle" and tests/Unit/Payment/StripeStatusMappingTest.
     */
    public static function normalizeOutcome(string $stripeStatus): NormalizedPaymentOutcome
    {
        return match ($stripeStatus) {
            'succeeded' => NormalizedPaymentOutcome::SUCCEEDED,
            'canceled' => NormalizedPaymentOutcome::CANCELED,
            'requires_payment_method', 'requires_confirmation', 'requires_action', 'processing' => NormalizedPaymentOutcome::NON_TERMINAL,
            'requires_capture' => NormalizedPaymentOutcome::UNEXPECTED_NON_TERMINAL,
            default => NormalizedPaymentOutcome::UNRECOGNIZED,
        };
    }

    /**
     * Stripe webhooks do not expand `payment_method` to an object by
     * default, so wallet-level detection (Apple Pay vs. plain card) is not
     * always available without an extra API call this gateway deliberately
     * does not make. Falls back to the declared `payment_method_types[0]`
     * (e.g. "card") when only an unexpanded id/string is present - still a
     * safe, provider-returned classification, never a client-declared one.
     * See docs/api-contracts/payments-v1.md "Apple Pay readiness".
     */
    private function normalizePaymentMethodType(PaymentIntent $intent): ?string
    {
        $paymentMethod = $intent->payment_method ?? null;

        if (is_object($paymentMethod)) {
            $walletType = $paymentMethod->card->wallet->type ?? null;

            if (is_string($walletType) && $walletType !== '') {
                return $walletType;
            }

            if (isset($paymentMethod->type) && is_string($paymentMethod->type)) {
                return $paymentMethod->type;
            }
        }

        $types = $intent->payment_method_types ?? [];

        return is_array($types) && $types !== [] ? (string) $types[0] : null;
    }

    private function minorUnitFor(string $currencyCode): int
    {
        return (int) (DB::table('currencies')->where('code', $currencyCode)->value('minor_unit') ?? 2);
    }

    private function client(): StripeClient
    {
        if (empty($this->secretKey)) {
            throw new PaymentGatewayNotConfiguredException(
                'Stripe secret key is not configured. Set STRIPE_SECRET_KEY once a Stripe account exists.'
            );
        }

        return new StripeClient($this->secretKey);
    }
}
