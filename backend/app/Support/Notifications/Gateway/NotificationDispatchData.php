<?php

namespace App\Support\Notifications\Gateway;

/**
 * Everything a TechnicianNotificationGateway needs to send (or safely
 * resume) exactly one WhatsApp message for one `outbound_notifications`
 * obligation. Built entirely from server-authoritative Booking/Booking
 * Item/Technician data already resolved and persisted by
 * App\Actions\Notifications\CreateTechnicianAssignmentNotificationAction -
 * never from client input, and never containing Stripe/payment/pricing
 * data (see App\Support\Notifications\TechnicianJobNotificationContent).
 *
 * $templateParameters is the DETERMINISTIC, positional parameter list a
 * Meta WhatsApp Utility template expects ({{1}}, {{2}}, ...) - see
 * docs/handoff/technician-whatsapp-v1.md for the exact order each template
 * requires. $renderedText is the same content rendered as one
 * human-readable block, used by the "log" driver so local development
 * never needs a real Meta template at all.
 */
final readonly class NotificationDispatchData
{
    /**
     * @param  array<int, string>  $templateParameters
     */
    public function __construct(
        public string $notificationUuid,
        public string $recipientPhoneNumber,
        public string $templateName,
        public string $templateLanguage,
        public array $templateParameters,
        public string $renderedText,
        public string $providerIdempotencyKey,
    ) {}
}
