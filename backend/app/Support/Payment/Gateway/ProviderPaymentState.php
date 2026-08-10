<?php

namespace App\Support\Payment\Gateway;

/**
 * The one safe, provider-neutral shape PaymentGateway::fetchPayment()
 * returns - the reconciliation/recovery path used when a create-payment or
 * webhook outcome was UNKNOWN and the provider's own current truth must be
 * consulted directly, independent of any webhook delivery.
 */
final readonly class ProviderPaymentState
{
    public function __construct(
        public string $providerSessionReference,
        public NormalizedPaymentOutcome $outcome,
        public ?string $amount,
        public ?string $currencyCode,
        public ?string $providerStatusCode,
        public ?string $paymentMethodType,
        public ?string $providerTransactionReference,
        public ?string $failureCode,
        public ?string $failureMessage,
    ) {}
}
