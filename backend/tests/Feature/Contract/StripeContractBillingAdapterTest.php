<?php

namespace Tests\Feature\Contract;

use App\Support\Contract\Billing\Gateway\ContractBillingCheckoutData;
use App\Support\Contract\Billing\Gateway\ContractBillingCheckoutOutcome;
use App\Support\Contract\Billing\Gateway\StripeContractBillingGateway;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

class StripeContractBillingAdapterTest extends TestCase
{
    private const FAKE_SECRET_KEY = 'sk_test_fake_contract_billing_key_12345';

    private const FAKE_WEBHOOK_SECRET = 'whsec_fake_contract_billing_secret_67890';

    private const OTHER_WEBHOOK_SECRET = 'whsec_fake_wrong_domain_secret_99999';

    private FakeStripeHttpClient $httpClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClient = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->httpClient);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function gateway(?string $webhookSecret = null): StripeContractBillingGateway
    {
        return new StripeContractBillingGateway(
            self::FAKE_SECRET_KEY,
            $webhookSecret ?? self::FAKE_WEBHOOK_SECRET,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function checkoutData(array $overrides = []): ContractBillingCheckoutData
    {
        $data = array_merge([
            'contractBillingUuid' => '11111111-1111-4111-8111-111111111111',
            'contractUuid' => '22222222-2222-4222-8222-222222222222',
            'stripeCustomerId' => 'cus_existing_123',
            'customerEmail' => 'customer@example.com',
            'recurringAmount' => '10.500000',
            'currencyCode' => 'AED',
            'currencyMinorUnit' => 2,
            'billingInterval' => 'MONTHLY',
            'productName' => 'Service Contract CTR-TEST-001',
            'successUrl' => 'https://example.test/contracts/success',
            'cancelUrl' => 'https://example.test/contracts/cancel',
            'providerIdempotencyKey' => 'blue_contract_billing_checkout_fixed',
            'customerIdempotencyKey' => 'blue_contract_billing_customer_fixed',
        ], $overrides);

        return new ContractBillingCheckoutData(
            contractBillingUuid: $data['contractBillingUuid'],
            contractUuid: $data['contractUuid'],
            stripeCustomerId: $data['stripeCustomerId'],
            customerEmail: $data['customerEmail'],
            recurringAmount: $data['recurringAmount'],
            currencyCode: $data['currencyCode'],
            currencyMinorUnit: $data['currencyMinorUnit'],
            billingInterval: $data['billingInterval'],
            productName: $data['productName'],
            successUrl: $data['successUrl'],
            cancelUrl: $data['cancelUrl'],
            providerIdempotencyKey: $data['providerIdempotencyKey'],
            customerIdempotencyKey: $data['customerIdempotencyKey'],
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function checkoutSessionBody(array $overrides = []): array
    {
        return array_merge([
            'id' => 'cs_test_contract_123',
            'object' => 'checkout.session',
            'mode' => 'subscription',
            'customer' => 'cus_existing_123',
            'subscription' => null,
            'status' => 'open',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_contract_123',
            'metadata' => [
                'service_contract_uuid' => '22222222-2222-4222-8222-222222222222',
                'service_contract_billing_uuid' => '11111111-1111-4111-8111-111111111111',
            ],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function customerBody(array $overrides = []): array
    {
        return array_merge([
            'id' => 'cus_created_456',
            'object' => 'customer',
            'email' => 'customer@example.com',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function subscriptionBody(array $overrides = []): array
    {
        return array_merge([
            'id' => 'sub_contract_123',
            'object' => 'subscription',
            'customer' => 'cus_existing_123',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'cancel_at' => null,
            'canceled_at' => null,
            'metadata' => [
                'service_contract_uuid' => '22222222-2222-4222-8222-222222222222',
                'service_contract_billing_uuid' => '11111111-1111-4111-8111-111111111111',
            ],
            'items' => [
                'object' => 'list',
                'data' => [[
                    'id' => 'si_contract_123',
                    'object' => 'subscription_item',
                    'current_period_start' => 1780000000,
                    'current_period_end' => 1782678400,
                    'price' => [
                        'id' => 'price_contract_123',
                        'object' => 'price',
                        'product' => 'prod_contract_123',
                    ],
                ]],
            ],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function invoiceBody(array $overrides = []): array
    {
        return array_merge([
            'id' => 'in_contract_123',
            'object' => 'invoice',
            'customer' => 'cus_existing_123',
            'status' => 'paid',
            'parent' => [
                'type' => 'subscription_details',
                'subscription_details' => [
                    'subscription' => 'sub_contract_123',
                    'metadata' => [
                        'service_contract_uuid' => '22222222-2222-4222-8222-222222222222',
                        'service_contract_billing_uuid' => '11111111-1111-4111-8111-111111111111',
                    ],
                ],
            ],
            'lines' => [
                'object' => 'list',
                'data' => [[
                    'id' => 'il_contract_123',
                    'object' => 'line_item',
                    'period' => [
                        'start' => 1780000000,
                        'end' => 1782678400,
                    ],
                ]],
            ],
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
            ], static fn ($value) => $value !== null),
        ];
    }

    /**
     * @param array<string, mixed> $event
     * @return array{body: string, header: string}
     */
    private function signedStripePayload(array $event, ?string $secret = null): array
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signedPayload = "{$timestamp}.{$body}";
        $signature = hash_hmac(
            'sha256',
            $signedPayload,
            $secret ?? self::FAKE_WEBHOOK_SECRET,
        );

        return [
            'body' => $body,
            'header' => "t={$timestamp},v1={$signature}",
        ];
    }

    private function idempotencyHeader(array $request): ?string
    {
        return collect($request['headers'])
            ->first(fn ($header) => str_starts_with($header, 'Idempotency-Key:'));
    }

    // -----------------------------------------------------------------
    // Checkout Session creation
    // -----------------------------------------------------------------

    public function test_existing_customer_checkout_uses_server_authoritative_subscription_terms(): void
    {
        $data = $this->checkoutData();

        $this->httpClient->queueResponse(
            200,
            $this->checkoutSessionBody(),
        );

        $result = $this->gateway()->createSubscriptionCheckout($data);

        $this->assertSame(ContractBillingCheckoutOutcome::CREATED, $result->outcome);
        $this->assertSame('cus_existing_123', $result->stripeCustomerId);
        $this->assertSame('cs_test_contract_123', $result->checkoutSessionId);
        $this->assertSame(
            'https://checkout.stripe.com/c/pay/cs_test_contract_123',
            $result->checkoutUrl,
        );

        $this->assertCount(1, $this->httpClient->requests);

        $request = $this->httpClient->requests[0];

        $this->assertSame('post', $request['method']);
        $this->assertStringContainsString(
            '/v1/checkout/sessions',
            $request['url'],
        );

        $params = $request['params'];

        $this->assertSame('subscription', $params['mode']);
        $this->assertSame('cus_existing_123', $params['customer']);

        $this->assertSame(
            'aed',
            $params['line_items'][0]['price_data']['currency'],
        );

        $this->assertSame(
            1050,
            (int) $params['line_items'][0]['price_data']['unit_amount'],
        );

        $this->assertSame(
            'month',
            $params['line_items'][0]['price_data']['recurring']['interval'],
        );

        $this->assertSame(
            'Service Contract CTR-TEST-001',
            $params['line_items'][0]['price_data']['product_data']['name'],
        );

        $this->assertSame(
            'https://example.test/contracts/success',
            $params['success_url'],
        );

        $this->assertSame(
            'https://example.test/contracts/cancel',
            $params['cancel_url'],
        );
    }

    public function test_yearly_billing_maps_to_stripe_year_interval(): void
    {
        $data = $this->checkoutData([
            'billingInterval' => 'YEARLY',
            'recurringAmount' => '120.000000',
        ]);

        $this->httpClient->queueResponse(
            200,
            $this->checkoutSessionBody(),
        );

        $this->gateway()->createSubscriptionCheckout($data);

        $params = $this->httpClient->requests[0]['params'];

        $this->assertSame(
            'year',
            $params['line_items'][0]['price_data']['recurring']['interval'],
        );

        $this->assertSame(
            12000,
            (int) $params['line_items'][0]['price_data']['unit_amount'],
        );
    }

    public function test_checkout_sends_only_expected_safe_metadata(): void
    {
        $data = $this->checkoutData();

        $this->httpClient->queueResponse(
            200,
            $this->checkoutSessionBody(),
        );

        $this->gateway()->createSubscriptionCheckout($data);

        $params = $this->httpClient->requests[0]['params'];

        $expected = [
            'service_contract_uuid' => $data->contractUuid,
            'service_contract_billing_uuid' => $data->contractBillingUuid,
        ];

        $this->assertSame($expected, $params['metadata']);
        $this->assertSame(
            $expected,
            $params['subscription_data']['metadata'],
        );

        foreach ([
            'password',
            'jwt',
            'access_token',
            'refresh_token',
            'card_number',
            'cvc',
            'recurring_amount',
        ] as $forbiddenKey) {
            $this->assertArrayNotHasKey(
                $forbiddenKey,
                $params['metadata'],
            );

            $this->assertArrayNotHasKey(
                $forbiddenKey,
                $params['subscription_data']['metadata'],
            );
        }
    }

    public function test_checkout_never_sends_subscription_data_cancel_at(): void
    {
        $this->httpClient->queueResponse(
            200,
            $this->checkoutSessionBody(),
        );

        $this->gateway()->createSubscriptionCheckout(
            $this->checkoutData(),
        );

        $subscriptionData =
            $this->httpClient->requests[0]['params']['subscription_data'];

        $this->assertArrayNotHasKey('cancel_at', $subscriptionData);
    }

    public function test_existing_customer_skips_customer_creation_and_uses_checkout_idempotency_key(): void
    {
        $this->httpClient->queueResponse(
            200,
            $this->checkoutSessionBody(),
        );

        $this->gateway()->createSubscriptionCheckout(
            $this->checkoutData([
                'providerIdempotencyKey' => 'checkout_stable_123',
            ]),
        );

        $this->assertCount(1, $this->httpClient->requests);

        $request = $this->httpClient->requests[0];

        $this->assertStringContainsString(
            '/v1/checkout/sessions',
            $request['url'],
        );

        $this->assertSame(
            'Idempotency-Key: checkout_stable_123',
            $this->idempotencyHeader($request),
        );
    }

    public function test_missing_customer_creates_customer_then_reuses_it_for_checkout(): void
    {
        $data = $this->checkoutData([
            'stripeCustomerId' => null,
            'customerIdempotencyKey' => 'customer_stable_456',
            'providerIdempotencyKey' => 'checkout_stable_456',
        ]);

        $this->httpClient->queueResponse(
            200,
            $this->customerBody(['id' => 'cus_created_456']),
        );

        $this->httpClient->queueResponse(
            200,
            $this->checkoutSessionBody([
                'customer' => 'cus_created_456',
            ]),
        );

        $result = $this->gateway()->createSubscriptionCheckout($data);

        $this->assertCount(2, $this->httpClient->requests);

        $customerRequest = $this->httpClient->requests[0];
        $checkoutRequest = $this->httpClient->requests[1];

        $this->assertStringContainsString(
            '/v1/customers',
            $customerRequest['url'],
        );

        $this->assertSame(
            'customer@example.com',
            $customerRequest['params']['email'],
        );

        $this->assertSame(
            'Idempotency-Key: customer_stable_456',
            $this->idempotencyHeader($customerRequest),
        );

        $this->assertStringContainsString(
            '/v1/checkout/sessions',
            $checkoutRequest['url'],
        );

        $this->assertSame(
            'cus_created_456',
            $checkoutRequest['params']['customer'],
        );

        $this->assertSame(
            'Idempotency-Key: checkout_stable_456',
            $this->idempotencyHeader($checkoutRequest),
        );

        $this->assertSame(
            'cus_created_456',
            $result->stripeCustomerId,
        );
    }

    public function test_retry_reuses_the_same_checkout_idempotency_key(): void
    {
        $data = $this->checkoutData([
            'providerIdempotencyKey' => 'checkout_retry_same_789',
        ]);

        $this->httpClient->queueResponse(
            200,
            $this->checkoutSessionBody(),
        );

        $this->httpClient->queueResponse(
            200,
            $this->checkoutSessionBody(),
        );

        $this->gateway()->createSubscriptionCheckout($data);
        $this->gateway()->createSubscriptionCheckout($data);

        $this->assertCount(2, $this->httpClient->requests);

        $first = $this->idempotencyHeader(
            $this->httpClient->requests[0],
        );

        $second = $this->idempotencyHeader(
            $this->httpClient->requests[1],
        );

        $this->assertSame($first, $second);
        $this->assertSame(
            'Idempotency-Key: checkout_retry_same_789',
            $first,
        );
    }

    // -----------------------------------------------------------------
    // Stripe error classification
    // -----------------------------------------------------------------

    public function test_429_rate_limit_is_unknown_not_definitive_failure(): void
    {
        $this->httpClient->queueResponse(
            429,
            $this->apiErrorBody(
                'invalid_request_error',
                'Too many requests.',
            ),
        );

        $result = $this->gateway()->createSubscriptionCheckout(
            $this->checkoutData(),
        );

        $this->assertSame(
            ContractBillingCheckoutOutcome::UNKNOWN,
            $result->outcome,
        );
    }

    public function test_401_authentication_error_is_definitive_failure(): void
    {
        $this->httpClient->queueResponse(
            401,
            $this->apiErrorBody(
                'authentication_error',
                'Invalid API key.',
            ),
        );

        $result = $this->gateway()->createSubscriptionCheckout(
            $this->checkoutData(),
        );

        $this->assertSame(
            ContractBillingCheckoutOutcome::DEFINITIVE_FAILURE,
            $result->outcome,
        );

        $this->assertSame(
            'STRIPE_REQUEST_REJECTED',
            $result->failureCode,
        );
    }

    public function test_400_invalid_request_is_definitive_failure(): void
    {
        $this->httpClient->queueResponse(
            400,
            $this->apiErrorBody(
                'invalid_request_error',
                'Invalid parameter.',
            ),
        );

        $result = $this->gateway()->createSubscriptionCheckout(
            $this->checkoutData(),
        );

        $this->assertSame(
            ContractBillingCheckoutOutcome::DEFINITIVE_FAILURE,
            $result->outcome,
        );

        $this->assertSame(
            'STRIPE_REQUEST_REJECTED',
            $result->failureCode,
        );
    }

    public function test_500_stripe_error_is_unknown(): void
    {
        $this->httpClient->queueResponse(
            500,
            $this->apiErrorBody(
                'api_error',
                'Internal server error.',
            ),
        );

        $result = $this->gateway()->createSubscriptionCheckout(
            $this->checkoutData(),
        );

        $this->assertSame(
            ContractBillingCheckoutOutcome::UNKNOWN,
            $result->outcome,
        );
    }

    public function test_connection_failure_is_unknown_and_not_thrown(): void
    {
        $this->httpClient->queueException(
            ApiConnectionException::factory(
                'Could not connect to Stripe.',
            ),
        );

        $result = $this->gateway()->createSubscriptionCheckout(
            $this->checkoutData(),
        );

        $this->assertSame(
            ContractBillingCheckoutOutcome::UNKNOWN,
            $result->outcome,
        );
    }

    // -----------------------------------------------------------------
    // Provider cancellation operations
    // -----------------------------------------------------------------

    public function test_schedule_subscription_cancel_at_updates_the_subscription(): void
    {
        $this->httpClient->queueResponse(
            200,
            $this->subscriptionBody([
                'cancel_at' => 1782678400,
            ]),
        );

        $this->gateway()->scheduleSubscriptionCancelAt(
            'sub_contract_123',
            1782678400,
        );

        $this->assertCount(1, $this->httpClient->requests);

        $request = $this->httpClient->requests[0];

        $this->assertSame('post', $request['method']);

        $this->assertStringContainsString(
            '/v1/subscriptions/sub_contract_123',
            $request['url'],
        );

        $this->assertSame(
            1782678400,
            (int) $request['params']['cancel_at'],
        );
    }

    public function test_cancel_subscription_calls_the_subscription_cancel_endpoint(): void
    {
        $this->httpClient->queueResponse(
            200,
            $this->subscriptionBody([
                'status' => 'canceled',
                'canceled_at' => 1781000000,
            ]),
        );

        $this->gateway()->cancelSubscription(
            'sub_contract_123',
        );

        $this->assertCount(1, $this->httpClient->requests);

        $request = $this->httpClient->requests[0];

        $this->assertSame('delete', $request['method']);

        $this->assertStringContainsString(
            '/v1/subscriptions/sub_contract_123',
            $request['url'],
        );
    }

    // -----------------------------------------------------------------
    // Webhook signature / normalization
    // -----------------------------------------------------------------

    public function test_signed_checkout_completed_event_verifies_and_normalizes(): void
    {
        $event = [
            'id' => 'evt_checkout_completed_1',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => $this->checkoutSessionBody([
                    'subscription' => 'sub_contract_123',
                ]),
            ],
        ];

        [
            'body' => $body,
            'header' => $header,
        ] = $this->signedStripePayload($event);

        $gateway = $this->gateway();

        $verified = $gateway->verifyWebhook(
            $body,
            ['Stripe-Signature' => $header],
        );

        $this->assertTrue($verified->valid);

        $normalized = $gateway->parseWebhook(
            $verified->providerEvent,
        );

        $this->assertSame(
            'evt_checkout_completed_1',
            $normalized->providerEventId,
        );

        $this->assertSame(
            'checkout.session.completed',
            $normalized->eventType,
        );

        $this->assertSame(
            '11111111-1111-4111-8111-111111111111',
            $normalized->contractBillingUuid,
        );

        $this->assertSame(
            'sub_contract_123',
            $normalized->stripeSubscriptionId,
        );

        $this->assertSame(
            'cus_existing_123',
            $normalized->stripeCustomerId,
        );

        $this->assertSame(
            'cs_test_contract_123',
            $normalized->stripeCheckoutSessionId,
        );
    }

    public function test_subscription_event_reads_price_product_and_item_periods(): void
    {
        $event = [
            'id' => 'evt_subscription_created_1',
            'object' => 'event',
            'type' => 'customer.subscription.created',
            'data' => [
                'object' => $this->subscriptionBody(),
            ],
        ];

        [
            'body' => $body,
            'header' => $header,
        ] = $this->signedStripePayload($event);

        $gateway = $this->gateway();

        $verified = $gateway->verifyWebhook(
            $body,
            ['Stripe-Signature' => $header],
        );

        $this->assertTrue($verified->valid);

        $normalized = $gateway->parseWebhook(
            $verified->providerEvent,
        );

        $this->assertSame(
            'sub_contract_123',
            $normalized->stripeSubscriptionId,
        );

        $this->assertSame(
            'price_contract_123',
            $normalized->stripePriceId,
        );

        $this->assertSame(
            'prod_contract_123',
            $normalized->stripeProductId,
        );

        $this->assertSame(
            'active',
            $normalized->subscriptionStatus,
        );

        $this->assertFalse(
            $normalized->cancelAtPeriodEnd,
        );

        $this->assertSame(
            gmdate('Y-m-d H:i:s', 1780000000),
            $normalized->currentPeriodStart,
        );

        $this->assertSame(
            gmdate('Y-m-d H:i:s', 1782678400),
            $normalized->currentPeriodEnd,
        );
    }

    public function test_subscription_deleted_normalizes_cancellation_metadata(): void
    {
        $event = [
            'id' => 'evt_subscription_deleted_1',
            'object' => 'event',
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => $this->subscriptionBody([
                    'status' => 'canceled',
                    'cancel_at_period_end' => true,
                    'cancel_at' => 1782678400,
                    'canceled_at' => 1782000000,
                ]),
            ],
        ];

        [
            'body' => $body,
            'header' => $header,
        ] = $this->signedStripePayload($event);

        $gateway = $this->gateway();

        $verified = $gateway->verifyWebhook(
            $body,
            ['Stripe-Signature' => $header],
        );

        $normalized = $gateway->parseWebhook(
            $verified->providerEvent,
        );

        $this->assertSame(
            'canceled',
            $normalized->subscriptionStatus,
        );

        $this->assertTrue(
            $normalized->cancelAtPeriodEnd,
        );

        $this->assertSame(
            gmdate('Y-m-d H:i:s', 1782678400),
            $normalized->cancelAt,
        );

        $this->assertSame(
            gmdate('Y-m-d H:i:s', 1782000000),
            $normalized->canceledAt,
        );
    }

    public function test_invoice_paid_reads_subscription_details_metadata_and_line_period(): void
    {
        $event = [
            'id' => 'evt_invoice_paid_1',
            'object' => 'event',
            'type' => 'invoice.paid',
            'data' => [
                'object' => $this->invoiceBody(),
            ],
        ];

        [
            'body' => $body,
            'header' => $header,
        ] = $this->signedStripePayload($event);

        $gateway = $this->gateway();

        $verified = $gateway->verifyWebhook(
            $body,
            ['Stripe-Signature' => $header],
        );

        $this->assertTrue($verified->valid);

        $normalized = $gateway->parseWebhook(
            $verified->providerEvent,
        );

        $this->assertSame(
            '11111111-1111-4111-8111-111111111111',
            $normalized->contractBillingUuid,
        );

        $this->assertSame(
            'sub_contract_123',
            $normalized->stripeSubscriptionId,
        );

        $this->assertTrue(
            $normalized->invoicePaid,
        );

        $this->assertSame(
            gmdate('Y-m-d H:i:s', 1780000000),
            $normalized->currentPeriodStart,
        );

        $this->assertSame(
            gmdate('Y-m-d H:i:s', 1782678400),
            $normalized->currentPeriodEnd,
        );
    }

    public function test_invoice_payment_failed_normalizes_invoice_paid_false(): void
    {
        $event = [
            'id' => 'evt_invoice_failed_1',
            'object' => 'event',
            'type' => 'invoice.payment_failed',
            'data' => [
                'object' => $this->invoiceBody([
                    'status' => 'open',
                ]),
            ],
        ];

        [
            'body' => $body,
            'header' => $header,
        ] = $this->signedStripePayload($event);

        $gateway = $this->gateway();

        $verified = $gateway->verifyWebhook(
            $body,
            ['Stripe-Signature' => $header],
        );

        $normalized = $gateway->parseWebhook(
            $verified->providerEvent,
        );

        $this->assertFalse(
            $normalized->invoicePaid,
        );
    }

    public function test_tampered_webhook_payload_is_rejected(): void
    {
        $event = [
            'id' => 'evt_tampered_contract',
            'object' => 'event',
            'type' => 'invoice.paid',
            'data' => [
                'object' => $this->invoiceBody(),
            ],
        ];

        [
            'header' => $header,
        ] = $this->signedStripePayload($event);

        $tampered = array_merge(
            $event,
            ['type' => 'invoice.payment_failed'],
        );

        $tamperedBody = json_encode(
            $tampered,
            JSON_THROW_ON_ERROR,
        );

        $verified = $this->gateway()->verifyWebhook(
            $tamperedBody,
            ['Stripe-Signature' => $header],
        );

        $this->assertFalse($verified->valid);
    }

    public function test_signature_from_another_webhook_secret_is_rejected(): void
    {
        $event = [
            'id' => 'evt_wrong_secret_contract',
            'object' => 'event',
            'type' => 'invoice.paid',
            'data' => [
                'object' => $this->invoiceBody(),
            ],
        ];

        [
            'body' => $body,
            'header' => $header,
        ] = $this->signedStripePayload(
            $event,
            self::OTHER_WEBHOOK_SECRET,
        );

        $verified = $this->gateway()->verifyWebhook(
            $body,
            ['Stripe-Signature' => $header],
        );

        $this->assertFalse($verified->valid);
    }

    public function test_missing_signature_header_is_rejected(): void
    {
        $verified = $this->gateway()->verifyWebhook(
            '{}',
            [],
        );

        $this->assertFalse($verified->valid);
    }

    public function test_authentic_unrelated_event_is_safely_unrecognized(): void
    {
        $event = [
            'id' => 'evt_unrelated_contract',
            'object' => 'event',
            'type' => 'customer.created',
            'data' => [
                'object' => [
                    'id' => 'cus_unrelated',
                    'object' => 'customer',
                ],
            ],
        ];

        [
            'body' => $body,
            'header' => $header,
        ] = $this->signedStripePayload($event);

        $gateway = $this->gateway();

        $verified = $gateway->verifyWebhook(
            $body,
            ['Stripe-Signature' => $header],
        );

        $this->assertTrue($verified->valid);

        $normalized = $gateway->parseWebhook(
            $verified->providerEvent,
        );

        $this->assertSame(
            'customer.created',
            $normalized->eventType,
        );

        $this->assertNull(
            $normalized->contractBillingUuid,
        );

        $this->assertNull(
            $normalized->stripeSubscriptionId,
        );

        $this->assertNull(
            $normalized->stripeCheckoutSessionId,
        );
    }

    // -----------------------------------------------------------------
    // Transport isolation
    // -----------------------------------------------------------------

    public function test_every_stripe_api_call_is_routed_through_fake_transport(): void
    {
        $this->httpClient->queueResponse(
            200,
            $this->checkoutSessionBody(),
        );

        $this->gateway()->createSubscriptionCheckout(
            $this->checkoutData(),
        );

        $this->assertCount(
            1,
            $this->httpClient->requests,
        );
    }
}
