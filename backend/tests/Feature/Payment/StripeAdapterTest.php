<?php

namespace Tests\Feature\Payment;

use App\Support\Payment\Gateway\NormalizedPaymentOutcome;
use App\Support\Payment\Gateway\PaymentCreationData;
use App\Support\Payment\Gateway\PaymentCreationOutcome;
use App\Support\Payment\Gateway\StripePaymentGateway;
use App\Support\Payment\PaymentPresenter;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Adapter-boundary tests for StripePaymentGateway itself - unlike
 * ProviderTest/WebhookTest/ReconciliationTest (which exercise the domain
 * pipeline through FakePaymentGateway), these tests instantiate
 * StripePaymentGateway directly, with a real (fake, non-secret) key pair,
 * and replace the Stripe SDK's own transport layer
 * (\Stripe\HttpClient\ClientInterface, via \Stripe\ApiRequestor::
 * setHttpClient()) with Tests\Support\FakeStripeHttpClient. No test in this
 * class ever opens a real socket - every "Stripe" response is a canned
 * array this test queues, and the real stripe-php SDK code (request
 * building, JSON parsing, typed exception construction from an HTTP error
 * body) runs exactly as it would in production, which is what makes this
 * class - not ProviderTest's single unconfigured-gateway check - the
 * evidence that createPayment()/fetchPayment() build the request stripe-php
 * expects and correctly reclassify what it throws back.
 *
 * DatabaseTransactions is required only because StripePaymentGateway reads
 * currencies.minor_unit from the database (App\Support\Payment\Gateway\
 * MinorUnitConverter's unit) - no payment_attempts/webhook row is ever
 * touched here.
 */
class StripeAdapterTest extends TestCase
{
    use DatabaseTransactions;

    private const FAKE_SECRET_KEY = 'sk_test_fake_not_a_real_stripe_secret_key_12345';

    private const FAKE_WEBHOOK_SECRET = 'whsec_fake_not_a_real_stripe_webhook_secret_67890';

    private FakeStripeHttpClient $httpClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClient = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->httpClient);
    }

    protected function tearDown(): void
    {
        // The Stripe SDK's HTTP client override is a process-wide static -
        // never let it leak into any other test class.
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function gateway(): StripePaymentGateway
    {
        return new StripePaymentGateway(self::FAKE_SECRET_KEY, self::FAKE_WEBHOOK_SECRET);
    }

    private function creationData(?string $providerIdempotencyKey = null): PaymentCreationData
    {
        $attemptUuid = (string) Str::uuid();

        return new PaymentCreationData(
            paymentAttemptUuid: $attemptUuid,
            checkoutReference: 'ckt_ref_'.$attemptUuid,
            amount: '100.000000',
            currencyCode: 'AED',
            currencyMinorUnit: 2,
            providerIdempotencyKey: $providerIdempotencyKey ?? 'blue_'.$attemptUuid,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function paymentIntentBody(array $overrides = []): array
    {
        return array_merge([
            'id' => 'pi_fake_123',
            'object' => 'payment_intent',
            'status' => 'requires_payment_method',
            'amount' => 10000,
            'currency' => 'aed',
            'client_secret' => 'pi_fake_123_secret_abc',
            'metadata' => ['checkout_reference' => 'ckt_ref_x', 'payment_attempt_uuid' => 'x'],
            'payment_method_types' => ['card'],
            'latest_charge' => null,
            'last_payment_error' => null,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function apiErrorBody(string $type, string $message, ?string $code = null): array
    {
        return [
            'error' => array_filter([
                'type' => $type,
                'message' => $message,
                'code' => $code,
            ], static fn ($v) => $v !== null),
        ];
    }

    // -----------------------------------------------------------------
    // createPayment(): request shape
    // -----------------------------------------------------------------

    public function test_create_payment_converts_decimal_amount_to_stripe_minor_units(): void
    {
        $this->httpClient->queueResponse(200, $this->paymentIntentBody(['amount' => 10000]));

        $this->gateway()->createPayment($this->creationData());

        $this->assertSame(10000, $this->httpClient->requests[0]['params']['amount']);
    }

    public function test_create_payment_lowercases_the_currency_code(): void
    {
        $this->httpClient->queueResponse(200, $this->paymentIntentBody());

        $this->gateway()->createPayment($this->creationData());

        $this->assertSame('aed', $this->httpClient->requests[0]['params']['currency']);
    }

    public function test_create_payment_requests_automatic_payment_methods(): void
    {
        $this->httpClient->queueResponse(200, $this->paymentIntentBody());

        $this->gateway()->createPayment($this->creationData());

        // stripe-php's own request builder stringifies scalar booleans
        // before they reach HttpClient\ClientInterface::request() (form
        // encoding, not JSON) - 'true' here is what the real Stripe API
        // actually receives on the wire, not a quirk of the fake transport.
        $this->assertSame(
            ['enabled' => 'true'],
            $this->httpClient->requests[0]['params']['automatic_payment_methods'],
        );
    }

    public function test_create_payment_never_uses_manual_capture(): void
    {
        $this->httpClient->queueResponse(200, $this->paymentIntentBody());

        $this->gateway()->createPayment($this->creationData());

        $this->assertArrayNotHasKey('capture_method', $this->httpClient->requests[0]['params']);
    }

    public function test_create_payment_sends_only_safe_metadata(): void
    {
        $data = $this->creationData();
        $this->httpClient->queueResponse(200, $this->paymentIntentBody());

        $this->gateway()->createPayment($data);

        $metadata = $this->httpClient->requests[0]['params']['metadata'];

        $this->assertSame([
            'checkout_reference' => $data->checkoutReference,
            'payment_attempt_uuid' => $data->paymentAttemptUuid,
        ], $metadata);

        foreach (['password', 'jwt', 'session_id', 'checkout_snapshot', 'card_number', 'cvc', 'pricing_rule'] as $forbiddenKey) {
            $this->assertArrayNotHasKey($forbiddenKey, $metadata);
        }
    }

    public function test_create_payment_sends_the_stable_provider_idempotency_key_as_a_header(): void
    {
        $data = $this->creationData(providerIdempotencyKey: 'blue_fixed_key_123');
        $this->httpClient->queueResponse(200, $this->paymentIntentBody());

        $this->gateway()->createPayment($data);

        $this->assertTrue(
            collect($this->httpClient->requests[0]['headers'])->contains('Idempotency-Key: blue_fixed_key_123'),
        );
    }

    public function test_a_retry_with_the_same_attempt_reuses_the_same_idempotency_key(): void
    {
        $data = $this->creationData(providerIdempotencyKey: 'blue_retry_key_456');
        $this->httpClient->queueResponse(200, $this->paymentIntentBody());
        $this->httpClient->queueResponse(200, $this->paymentIntentBody());

        $this->gateway()->createPayment($data);
        $this->gateway()->createPayment($data);

        $this->assertCount(2, $this->httpClient->requests);
        $firstKey = collect($this->httpClient->requests[0]['headers'])->first(fn ($h) => str_starts_with($h, 'Idempotency-Key:'));
        $secondKey = collect($this->httpClient->requests[1]['headers'])->first(fn ($h) => str_starts_with($h, 'Idempotency-Key:'));
        $this->assertSame($firstKey, $secondKey);
        $this->assertSame('Idempotency-Key: blue_retry_key_456', $firstKey);
    }

    // -----------------------------------------------------------------
    // createPayment(): response parsing
    // -----------------------------------------------------------------

    public function test_create_payment_extracts_the_payment_intent_id_and_client_secret(): void
    {
        $this->httpClient->queueResponse(200, $this->paymentIntentBody([
            'id' => 'pi_extracted_789',
            'client_secret' => 'pi_extracted_789_secret_xyz',
            'status' => 'requires_payment_method',
        ]));

        $result = $this->gateway()->createPayment($this->creationData());

        $this->assertSame(PaymentCreationOutcome::CREATED, $result->outcome);
        $this->assertSame('pi_extracted_789', $result->providerSessionReference);
        $this->assertSame('pi_extracted_789_secret_xyz', $result->clientSecret);
        $this->assertSame('requires_payment_method', $result->providerStatusCode);
    }

    // -----------------------------------------------------------------
    // createPayment(): error classification (DEFINITIVE_FAILURE vs UNKNOWN)
    // -----------------------------------------------------------------

    public function test_a_429_rate_limit_response_is_classified_as_unknown_not_definitive_failure(): void
    {
        // Regression guard: Stripe\Exception\RateLimitException extends
        // InvalidRequestException in stripe-php, so a naive catch/instanceof
        // order would misclassify this as a definitive rejection - see
        // StripePaymentGateway::classifyCreationFailure().
        $this->httpClient->queueResponse(429, $this->apiErrorBody('invalid_request_error', 'Too many requests.'));

        $result = $this->gateway()->createPayment($this->creationData());

        $this->assertSame(PaymentCreationOutcome::UNKNOWN, $result->outcome);
    }

    public function test_a_401_authentication_error_is_classified_as_definitive_failure(): void
    {
        $this->httpClient->queueResponse(401, $this->apiErrorBody('authentication_error', 'Invalid API key.'));

        $result = $this->gateway()->createPayment($this->creationData());

        $this->assertSame(PaymentCreationOutcome::DEFINITIVE_FAILURE, $result->outcome);
        $this->assertSame('STRIPE_REQUEST_REJECTED', $result->failureCode);
    }

    public function test_a_400_invalid_request_error_is_classified_as_definitive_failure(): void
    {
        $this->httpClient->queueResponse(400, $this->apiErrorBody('invalid_request_error', 'Invalid parameter.', 'parameter_invalid_empty'));

        $result = $this->gateway()->createPayment($this->creationData());

        $this->assertSame(PaymentCreationOutcome::DEFINITIVE_FAILURE, $result->outcome);
        $this->assertSame('STRIPE_REQUEST_REJECTED', $result->failureCode);
    }

    public function test_a_500_stripe_server_error_is_classified_as_unknown(): void
    {
        $this->httpClient->queueResponse(500, $this->apiErrorBody('api_error', 'Internal server error.'));

        $result = $this->gateway()->createPayment($this->creationData());

        $this->assertSame(PaymentCreationOutcome::UNKNOWN, $result->outcome);
    }

    public function test_a_402_card_error_is_classified_as_definitive_failure(): void
    {
        $this->httpClient->queueResponse(402, $this->apiErrorBody('card_error', 'Your card was declined.', 'card_declined'));

        $result = $this->gateway()->createPayment($this->creationData());

        $this->assertSame(PaymentCreationOutcome::DEFINITIVE_FAILURE, $result->outcome);
        $this->assertSame('STRIPE_API_ERROR', $result->failureCode);
    }

    public function test_a_connection_failure_is_classified_as_unknown_and_never_thrown(): void
    {
        $this->httpClient->queueException(ApiConnectionException::factory('Could not connect to Stripe.'));

        $result = $this->gateway()->createPayment($this->creationData());

        $this->assertSame(PaymentCreationOutcome::UNKNOWN, $result->outcome);
    }

    public function test_provider_failure_details_never_reach_the_flutter_facing_presenter(): void
    {
        $this->httpClient->queueResponse(401, $this->apiErrorBody('authentication_error', 'Invalid API Key provided: '.self::FAKE_SECRET_KEY));

        $result = $this->gateway()->createPayment($this->creationData());
        $this->assertSame(PaymentCreationOutcome::DEFINITIVE_FAILURE, $result->outcome);

        // Stripe's own authentication error message can echo the offending
        // key back into $result->failureMessage - that value is internal
        // (CreatePaymentAttemptAction::compensateDefinitiveFailure() uses
        // it for the state machine, not the response) and must never reach
        // PaymentPresenter's Flutter-facing output.
        $presented = PaymentPresenter::present($this->fakeStatusAndCurrencyRow(), null);

        $this->assertArrayNotHasKey('failure_message', $presented);
        $this->assertArrayNotHasKey('failure_code', $presented);
        $this->assertArrayNotHasKey('client_secret', $presented);
    }

    private function fakeStatusAndCurrencyRow(): object
    {
        $statusId = DB::table('payment_statuses')->where('code', 'PENDING')->value('id');
        $currencyId = DB::table('currencies')->where('code', 'AED')->value('id');

        return (object) [
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'checkout_reference' => 'ckt_ref_presenter_test',
            'status_id' => $statusId,
            'requested_amount' => '1.000000',
            'currency_id' => $currencyId,
            'expires_at' => null,
            'provider_code' => 'STRIPE',
        ];
    }

    // -----------------------------------------------------------------
    // fetchPayment(): recovery path, same status mapper as webhooks
    // -----------------------------------------------------------------

    public function test_fetch_payment_retrieves_by_provider_reference(): void
    {
        $this->httpClient->queueResponse(200, $this->paymentIntentBody(['id' => 'pi_fetch_me', 'status' => 'succeeded', 'amount' => 10000]));

        $this->gateway()->fetchPayment('pi_fetch_me');

        $this->assertSame('get', $this->httpClient->requests[0]['method']);
        $this->assertStringContainsString('pi_fetch_me', $this->httpClient->requests[0]['url']);
    }

    public function test_fetch_payment_normalizes_status_through_the_same_mapper_as_webhooks(): void
    {
        $this->httpClient->queueResponse(200, $this->paymentIntentBody(['status' => 'succeeded', 'amount' => 10000]));

        $state = $this->gateway()->fetchPayment('pi_fake_123');

        $this->assertSame(NormalizedPaymentOutcome::SUCCEEDED, $state->outcome);
        $this->assertSame(
            StripePaymentGateway::normalizeOutcome('succeeded'),
            $state->outcome,
        );
        $this->assertSame('100.000000', $state->amount);
        $this->assertSame('AED', $state->currencyCode);
    }

    /**
     * @return iterable<string, array{0: string, 1: NormalizedPaymentOutcome}>
     */
    public static function fetchStatusProvider(): iterable
    {
        yield 'requires_payment_method' => ['requires_payment_method', NormalizedPaymentOutcome::NON_TERMINAL];
        yield 'requires_action' => ['requires_action', NormalizedPaymentOutcome::NON_TERMINAL];
        yield 'processing' => ['processing', NormalizedPaymentOutcome::NON_TERMINAL];
        yield 'requires_capture' => ['requires_capture', NormalizedPaymentOutcome::UNEXPECTED_NON_TERMINAL];
        yield 'canceled' => ['canceled', NormalizedPaymentOutcome::CANCELED];
        yield 'succeeded' => ['succeeded', NormalizedPaymentOutcome::SUCCEEDED];
    }

    #[DataProvider('fetchStatusProvider')]
    public function test_fetch_payment_maps_every_known_stripe_status(string $stripeStatus, NormalizedPaymentOutcome $expected): void
    {
        $this->httpClient->queueResponse(200, $this->paymentIntentBody(['status' => $stripeStatus]));

        $state = $this->gateway()->fetchPayment('pi_fake_123');

        $this->assertSame($expected, $state->outcome);
        $this->assertSame($stripeStatus, $state->providerStatusCode);
    }

    // -----------------------------------------------------------------
    // verifyWebhook() / parseWebhook(): real Stripe\Webhook signing, no HTTP
    // -----------------------------------------------------------------

    private function signedStripePayload(array $event): array
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signedPayload = "{$timestamp}.{$body}";
        $signature = hash_hmac('sha256', $signedPayload, self::FAKE_WEBHOOK_SECRET);
        $header = "t={$timestamp},v1={$signature}";

        return ['body' => $body, 'header' => $header];
    }

    public function test_a_correctly_signed_webhook_verifies_and_normalizes_a_succeeded_payment_intent(): void
    {
        $event = [
            'id' => 'evt_real_sig_1',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => $this->paymentIntentBody([
                'id' => 'pi_webhook_1',
                'status' => 'succeeded',
                'amount' => 10000,
                'metadata' => ['checkout_reference' => 'ckt_ref_webhook_1'],
            ])],
        ];
        ['body' => $body, 'header' => $header] = $this->signedStripePayload($event);

        $gateway = $this->gateway();
        $verified = $gateway->verifyWebhook($body, ['Stripe-Signature' => $header]);

        $this->assertTrue($verified->valid);

        $normalized = $gateway->parseWebhook($verified->providerEvent);

        $this->assertSame(NormalizedPaymentOutcome::SUCCEEDED, $normalized->outcome);
        $this->assertSame('pi_webhook_1', $normalized->providerSessionReference);
        $this->assertSame('ckt_ref_webhook_1', $normalized->checkoutReference);
        $this->assertSame('100.000000', $normalized->amount);
    }

    public function test_a_tampered_payload_fails_signature_verification(): void
    {
        $event = ['id' => 'evt_tampered', 'object' => 'event', 'type' => 'payment_intent.succeeded', 'data' => ['object' => $this->paymentIntentBody()]];
        ['header' => $header] = $this->signedStripePayload($event);

        // The signature was computed over the original body - verifying a
        // different (tampered) body against it must fail closed.
        $tamperedBody = json_encode(array_merge($event, ['type' => 'payment_intent.canceled']), JSON_THROW_ON_ERROR);

        $verified = $this->gateway()->verifyWebhook($tamperedBody, ['Stripe-Signature' => $header]);

        $this->assertFalse($verified->valid);
    }

    public function test_an_authentic_event_for_a_non_payment_intent_object_is_unrecognized(): void
    {
        $event = [
            'id' => 'evt_unrelated',
            'object' => 'event',
            'type' => 'charge.dispute.created',
            'data' => ['object' => ['id' => 'ch_x', 'object' => 'charge']],
        ];
        ['body' => $body, 'header' => $header] = $this->signedStripePayload($event);

        $gateway = $this->gateway();
        $verified = $gateway->verifyWebhook($body, ['Stripe-Signature' => $header]);
        $this->assertTrue($verified->valid, 'An authentic event for an event type BLUE does not act on must still verify - only parsing/mutation is skipped.');

        $normalized = $gateway->parseWebhook($verified->providerEvent);

        $this->assertSame(NormalizedPaymentOutcome::UNRECOGNIZED, $normalized->outcome);
    }

    public function test_no_signature_header_at_all_is_rejected(): void
    {
        $verified = $this->gateway()->verifyWebhook('{}', []);

        $this->assertFalse($verified->valid);
    }

    // -----------------------------------------------------------------
    // No real network call ever happens in this class
    // -----------------------------------------------------------------

    public function test_every_stripe_call_in_this_suite_is_routed_through_the_fake_transport(): void
    {
        $this->httpClient->queueResponse(200, $this->paymentIntentBody());
        $this->httpClient->queueResponse(200, $this->paymentIntentBody());

        $this->gateway()->createPayment($this->creationData());
        $this->gateway()->fetchPayment('pi_fake_123');

        // Every request this test suite makes is captured here - a real
        // network attempt would either hang/fail against the fake
        // sk_test_fake_... key (which is not a live Stripe credential) or,
        // if one somehow succeeded, would not match the exact canned
        // response this assertion already consumed above.
        $this->assertCount(2, $this->httpClient->requests);
    }
}
