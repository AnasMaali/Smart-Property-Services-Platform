<?php

namespace App\Support\Payment\Gateway;

/**
 * The one safe, provider-neutral result of PaymentGateway::createPayment().
 * $clientSecret (or any other client-initiation field) is the only value
 * from this DTO ever returned to Flutter - never $failureMessage verbatim
 * (that may carry provider internals) and never any other field.
 */
final readonly class PaymentCreationResult
{
    private function __construct(
        public PaymentCreationOutcome $outcome,
        public ?string $providerSessionReference,
        public ?string $providerStatusCode,
        public ?string $clientSecret,
        public ?string $failureCode,
        public ?string $failureMessage,
    ) {}

    public static function created(
        string $providerSessionReference,
        ?string $providerStatusCode = null,
        ?string $clientSecret = null,
    ): self {
        return new self(PaymentCreationOutcome::CREATED, $providerSessionReference, $providerStatusCode, $clientSecret, null, null);
    }

    public static function definitiveFailure(string $failureCode, string $failureMessage): self
    {
        return new self(PaymentCreationOutcome::DEFINITIVE_FAILURE, null, null, null, $failureCode, $failureMessage);
    }

    public static function unknown(?string $failureMessage = null): self
    {
        return new self(PaymentCreationOutcome::UNKNOWN, null, null, null, null, $failureMessage);
    }
}
