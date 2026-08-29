<?php

namespace App\Support\Notifications\Gateway;

/**
 * The result of EmailNotificationGateway::send() - mirrors
 * App\Support\Notifications\Gateway\NotificationDispatchResult's shape for
 * WhatsApp, minus the fields ($templateName-adjacent) that never applied to
 * email in the first place.
 */
final readonly class EmailDispatchResult
{
    private function __construct(
        public EmailDispatchOutcome $outcome,
        public ?string $providerMessageReference = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
    ) {}

    public static function submitted(?string $providerMessageReference): self
    {
        return new self(EmailDispatchOutcome::SUBMITTED, providerMessageReference: $providerMessageReference);
    }

    public static function failed(string $failureCode, ?string $failureMessage): self
    {
        return new self(EmailDispatchOutcome::FAILED, failureCode: $failureCode, failureMessage: $failureMessage);
    }
}
