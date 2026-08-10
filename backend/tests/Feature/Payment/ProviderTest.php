<?php

namespace Tests\Feature\Payment;

use App\Support\Payment\Gateway\FakePaymentGateway;
use App\Support\Payment\Gateway\PaymentCreationData;
use App\Support\Payment\Gateway\PaymentCreationResult;
use App\Support\Payment\Gateway\PaymentGateway;
use App\Support\Payment\Gateway\PaymentGatewayNotConfiguredException;
use App\Support\Payment\Gateway\StripePaymentGateway;
use App\Support\Payment\Gateway\VerifiedWebhookResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Payment\Concerns\CreatesPaymentFixtures;
use Tests\TestCase;

class ProviderTest extends TestCase
{
    use CreatesPaymentFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    public function test_the_testing_environment_always_binds_the_fake_gateway(): void
    {
        $this->assertInstanceOf(FakePaymentGateway::class, app(PaymentGateway::class));
    }

    public function test_fake_create_success_stores_provider_session_reference(): void
    {
        $customer = $this->readyForPaymentCustomer();

        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $row = $this->paymentRow($response->json('data.payment.uuid'));

        $this->assertNotNull($row->provider_session_reference);
        $this->assertSame('FAKE', $row->provider_code);
        $this->assertNotNull($response->json('data.payment.client_secret'));
    }

    public function test_definitive_provider_create_failure_marks_failed_releases_hold_and_restores_cart(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $cartUuid = $this->getCheckout($customer['access_token'])->json('data.checkout.cart_uuid');
        $holdUuid = $this->getCheckout($customer['access_token'])->json('data.checkout.appointment.hold_uuid');

        $this->fakeGateway()->queueNextCreation(PaymentCreationResult::definitiveFailure('BAD_PARAMS', 'invalid'));

        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());

        $response->assertStatus(200);
        $this->assertSame('FAILED', $response->json('data.payment.status'));
        $this->assertSame('ACTIVE', $this->cartStatusCode($cartUuid));

        $hold = DB::table('appointment_holds')->where('id', UuidBinary::toBinary($holdUuid))->first();
        $this->assertNotNull($hold->released_at);

        $this->assertSame(0, DB::table('bookings')->count());
    }

    public function test_unknown_create_outcome_leaves_attempt_pending_without_a_duplicate(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $key = (string) Str::uuid();

        $this->fakeGateway()->queueNextCreation(PaymentCreationResult::unknown('connection reset'));

        $first = $this->createPayment($customer['access_token'], $key);
        $first->assertStatus(201);
        $this->assertSame('PENDING', $first->json('data.payment.status'));
        $this->assertNull($this->paymentRow($first->json('data.payment.uuid'))->provider_session_reference);

        $this->createPayment($customer['access_token'], $key)->assertStatus(200);

        $this->assertSame(1, DB::table('payment_attempts')->count());
    }

    public function test_retry_after_unknown_outcome_reuses_the_same_provider_idempotency_key(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $key = (string) Str::uuid();

        $this->fakeGateway()->queueNextCreation(PaymentCreationResult::unknown());
        $this->createPayment($customer['access_token'], $key)->assertStatus(201);

        $this->createPayment($customer['access_token'], $key)->assertStatus(200);

        $calls = $this->fakeGateway()->createPaymentCalls;
        $this->assertCount(2, $calls);
        $this->assertSame($calls[0]->providerIdempotencyKey, $calls[1]->providerIdempotencyKey);
    }

    public function test_unconfigured_stripe_gateway_fails_safely_without_a_network_call(): void
    {
        $gateway = new StripePaymentGateway(secretKey: null, webhookSecret: null);

        try {
            $gateway->createPayment(new PaymentCreationData(
                paymentAttemptUuid: UuidBinary::generate(),
                checkoutReference: 'x',
                amount: '100.000000',
                currencyCode: 'AED',
                currencyMinorUnit: 2,
                providerIdempotencyKey: 'blue_x',
            ));
            $this->fail('Expected PaymentGatewayNotConfiguredException.');
        } catch (PaymentGatewayNotConfiguredException) {
            $this->addToAssertionCount(1);
        }

        $verified = $gateway->verifyWebhook('{}', ['Stripe-Signature' => 'anything']);
        $this->assertInstanceOf(VerifiedWebhookResult::class, $verified);
        $this->assertFalse($verified->valid);
    }
}
