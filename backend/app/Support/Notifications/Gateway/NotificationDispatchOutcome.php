<?php

namespace App\Support\Notifications\Gateway;

/**
 * The outcome of TechnicianNotificationGateway::send() - loosely mirrors
 * App\Support\Payment\Gateway\RefundCreationOutcome, but with one
 * deliberate difference: the Meta WhatsApp Cloud API's send-message
 * endpoint has NO request-level idempotency key (unlike Stripe), so an
 * outcome that merely "could not be determined" can mean two very
 * different things here, and conflating them would be unsafe - see
 * UNKNOWN vs AMBIGUOUS below, and
 * App\Actions\Notifications\SendTechnicianNotificationAction for how each
 * is handled.
 */
enum NotificationDispatchOutcome: string
{
    /**
     * The provider accepted the message request (e.g. Meta returned a
     * message id). This is proof of submission only - never proof the
     * Technician read it, and (until delivery/read webhooks exist) never
     * proof of delivery either. See App\Support\Notifications\
     * OutboundNotificationStatuses for why "SUBMITTED" is the correct
     * terminal-success label, not "DELIVERED".
     */
    case SUBMITTED = 'SUBMITTED';

    /**
     * Definitively proven the message was rejected for a reason a retry
     * cannot fix (invalid phone number, unapproved/misconfigured template,
     * bad credentials, malformed request). Safe to mark the obligation
     * FAILED - never retried with the same idempotency key.
     */
    case DEFINITIVE_FAILURE = 'DEFINITIVE_FAILURE';

    /**
     * A provider-side outcome that proves no message was created (e.g. a
     * Meta-returned 429/5xx - the API responded, which means the request
     * was received and rejected/errored server-side before ever reaching
     * message dispatch). Safe to auto-retry with the SAME idempotency key
     * up to config('technician_notifications.max_attempts'), after which
     * it becomes a terminal FAILED. This is the only outcome this gateway
     * ever treats as automatically retryable.
     */
    case UNKNOWN = 'UNKNOWN';

    /**
     * The provider round-trip itself failed (connection error, DNS
     * failure, or a timeout with no response ever received) - BLUE
     * cannot determine whether Meta received and acted on the request
     * before the failure occurred. Because Meta's API has no idempotency
     * key, retrying here carries a real risk of sending a SECOND real
     * WhatsApp message if the first request actually succeeded
     * server-side. This is NEVER auto-retried - it maps to the terminal
     * `RECONCILIATION_REQUIRED` status, requiring an explicit,
     * out-of-band human decision (see docs/handoff/
     * technician-whatsapp-v1.md) rather than an automatic resend.
     */
    case AMBIGUOUS = 'AMBIGUOUS';
}
