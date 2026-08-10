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
        } catch (InvalidRequestException|AuthenticationException $e) {
            // Stripe never creates the object when the request itself is
            // rejected (bad params, bad/revoked key) - safe to compensate.
            return PaymentCreationResult::definitiveFailure('STRIPE_REQUEST_REJECTED', $e->getMessage());
        } catch (ApiConnectionException|RateLimitException $e) {
            return PaymentCreationResult::unknown($e->getMessage());
        } catch (ApiErrorException $e) {
            $httpStatus = $e->getHttpStatus();

            if ($httpStatus !== null && $httpStatus >= 500) {
                return PaymentCreationResult::unknown($e->getMessage());
            }

            return PaymentCreationResult::definitiveFailure('STRIPE_API_ERROR', $e->getMessage());
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

    public function parseWebhook(mixed $verifiedProviderEvent): NormalizedPaymentEvent
    {
        if (! $verifiedProviderEvent instanceof StripeEvent) {
            throw new UnexpectedValueException('Expected a verified Stripe\\Event instance.');
        }

        $event = $verifiedProviderEvent;
        $object = $event->data->object ?? null;

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
