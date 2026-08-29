<?php

namespace App\Support\Notifications\Gateway;

use Illuminate\Contracts\Mail\Mailable;

/**
 * Everything an EmailNotificationGateway needs to send (or safely resume)
 * exactly one email for one `outbound_notifications` obligation - mirrors
 * App\Support\Notifications\Gateway\NotificationDispatchData's role for
 * WhatsApp. $mailable is built entirely from server-authoritative Booking/
 * Booking Item/Technician/Customer data already resolved and persisted by
 * App\Actions\Notifications\CreateEmailNotificationAction - never from
 * client input, and never containing Stripe/payment-provider/refund/
 * internal-pricing data.
 */
final readonly class EmailDispatchData
{
    public function __construct(
        public string $notificationUuid,
        public string $recipientAddress,
        public Mailable $mailable,
        public string $providerIdempotencyKey,
    ) {}
}
