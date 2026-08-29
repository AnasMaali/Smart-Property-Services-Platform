<?php

namespace Tests\Feature\Notifications;

use App\Support\Notifications\Gateway\MetaWhatsAppTechnicianNotificationGateway;
use App\Support\Notifications\Gateway\NotificationDispatchData;
use App\Support\Notifications\Gateway\NotificationDispatchOutcome;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BLUE V1 Phase B21 safety-correction pass - proves
 * App\Support\Notifications\Gateway\MetaWhatsAppTechnicianNotificationGateway
 * classifies every Graph API response as conservatively as a real-message
 * WhatsApp channel requires: an HTTP 5xx must NEVER be treated as safe to
 * auto-retry, because a server-side error response does not, by itself,
 * prove Meta never created/accepted the message before returning it. Only
 * an explicit HTTP 429 (a genuine round-trip that Meta itself rejected) is
 * safe to retry; every 5xx and every connection/timeout failure must land
 * on AMBIGUOUS (terminal RECONCILIATION_REQUIRED, never auto-resent) - see
 * MetaWhatsAppTechnicianNotificationGateway::classifyFailure().
 */
class MetaWhatsAppTechnicianNotificationGatewayTest extends TestCase
{
    private const ENDPOINT = 'https://graph.facebook.com/v20.0/123456789/messages';

    private function gateway(): MetaWhatsAppTechnicianNotificationGateway
    {
        return new MetaWhatsAppTechnicianNotificationGateway(
            phoneNumberId: '123456789',
            accessToken: 'super-secret-access-token',
            graphVersion: 'v20.0',
            timeoutSeconds: 5,
        );
    }

    private function data(): NotificationDispatchData
    {
        return new NotificationDispatchData(
            notificationUuid: '11111111-1111-1111-1111-111111111111',
            recipientPhoneNumber: '+971501234567',
            templateName: 'blue_technician_assignment_v1',
            templateLanguage: 'en',
            templateParameters: ['Omar', 'BLU-TEST', 'Service', 'Today', 'Customer', 'Address'],
            renderedText: 'BLUE | New Service Assignment',
            providerIdempotencyKey: 'blue_notify_test_key',
        );
    }

    public function test_successful_2xx_response_persists_the_provider_message_id_and_is_submitted(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['messages' => [['id' => 'wamid.TEST123']]], 200)]);

        $result = $this->gateway()->send($this->data());

        $this->assertSame(NotificationDispatchOutcome::SUBMITTED, $result->outcome);
        $this->assertSame('wamid.TEST123', $result->providerMessageReference);
    }

    public function test_definitive_validation_rejection_400_becomes_failed(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'error' => ['code' => 132001, 'message' => 'Template does not exist in the specified language'],
        ], 400)]);

        $result = $this->gateway()->send($this->data());

        $this->assertSame(NotificationDispatchOutcome::DEFINITIVE_FAILURE, $result->outcome);
        $this->assertSame('132001', $result->failureCode);
    }

    public function test_http_429_remains_retryable_as_unknown(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'error' => ['code' => 80007, 'message' => 'Rate limit hit'],
        ], 429)]);

        $result = $this->gateway()->send($this->data());

        $this->assertSame(NotificationDispatchOutcome::UNKNOWN, $result->outcome);
    }

    public function test_http_500_does_not_automatically_retry_and_is_ambiguous(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['error' => ['message' => 'Internal server error']], 500)]);

        $result = $this->gateway()->send($this->data());

        $this->assertSame(NotificationDispatchOutcome::AMBIGUOUS, $result->outcome);
        $this->assertNotSame(NotificationDispatchOutcome::UNKNOWN, $result->outcome);
    }

    public function test_http_503_does_not_automatically_retry_and_is_ambiguous(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['error' => ['message' => 'Service unavailable']], 503)]);

        $result = $this->gateway()->send($this->data());

        $this->assertSame(NotificationDispatchOutcome::AMBIGUOUS, $result->outcome);
        $this->assertNotSame(NotificationDispatchOutcome::UNKNOWN, $result->outcome);
    }

    public function test_connection_timeout_does_not_automatically_retry_and_is_ambiguous(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out contacting the WhatsApp Cloud API.'));

        $result = $this->gateway()->send($this->data());

        $this->assertSame(NotificationDispatchOutcome::AMBIGUOUS, $result->outcome);
    }

    public function test_classify_failure_matrix_directly(): void
    {
        $this->assertSame(NotificationDispatchOutcome::UNKNOWN, MetaWhatsAppTechnicianNotificationGateway::classifyFailure(429, null, null)->outcome);
        $this->assertSame(NotificationDispatchOutcome::AMBIGUOUS, MetaWhatsAppTechnicianNotificationGateway::classifyFailure(500, null, null)->outcome);
        $this->assertSame(NotificationDispatchOutcome::AMBIGUOUS, MetaWhatsAppTechnicianNotificationGateway::classifyFailure(503, null, null)->outcome);
        $this->assertSame(NotificationDispatchOutcome::AMBIGUOUS, MetaWhatsAppTechnicianNotificationGateway::classifyFailure(529, null, null)->outcome);
        $this->assertSame(NotificationDispatchOutcome::DEFINITIVE_FAILURE, MetaWhatsAppTechnicianNotificationGateway::classifyFailure(400, null, null)->outcome);
        $this->assertSame(NotificationDispatchOutcome::DEFINITIVE_FAILURE, MetaWhatsAppTechnicianNotificationGateway::classifyFailure(401, null, null)->outcome);
        $this->assertSame(NotificationDispatchOutcome::DEFINITIVE_FAILURE, MetaWhatsAppTechnicianNotificationGateway::classifyFailure(404, null, null)->outcome);
    }
}
