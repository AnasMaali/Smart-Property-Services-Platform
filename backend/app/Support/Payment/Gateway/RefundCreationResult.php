<?php

namespace App\Support\Payment\Gateway;

/**
 * The one safe, provider-neutral result of PaymentGateway::refundPayment().
 * Mirrors PaymentCreationResult exactly.
 */
final readonly class RefundCreationResult
{
    private function __construct(
        public RefundCreationOutcome $outcome,
        public ?string $providerRefundReference,
        public ?string $providerStatusCode,
        public ?string $failureCode,
        public ?string $failureMessage,
    ) {}

    public static function created(string $providerRefundReference, ?string $providerStatusCode = null): self
    {
        return new self(RefundCreationOutcome::CREATED, $providerRefundReference, $providerStatusCode, null, null);
    }

    public static function definitiveFailure(string $failureCode, string $failureMessage): self
    {
        return new self(RefundCreationOutcome::DEFINITIVE_FAILURE, null, null, $failureCode, $failureMessage);
    }

    public static function unknown(?string $failureMessage = null): self
    {
        return new self(RefundCreationOutcome::UNKNOWN, null, null, null, $failureMessage);
    }
}
