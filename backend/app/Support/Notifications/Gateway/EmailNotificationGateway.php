<?php

namespace App\Support\Notifications\Gateway;

/**
 * The one provider-neutral boundary between BLUE's Technician/Customer
 * email-notification domain and the concrete mail transport - mirrors
 * App\Support\Notifications\Gateway\TechnicianNotificationGateway's role for
 * WhatsApp exactly. App\Actions\Notifications\SendEmailNotificationAction
 * never depends on a concrete implementation, only this interface, so the
 * "testing" environment can bind a deterministic fake (see
 * FakeEmailNotificationGateway) while every other environment sends through
 * Laravel's own configured Mailer (MAIL_MAILER, see
 * LaravelMailEmailNotificationGateway) - see App\Providers\
 * EmailNotificationServiceProvider for the only place a concrete
 * implementation is chosen.
 */
interface EmailNotificationGateway
{
    /**
     * Purely for operator traceability/logging, never persisted as a
     * business-meaningful discriminator (that is `outbound_notifications.
     * channel`, set once at obligation-creation time).
     */
    public function channelCode(): string;

    /**
     * Sends exactly one email for a `outbound_notifications` obligation.
     * Must never throw - every failure (SMTP connection error, provider
     * rejection, malformed address making it this far, etc.) is reported as
     * EmailDispatchOutcome::FAILED so the caller can persist a safe,
     * retryable state instead of an uncaught exception ever reaching the
     * Admin assign/reassign HTTP request that triggered the best-effort
     * send.
     */
    public function send(EmailDispatchData $data): EmailDispatchResult;
}
