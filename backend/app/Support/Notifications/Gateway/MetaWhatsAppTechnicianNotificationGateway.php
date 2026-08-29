<?php

namespace App\Support\Notifications\Gateway;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The BLUE V1 production Technician-notification provider adapter, against
 * the official Meta WhatsApp Cloud API
 * (https://developers.facebook.com/docs/whatsapp/cloud-api/reference/messages) -
 * never an unofficial WhatsApp Web automation library, never
 * Selenium/browser automation, never personal-account automation. Bound
 * only by App\Providers\TechnicianNotificationServiceProvider, which
 * validates every required config value is present before constructing
 * this class - every property here is therefore guaranteed non-empty.
 *
 * Business-initiated messages must use a pre-approved WhatsApp "Utility"
 * template (Meta rejects free-form outbound text to a customer/technician
 * who has not messaged the business first) - $data->templateName/
 * $data->templateParameters are always resolved by the caller (App\Actions\
 * Notifications\SendTechnicianNotificationAction) from config
 * ('services.whatsapp.assignment_template' /
 * 'unassignment_template'), never invented here.
 *
 * The Graph API has no native request-level idempotency key (unlike
 * Stripe) - $data->providerIdempotencyKey is therefore NOT sent to Meta at
 * all; BLUE's own two layers (`outbound_notifications.idempotency_key`
 * UNIQUE constraint + the PENDING-only guard in
 * SendTechnicianNotificationAction) are the entire idempotency guarantee
 * for this provider - see that Action's docblock.
 *
 * Never logs the access token or the raw response body (which may include
 * account-identifying detail this codebase has no established retention
 * policy for) - only the HTTP status code and Meta's own safe `error.code`/
 * `error.message` fields ever reach a thrown result.
 */
final readonly class MetaWhatsAppTechnicianNotificationGateway implements TechnicianNotificationGateway
{
    public function __construct(
        private string $phoneNumberId,
        private string $accessToken,
        private string $graphVersion,
        private int $timeoutSeconds,
    ) {}

    public function channelCode(): string
    {
        return 'WHATSAPP_META';
    }

    public function send(NotificationDispatchData $data): NotificationDispatchResult
    {
        $endpoint = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $this->graphVersion,
            $this->phoneNumberId,
        );

        try {
            $response = Http::withToken($this->accessToken)
                ->timeout($this->timeoutSeconds)
                ->post($endpoint, [
                    'messaging_product' => 'whatsapp',
                    'to' => $data->recipientPhoneNumber,
                    'type' => 'template',
                    'template' => [
                        'name' => $data->templateName,
                        'language' => ['code' => $data->templateLanguage],
                        'components' => $data->templateParameters === [] ? [] : [[
                            'type' => 'body',
                            'parameters' => array_map(
                                static fn (string $value): array => ['type' => 'text', 'text' => $value],
                                $data->templateParameters,
                            ),
                        ]],
                    ],
                ]);
        } catch (ConnectionException) {
            // A connection failure or timeout means the response (if any)
            // was never received - BLUE cannot tell whether Meta already
            // accepted the request before the failure. This is never safe
            // to auto-retry - see NotificationDispatchOutcome::AMBIGUOUS.
            return NotificationDispatchResult::ambiguous('Could not confirm the WhatsApp Cloud API request completed (connection/timeout failure).');
        }

        if ($response->successful()) {
            $messageId = $response->json('messages.0.id');

            return NotificationDispatchResult::submitted(is_string($messageId) ? $messageId : null);
        }

        return self::classifyFailure($response->status(), $response->json('error.code'), $response->json('error.message'));
    }

    /**
     * The one centralized failure classifier for a failed Graph API
     * response. Deliberately conservative about what "safe to retry"
     * means, because a blind resend risks a second real WhatsApp message
     * (Meta has no request-level idempotency key):
     *
     * - HTTP 429: Meta has explicitly and definitively rejected the
     *   request as rate-limited - this IS a genuine round-trip proving no
     *   message was created, so it is the one failure status safe to mark
     *   UNKNOWN/retryable.
     * - HTTP 5xx: unlike 429, a server-side error does NOT by itself prove
     *   Meta never created/accepted the message before the error was
     *   returned - ordinary HTTP semantics cannot be relied on here. Every
     *   5xx is therefore treated as AMBIGUOUS (terminal
     *   RECONCILIATION_REQUIRED, never auto-retried) unless Meta's
     *   official Cloud API documentation is later found to give an
     *   explicit no-side-effect guarantee for a specific code, in which
     *   case that one code could be special-cased.
     * - Any other 4xx (invalid phone number, unapproved/paused template,
     *   bad credentials, malformed request): a definitive client-side
     *   rejection - proof no message was queued - safe to mark terminal
     *   FAILED without ever retrying with the same idempotency key.
     */
    public static function classifyFailure(int $httpStatus, mixed $errorCode, mixed $errorMessage): NotificationDispatchResult
    {
        $safeMessage = is_string($errorMessage) && $errorMessage !== ''
            ? $errorMessage
            : "WhatsApp Cloud API rejected the request (HTTP {$httpStatus}).";
        $safeCode = is_int($errorCode) || is_string($errorCode)
            ? (string) $errorCode
            : 'WHATSAPP_API_ERROR';

        if ($httpStatus === 429) {
            return NotificationDispatchResult::unknown($safeMessage);
        }

        if ($httpStatus >= 500) {
            return NotificationDispatchResult::ambiguous($safeMessage);
        }

        return NotificationDispatchResult::definitiveFailure($safeCode, $safeMessage);
    }
}
