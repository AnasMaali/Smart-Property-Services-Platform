<?php

namespace App\Support\Payment\Gateway;

/**
 * Everything a PaymentGateway needs to start (or safely resume) exactly
 * one provider-side payment for exactly one BLUE payment attempt. Built
 * entirely from server-authoritative values already frozen inside
 * Transaction A (see CreatePaymentAttemptAction) - never from client input.
 */
final readonly class PaymentCreationData
{
    public function __construct(
        public string $paymentAttemptUuid,
        public string $checkoutReference,
        public string $amount,
        public string $currencyCode,
        public int $currencyMinorUnit,
        public string $providerIdempotencyKey,
    ) {}
}
