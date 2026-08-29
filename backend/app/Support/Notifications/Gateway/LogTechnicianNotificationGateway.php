<?php

namespace App\Support\Notifications\Gateway;

use Illuminate\Support\Facades\Log;

/**
 * TECHNICIAN_NOTIFICATION_DRIVER=log - writes the exact normalized
 * notification content to the log instead of contacting Meta, so local
 * development and CI never need real WhatsApp credentials. Always
 * "succeeds" (NotificationDispatchOutcome::SUBMITTED) - there is no
 * provider to fail against.
 *
 * Only ever logs already-safe, already-normalized operational fields
 * ($data->renderedText / $data->templateParameters - see
 * App\Support\Notifications\TechnicianJobNotificationContent, which never
 * includes Stripe/payment/pricing/internal-UUID data) - never a raw
 * access token (this driver never has one) and never anything this
 * codebase's existing logging conventions would consider sensitive.
 */
final class LogTechnicianNotificationGateway implements TechnicianNotificationGateway
{
    public function channelCode(): string
    {
        return 'WHATSAPP_LOG';
    }

    public function send(NotificationDispatchData $data): NotificationDispatchResult
    {
        Log::info('[TECHNICIAN WHATSAPP - LOG DRIVER]', [
            'notification_uuid' => $data->notificationUuid,
            'to' => $data->recipientPhoneNumber,
            'template' => $data->templateName,
            'language' => $data->templateLanguage,
            'idempotency_key' => $data->providerIdempotencyKey,
            'message' => $data->renderedText,
        ]);

        return NotificationDispatchResult::submitted('log-'.$data->notificationUuid);
    }
}
