<?php

namespace App\Support\Notifications\Gateway;

/**
 * The one provider-neutral boundary between BLUE's Technician-assignment
 * domain and any concrete outbound-messaging provider - mirrors
 * App\Support\Payment\Gateway\PaymentGateway's role exactly.
 * App\Actions\Notifications\SendTechnicianNotificationAction never depends
 * on a concrete implementation, only this interface, so V2 can swap in a
 * push-notification/Technician-app channel later without touching the
 * assignment or notification-recovery logic at all - see
 * App\Providers\TechnicianNotificationServiceProvider for the only place a
 * concrete implementation is chosen.
 */
interface TechnicianNotificationGateway
{
    /**
     * The exact channel identifier this implementation represents (e.g.
     * "WHATSAPP") - purely for operator traceability/logging, never
     * persisted as a business-meaningful discriminator (that is
     * `outbound_notifications.channel`, set once at obligation-creation
     * time, independent of which driver happens to be configured).
     */
    public function channelCode(): string;

    /**
     * Sends (or safely resumes) exactly one WhatsApp message for a
     * `outbound_notifications` obligation. Must be called with a stable,
     * obligation-derived idempotency key
     * ($data->providerIdempotencyKey === outbound_notifications.
     * idempotency_key) so a retry after a timeout/crash can never send a
     * second message - see NotificationDispatchData's docblock.
     *
     * Must never throw for an ordinary provider-side rejection that still
     * proves no duplicate-send risk exists - that is still
     * NotificationDispatchOutcome::DEFINITIVE_FAILURE. Only a genuinely
     * ambiguous network/timeout outcome escapes as
     * NotificationDispatchOutcome::UNKNOWN.
     */
    public function send(NotificationDispatchData $data): NotificationDispatchResult;
}
