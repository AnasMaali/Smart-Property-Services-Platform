<?php

namespace App\Support\Payment\Gateway;

/**
 * The result of PaymentGateway::verifyWebhook() - authenticity only,
 * nothing here is trusted financial input until parseWebhook() has also
 * normalized it. $providerEvent is an opaque, provider-native decoded
 * payload (e.g. a \Stripe\Event instance) never inspected outside its own
 * PaymentGateway implementation.
 */
final readonly class VerifiedWebhookResult
{
    private function __construct(
        public bool $valid,
        public mixed $providerEvent,
        public ?string $invalidReason,
    ) {}

    public static function valid(mixed $providerEvent): self
    {
        return new self(true, $providerEvent, null);
    }

    public static function invalid(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
