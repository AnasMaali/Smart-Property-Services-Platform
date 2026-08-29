<?php

namespace App\Providers;

use App\Support\Notifications\Gateway\FakeTechnicianNotificationGateway;
use App\Support\Notifications\Gateway\LogTechnicianNotificationGateway;
use App\Support\Notifications\Gateway\MetaWhatsAppTechnicianNotificationGateway;
use App\Support\Notifications\Gateway\TechnicianNotificationGateway;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * The single place TechnicianNotificationGateway is bound - mirrors
 * App\Providers\PaymentServiceProvider (testing environment always gets
 * the fake, regardless of config) and App\Providers\
 * OtpDeliveryServiceProvider (every required credential is validated
 * eagerly here, so a misconfigured driver fails loudly at resolution time
 * rather than lazily inside the first real send attempt).
 */
class TechnicianNotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TechnicianNotificationGateway::class, function ($app) {
            if ($app->environment('testing')) {
                return new FakeTechnicianNotificationGateway;
            }

            return match (config('technician_notifications.driver')) {
                'log' => new LogTechnicianNotificationGateway,
                'meta_whatsapp' => $this->makeMetaWhatsAppGateway(),
                default => throw new RuntimeException(
                    'Unsupported TECHNICIAN_NOTIFICATION_DRIVER config value: '.config('technician_notifications.driver')
                ),
            };
        });
    }

    private function makeMetaWhatsAppGateway(): MetaWhatsAppTechnicianNotificationGateway
    {
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $accessToken = config('services.whatsapp.access_token');
        $graphVersion = config('services.whatsapp.graph_version');
        $assignmentTemplate = config('services.whatsapp.assignment_template');
        $unassignmentTemplate = config('services.whatsapp.unassignment_template');

        if (! is_string($phoneNumberId) || $phoneNumberId === '') {
            throw new RuntimeException('TECHNICIAN_NOTIFICATION_DRIVER=meta_whatsapp requires WHATSAPP_PHONE_NUMBER_ID to be configured.');
        }

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('TECHNICIAN_NOTIFICATION_DRIVER=meta_whatsapp requires WHATSAPP_ACCESS_TOKEN to be configured.');
        }

        if (! is_string($graphVersion) || $graphVersion === '') {
            throw new RuntimeException('TECHNICIAN_NOTIFICATION_DRIVER=meta_whatsapp requires WHATSAPP_GRAPH_VERSION to be configured - never hardcoded, since Meta deprecates Graph API versions on its own schedule.');
        }

        if (! is_string($assignmentTemplate) || $assignmentTemplate === '') {
            throw new RuntimeException('TECHNICIAN_NOTIFICATION_DRIVER=meta_whatsapp requires WHATSAPP_ASSIGNMENT_TEMPLATE to be configured.');
        }

        if (! is_string($unassignmentTemplate) || $unassignmentTemplate === '') {
            throw new RuntimeException('TECHNICIAN_NOTIFICATION_DRIVER=meta_whatsapp requires WHATSAPP_UNASSIGNMENT_TEMPLATE to be configured.');
        }

        $timeoutSeconds = (int) config('services.whatsapp.timeout_seconds', 10);

        // Guzzle (Laravel's HTTP client) treats a 0 timeout as "wait
        // forever" - a hung Graph API connection would then block the
        // Admin's own HTTP request (the best-effort synchronous send
        // attempt runs on that same request/response cycle) indefinitely.
        if ($timeoutSeconds < 1) {
            throw new RuntimeException('WHATSAPP_TIMEOUT_SECONDS must be a positive integer.');
        }

        return new MetaWhatsAppTechnicianNotificationGateway(
            phoneNumberId: $phoneNumberId,
            accessToken: $accessToken,
            graphVersion: $graphVersion,
            timeoutSeconds: $timeoutSeconds,
        );
    }
}
