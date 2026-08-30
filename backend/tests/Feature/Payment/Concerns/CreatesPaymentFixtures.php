<?php

namespace Tests\Feature\Payment\Concerns;

use App\Support\Payment\Gateway\FakePaymentGateway;
use App\Support\Payment\Gateway\PaymentGateway;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Checkout\Concerns\CreatesCheckoutFixtures;

/**
 * Payment-specific fixture builders and HTTP helpers, layered on top of the
 * Phase 5 CreatesCheckoutFixtures - every Payment test needs a cart that
 * has already reached ready_for_payment === true to even reach POST
 * /v1/payments in the first place.
 */
trait CreatesPaymentFixtures
{
    use CreatesCheckoutFixtures;

    /**
     * The FakePaymentGateway singleton bound for the "testing" environment
     * (see App\Providers\PaymentServiceProvider) - resolving it through the
     * container guarantees tests configure the exact same instance
     * CreatePaymentAttemptAction / ProcessPaymentWebhookAction will use.
     */
    private function fakeGateway(): FakePaymentGateway
    {
        return app(PaymentGateway::class);
    }

    /**
     * @param  array<string, mixed>  $slotOverrides  Forwarded to createAppointmentSlot() - e.g. to give two customers
     *                                               in the same test distinct, non-colliding slot periods without
     *                                               using Carbon::travel() (which would also age out access tokens).
     * @return array{user_uuid: string, access_token: string}
     */
    private function readyForPaymentCustomer(array $slotOverrides = []): array
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'quantity' => 1,
        ])->assertStatus(201);

        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);

        $slot = $this->createAppointmentSlot($slotOverrides);
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);

        return $customer;
    }

    private function createPayment(string $accessToken, ?string $idempotencyKey = null, ?string $paymentMethod = 'CARD'): TestResponse
    {
        $headers = ['Authorization' => 'Bearer '.$accessToken];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $body = $paymentMethod === null ? [] : ['payment_method' => $paymentMethod];

        return $this->postJson('/api/v1/payments', $body, $headers);
    }

    private function getPayment(string $accessToken, string $paymentUuid): TestResponse
    {
        return $this->getJson('/api/v1/payments/'.$paymentUuid, ['Authorization' => 'Bearer '.$accessToken]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fakeWebhookPayload(array $overrides = []): array
    {
        return array_merge([
            'event_id' => 'evt_'.UuidBinary::generate(),
            'event_type' => 'payment_intent.succeeded',
            'provider_session_reference' => null,
            'provider_transaction_reference' => null,
            'checkout_reference' => null,
            'outcome' => 'SUCCEEDED',
            'amount' => null,
            'currency' => null,
            'provider_status_code' => 'succeeded',
            'payment_method_type' => 'card',
            'failure_code' => null,
            'failure_message' => null,
        ], $overrides);
    }

    /**
     * BLUE V1 Phase B20 - the refund-lifecycle counterpart to
     * fakeWebhookPayload(). The 'kind' => 'refund' key is what
     * FakePaymentGateway::parseWebhook() uses to build a
     * NormalizedRefundEvent instead of a NormalizedPaymentEvent - see that
     * method's docblock.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fakeRefundWebhookPayload(array $overrides = []): array
    {
        return array_merge([
            'event_id' => 'evt_'.UuidBinary::generate(),
            'event_type' => 'refund.updated',
            'kind' => 'refund',
            'provider_refund_reference' => null,
            'provider_payment_reference' => null,
            'refund_status' => 'succeeded',
            'amount' => null,
            'currency' => null,
            'failure_code' => null,
            'failure_message' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(array $payload, ?string $signatureOverride = null): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = $signatureOverride ?? FakePaymentGateway::sign($body);

        return $this->call('POST', '/api/v1/payments/webhooks/stripe', [], [], [], [
            'HTTP_X_FAKE_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function paymentRow(string $paymentUuid): ?object
    {
        return DB::table('payment_attempts')->where('id', UuidBinary::toBinary($paymentUuid))->first();
    }

    private function cartRow(string $cartUuid): ?object
    {
        return DB::table('carts')->where('id', UuidBinary::toBinary($cartUuid))->first();
    }

    private function cartStatusCode(string $cartUuid): ?string
    {
        return DB::table('carts')
            ->join('cart_statuses', 'cart_statuses.id', '=', 'carts.status_id')
            ->where('carts.id', UuidBinary::toBinary($cartUuid))
            ->value('cart_statuses.code');
    }

    private function paymentStatusCode(object $row): string
    {
        return DB::table('payment_statuses')->where('id', $row->status_id)->value('code');
    }

    private function webhookLedgerRow(string $providerEventId): ?object
    {
        return DB::table('payment_webhook_events')->where('provider_event_id', $providerEventId)->first();
    }

    private function webhookLedgerStatusCode(string $providerEventId): ?string
    {
        return DB::table('payment_webhook_events')
            ->join('payment_webhook_event_statuses', 'payment_webhook_event_statuses.id', '=', 'payment_webhook_events.status_id')
            ->where('payment_webhook_events.provider_event_id', $providerEventId)
            ->value('payment_webhook_event_statuses.code');
    }
}
