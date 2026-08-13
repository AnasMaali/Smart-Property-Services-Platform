<?php

namespace Tests\Feature\Payment;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Payment\Concerns\CreatesPaymentFixtures;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use CreatesPaymentFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    private function createPendingPayment(): array
    {
        $customer = $this->readyForPaymentCustomer();
        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $row = $this->paymentRow($response->json('data.payment.uuid'));

        return ['customer' => $customer, 'row' => $row];
    }

    public function test_webhook_route_has_no_customer_auth_requirement(): void
    {
        $response = $this->postWebhook($this->fakeWebhookPayload());

        $this->assertNotSame(401, $response->getStatusCode());
    }

    public function test_invalid_signature_is_rejected_and_mutates_nothing(): void
    {
        ['row' => $row] = $this->createPendingPayment();

        $payload = $this->fakeWebhookPayload(['provider_session_reference' => $row->provider_session_reference]);
        $response = $this->postWebhook($payload, signatureOverride: 'not-the-right-signature');

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('payment_webhook_events')->count());

        $fresh = $this->paymentRow($this->uuidOf($row));
        $this->assertSame('PENDING', $this->paymentStatusCode($fresh));
    }

    public function test_valid_success_event_transitions_payment_to_successful(): void
    {
        ['row' => $row] = $this->createPendingPayment();

        $response = $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $row->provider_session_reference,
            'provider_transaction_reference' => 'txn_test_1',
            'outcome' => 'SUCCEEDED',
            'amount' => $row->requested_amount,
            'currency' => 'AED',
        ]));

        $response->assertStatus(200);

        $fresh = $this->paymentRow($this->uuidOf($row));
        $this->assertSame('SUCCESSFUL', $this->paymentStatusCode($fresh));
        $this->assertSame(0, (int) $fresh->requires_reconciliation);
        $this->assertNotNull($fresh->successful_at);
        $this->assertNotNull($fresh->finalized_at);
        $this->assertSame('txn_test_1', $fresh->provider_transaction_reference);
    }

    public function test_valid_failed_style_event_keeps_attempt_pending_not_failed(): void
    {
        ['row' => $row] = $this->createPendingPayment();

        // A declined card keeps the Stripe PaymentIntent recoverable
        // (requires_payment_method) - BLUE must stay PENDING, never FAILED.
        $response = $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'NON_TERMINAL',
            'provider_status_code' => 'requires_payment_method',
            'event_type' => 'payment_intent.payment_failed',
        ]));

        $response->assertStatus(200);

        $fresh = $this->paymentRow($this->uuidOf($row));
        $this->assertSame('PENDING', $this->paymentStatusCode($fresh));
        $this->assertNull($fresh->finalized_at);
    }

    public function test_valid_canceled_event_transitions_to_cancelled_and_restores_cart(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $cartUuid = $this->getCheckout($customer['access_token'])->json('data.checkout.cart_uuid');
        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $row = $this->paymentRow($response->json('data.payment.uuid'));

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'CANCELED',
            'provider_status_code' => 'canceled',
        ]))->assertStatus(200);

        $fresh = $this->paymentRow($this->uuidOf($row));
        $this->assertSame('CANCELLED', $this->paymentStatusCode($fresh));
        $this->assertSame('ACTIVE', $this->cartStatusCode($cartUuid));
    }

    public function test_duplicate_event_is_idempotent(): void
    {
        ['row' => $row] = $this->createPendingPayment();
        $eventId = 'evt_duplicate_test';

        $payload = $this->fakeWebhookPayload([
            'event_id' => $eventId,
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $row->requested_amount,
        ]);

        $this->postWebhook($payload)->assertStatus(200);
        $successfulAt = $this->paymentRow($this->uuidOf($row))->successful_at;

        $this->postWebhook($payload)->assertStatus(200);

        $this->assertSame(1, DB::table('payment_webhook_events')->where('provider_event_id', $eventId)->count());
        $this->assertSame($successfulAt, $this->paymentRow($this->uuidOf($row))->successful_at);
    }

    public function test_event_ledger_row_is_created_with_payload_hash_and_no_raw_payload(): void
    {
        ['row' => $row] = $this->createPendingPayment();
        $eventId = 'evt_ledger_test';

        $payload = $this->fakeWebhookPayload([
            'event_id' => $eventId,
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $row->requested_amount,
        ]);

        $this->postWebhook($payload)->assertStatus(200);

        $ledger = $this->webhookLedgerRow($eventId);
        $this->assertNotNull($ledger);
        $this->assertSame(32, strlen($ledger->payload_hash));
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR), true), $ledger->payload_hash);
        $this->assertSame('PROCESSED', $this->webhookLedgerStatusCode($eventId));

        $columns = array_keys(get_object_vars($ledger));
        $this->assertNotContains('payload', $columns, 'payment_webhook_events must never store a raw payload column.');
        $this->assertNotContains('raw_payload', $columns, 'payment_webhook_events must never store a raw payload column.');
    }

    public function test_stale_out_of_order_event_cannot_regress_terminal_status(): void
    {
        ['row' => $row] = $this->createPendingPayment();

        $this->postWebhook($this->fakeWebhookPayload([
            'event_id' => 'evt_success_first',
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $row->requested_amount,
        ]))->assertStatus(200);

        $this->postWebhook($this->fakeWebhookPayload([
            'event_id' => 'evt_stale_cancel_after',
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'CANCELED',
        ]))->assertStatus(200);

        $fresh = $this->paymentRow($this->uuidOf($row));
        $this->assertSame('SUCCESSFUL', $this->paymentStatusCode($fresh));
        $this->assertSame('IGNORED', $this->webhookLedgerStatusCode('evt_stale_cancel_after'));
    }

    public function test_unknown_authentic_event_is_safely_ignored(): void
    {
        ['row' => $row] = $this->createPendingPayment();

        $this->postWebhook($this->fakeWebhookPayload([
            'event_id' => 'evt_unrecognized',
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'UNRECOGNIZED',
            'event_type' => 'charge.refund.updated',
        ]))->assertStatus(200);

        $fresh = $this->paymentRow($this->uuidOf($row));
        $this->assertSame('PENDING', $this->paymentStatusCode($fresh));
        $this->assertSame('IGNORED', $this->webhookLedgerStatusCode('evt_unrecognized'));
    }

    /**
     * Regression guard for a live Stripe Sandbox reproduction: an authentic
     * event unrelated to any PaymentIntent (e.g. customer.created) carries
     * no provider_session_reference/checkout_reference at all, so
     * resolveAttempt() can never find a local match. Before the fix,
     * process() resolved the attempt before branching on outcome, so this
     * always fell into the FAILED/PAYMENT_ATTEMPT_NOT_FOUND branch even
     * though the event is authentic and simply not one BLUE acts on. It
     * must instead be finalized as IGNORED, with no payment/Booking
     * mutation of any kind.
     */
    public function test_authentic_unrelated_event_with_no_resolvable_reference_is_ignored_not_failed(): void
    {
        ['row' => $row] = $this->createPendingPayment();
        $eventId = 'evt_customer_created';

        $response = $this->postWebhook($this->fakeWebhookPayload([
            'event_id' => $eventId,
            'event_type' => 'customer.created',
            'outcome' => 'UNRECOGNIZED',
            'provider_session_reference' => null,
            'checkout_reference' => null,
        ]));

        $response->assertStatus(200);

        $ledger = $this->webhookLedgerRow($eventId);
        $this->assertNotNull($ledger);
        $this->assertSame('IGNORED', $this->webhookLedgerStatusCode($eventId));
        $this->assertNull($ledger->payment_attempt_id);
        $this->assertNull($ledger->last_error_code);
        $this->assertNull($ledger->last_error_message);
        $this->assertNotNull($ledger->processed_at);

        // Zero payment mutation: the unrelated pre-existing PENDING
        // attempt must be completely untouched.
        $fresh = $this->paymentRow($this->uuidOf($row));
        $this->assertSame('PENDING', $this->paymentStatusCode($fresh));
        $this->assertSame(0, (int) $fresh->requires_reconciliation);
        $this->assertNull($fresh->finalized_at);
        $this->assertNull($fresh->successful_at);

        // Zero Booking mutation.
        $this->assertSame(0, DB::table('bookings')->count());
    }

    public function test_duplicate_unrelated_event_remains_idempotent(): void
    {
        $eventId = 'evt_customer_created_dup';

        $payload = $this->fakeWebhookPayload([
            'event_id' => $eventId,
            'event_type' => 'customer.created',
            'outcome' => 'UNRECOGNIZED',
            'provider_session_reference' => null,
            'checkout_reference' => null,
        ]);

        $this->postWebhook($payload)->assertStatus(200);
        $this->postWebhook($payload)->assertStatus(200);

        $this->assertSame(1, DB::table('payment_webhook_events')->where('provider_event_id', $eventId)->count());
        $this->assertSame('IGNORED', $this->webhookLedgerStatusCode($eventId));
        $this->assertSame(0, DB::table('bookings')->count());
    }

    /**
     * The distinction the fix must preserve: a SUPPORTED PaymentIntent
     * event (outcome !== UNRECOGNIZED) that genuinely cannot resolve a
     * local attempt must still fail as PAYMENT_ATTEMPT_NOT_FOUND - the
     * UNRECOGNIZED short-circuit must never weaken this path.
     */
    public function test_supported_payment_intent_event_with_no_matching_attempt_still_fails(): void
    {
        $eventId = 'evt_supported_no_match';

        $response = $this->postWebhook($this->fakeWebhookPayload([
            'event_id' => $eventId,
            'event_type' => 'payment_intent.succeeded',
            'outcome' => 'SUCCEEDED',
            'provider_session_reference' => 'pi_does_not_exist_locally',
            'checkout_reference' => null,
        ]));

        $response->assertStatus(200);

        $ledger = $this->webhookLedgerRow($eventId);
        $this->assertNotNull($ledger);
        $this->assertSame('FAILED', $this->webhookLedgerStatusCode($eventId));
        $this->assertNull($ledger->payment_attempt_id);
        $this->assertSame('PAYMENT_ATTEMPT_NOT_FOUND', $ledger->last_error_code);
        $this->assertNotNull($ledger->last_error_message);
        $this->assertSame(0, DB::table('bookings')->count());
    }

    public function test_webhook_response_never_exposes_internal_details(): void
    {
        ['row' => $row] = $this->createPendingPayment();

        $response = $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $row->requested_amount,
        ]));

        $body = $response->json();
        $this->assertArrayNotHasKey('data', $body);
    }

    private function uuidOf(object $row): string
    {
        return UuidBinary::toString($row->id);
    }
}
