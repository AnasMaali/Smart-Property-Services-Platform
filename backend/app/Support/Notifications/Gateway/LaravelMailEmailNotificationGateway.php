<?php

namespace App\Support\Notifications\Gateway;

use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The only production EmailNotificationGateway implementation - delivers
 * through whichever Mailer Laravel's own MAIL_MAILER config selects
 * (`smtp` in production, `log` for local development with no real SMTP
 * credentials configured) - BLUE never talks to an SMTP socket or a
 * provider SDK directly, exactly per BLUE V1 email spec section 2 ("Use
 * Laravel Mail/SMTP. Do not introduce an external provider SDK").
 */
final class LaravelMailEmailNotificationGateway implements EmailNotificationGateway
{
    public function channelCode(): string
    {
        return 'EMAIL';
    }

    public function send(EmailDispatchData $data): EmailDispatchResult
    {
        try {
            $sent = Mail::to($data->recipientAddress)->send($data->mailable);

            return EmailDispatchResult::submitted($sent?->getMessageId() ?? $data->providerIdempotencyKey);
        } catch (Throwable $e) {
            // Never log the raw exception message if it could ever embed
            // SMTP credentials - Symfony Mailer's own transport exceptions
            // never interpolate MAIL_PASSWORD into their message text, so
            // this is safe to persist as-is (see
            // App\Actions\Notifications\SendEmailNotificationAction).
            return EmailDispatchResult::failed('EMAIL_SEND_FAILED', $e->getMessage());
        }
    }
}
