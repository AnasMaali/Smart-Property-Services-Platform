<?php

namespace App\Support\Payment\Gateway;

/**
 * The one safe, provider-neutral shape PaymentGateway::parseWebhook()
 * produces from an already-verified provider event. This is the only form
 * in which webhook data reaches App\Actions\Payment\ProcessPaymentWebhookAction
 * - no provider SDK object or raw payload crosses this boundary. Amounts
 * are decimal strings (converted back from the provider's minor-unit
 * integer), matching payment_attempts.requested_amount/confirmed_amount.
 */
final readonly class NormalizedPaymentEvent
{
    public function __construct(
        public string $providerEventId,
        public string $eventType,
        public ?string $providerSessionReference,
        public ?string $providerTransactionReference,
        public ?string $checkoutReference,
        public NormalizedPaymentOutcome $outcome,
        public ?string $amount,
        public ?string $currencyCode,
        public ?string $providerStatusCode,
        public ?string $paymentMethodType,
        public ?string $failureCode,
        public ?string $failureMessage,
    ) {}
}
