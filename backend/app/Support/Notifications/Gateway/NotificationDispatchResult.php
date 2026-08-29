<?php

namespace App\Support\Notifications\Gateway;

/**
 * The one safe, provider-neutral result of
 * TechnicianNotificationGateway::send(). Loosely mirrors
 * App\Support\Payment\Gateway\RefundCreationResult, plus ambiguous() -
 * see NotificationDispatchOutcome::AMBIGUOUS's docblock for why this
 * gateway needs a case RefundCreationResult does not.
 */
final readonly class NotificationDispatchResult
{
    private function __construct(
        public NotificationDispatchOutcome $outcome,
        public ?string $providerMessageReference,
        public ?string $failureCode,
        public ?string $failureMessage,
    ) {}

    public static function submitted(?string $providerMessageReference = null): self
    {
        return new self(NotificationDispatchOutcome::SUBMITTED, $providerMessageReference, null, null);
    }

    public static function definitiveFailure(string $failureCode, string $failureMessage): self
    {
        return new self(NotificationDispatchOutcome::DEFINITIVE_FAILURE, null, $failureCode, $failureMessage);
    }

    public static function unknown(?string $failureMessage = null): self
    {
        return new self(NotificationDispatchOutcome::UNKNOWN, null, null, $failureMessage);
    }

    /**
     * A provider round-trip failure with no confirmed response - never
     * auto-retried. $failureMessage should describe the transport failure
     * itself (e.g. "connection timed out"), never a guess about whether
     * Meta actually processed the request.
     */
    public static function ambiguous(?string $failureMessage = null): self
    {
        return new self(NotificationDispatchOutcome::AMBIGUOUS, null, null, $failureMessage);
    }
}
