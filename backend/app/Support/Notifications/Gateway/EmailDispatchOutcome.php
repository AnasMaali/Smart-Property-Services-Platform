<?php

namespace App\Support\Notifications\Gateway;

/**
 * The outcome of EmailNotificationGateway::send() - deliberately simpler
 * than NotificationDispatchOutcome (the WhatsApp equivalent). Standard SMTP
 * delivery (Laravel Mail) has no Meta-style "ambiguous, no idempotency key"
 * failure mode to guard against: a Mailer send either succeeds (SUBMITTED)
 * or throws, and every thrown failure - a transient connection error and a
 * definitive rejection alike - is safe to treat as ordinary-retryable here
 * (see App\Actions\Notifications\SendEmailNotificationAction's bounded
 * linear-backoff, mirroring config('technician_notifications.max_attempts')
 * for WhatsApp). RECONCILIATION_REQUIRED is therefore never produced for an
 * EMAIL-channel `outbound_notifications` row.
 */
enum EmailDispatchOutcome: string
{
    /**
     * The configured Mailer accepted and sent the message. Proof of
     * submission only - never proof of delivery or that it was read (no
     * delivery/read webhook exists in this phase, exactly like WhatsApp's
     * SUBMITTED status).
     */
    case SUBMITTED = 'SUBMITTED';

    /**
     * The Mailer threw (SMTP connection failure, provider rejection,
     * misconfiguration, etc.) - safe to retry with the SAME idempotency key
     * up to config('email_notifications.max_attempts'), after which it
     * becomes a terminal FAILED.
     */
    case FAILED = 'FAILED';
}
