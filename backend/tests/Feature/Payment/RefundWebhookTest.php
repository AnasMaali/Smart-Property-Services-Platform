<?php

namespace Tests\Feature\Payment;

use App\Actions\Payment\ExecuteBookingRefundAction;
use App\Support\Booking\BookingRefundStatuses;
use App\Support\Payment\Gateway\RefundCreationResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Booking\Concerns\CreatesBookingFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B20 - proves App\Actions\Payment\ProcessPaymentWebhookAction
 * correctly finalizes a refund-lifecycle event (NormalizedRefundEvent)
 * against `booking_refunds`, reusing the SAME webhook endpoint/ledger a
 * payment-lifecycle event already uses (see tests/Feature/Payment/
 * WebhookTest.php for the payment-side equivalents this deliberately does
 * not duplicate).
 */
class RefundWebhookTest extends TestCase
{
    use CreatesBookingFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
        config([
            'cancellation.timezone' => 'UTC',
            'cancellation.before_appointment_day_percentage' => 100,
            'cancellation.appointment_day_percentage' => 75,
        ]);
    }

    /**
     * Cancels a fresh Booking whose synchronous Stripe refund attempt is
     * forced UNKNOWN (network timeout), leaving `booking_refunds` PENDING
     * and awaiting webhook confirmation - the case this suite exercises.
     */
    private function pendingRefund(): object
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => now()->addDays(2),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        $this->fakeGateway()->queueNextRefund(RefundCreationResult::unknown('simulated timeout'));

        $this->postJson(
            '/api/v1/bookings/'.UuidBinary::toString($booking->id).'/cancel',
            [],
            ['Authorization' => 'Bearer '.$customer['access_token']]
        )->assertStatus(200);

        return $this->bookingRefundRow($booking);
    }

    public function test_succeeded_refund_event_finalizes_a_pending_obligation(): void
    {
        $refund = $this->pendingRefund();
        $this->assertSame('PENDING', $this->bookingRefundStatusCode($refund));

        // The synchronous attempt returned UNKNOWN, so no
        // provider_refund_reference was recorded yet - resolution falls
        // back to matching the still-PENDING obligation by the original
        // payment's PaymentIntent reference.
        $paymentIntentRef = DB::table('payment_attempts')->where('id', $refund->payment_attempt_id)->value('provider_session_reference');

        $response = $this->postWebhook($this->fakeRefundWebhookPayload([
            'provider_refund_reference' => 'fake_re_test_2',
            'provider_payment_reference' => $paymentIntentRef,
            'refund_status' => 'succeeded',
        ]));

        $response->assertStatus(200);

        $fresh = DB::table('booking_refunds')->where('id', $refund->id)->first();
        $this->assertSame('SUCCEEDED', $this->bookingRefundStatusCode($fresh));
        $this->assertSame('fake_re_test_2', $fresh->provider_refund_reference);
        $this->assertNotNull($fresh->succeeded_at);
    }

    public function test_failed_refund_event_finalizes_a_pending_obligation_as_failed(): void
    {
        $refund = $this->pendingRefund();
        $paymentIntentRef = DB::table('payment_attempts')->where('id', $refund->payment_attempt_id)->value('provider_session_reference');

        $this->postWebhook($this->fakeRefundWebhookPayload([
            'provider_refund_reference' => 'fake_re_failed_1',
            'provider_payment_reference' => $paymentIntentRef,
            'refund_status' => 'failed',
            'failure_code' => 'insufficient_funds',
        ]))->assertStatus(200);

        $fresh = DB::table('booking_refunds')->where('id', $refund->id)->first();
        $this->assertSame('FAILED', $this->bookingRefundStatusCode($fresh));
        $this->assertSame('insufficient_funds', $fresh->failure_code);
        $this->assertNotNull($fresh->failed_at);
    }

    public function test_duplicate_refund_event_delivery_is_idempotent(): void
    {
        $refund = $this->pendingRefund();
        $paymentIntentRef = DB::table('payment_attempts')->where('id', $refund->payment_attempt_id)->value('provider_session_reference');
        $eventId = 'evt_refund_dup';

        $payload = $this->fakeRefundWebhookPayload([
            'event_id' => $eventId,
            'provider_refund_reference' => 'fake_re_dup',
            'provider_payment_reference' => $paymentIntentRef,
            'refund_status' => 'succeeded',
        ]);

        $this->postWebhook($payload)->assertStatus(200);
        $succeededAt = DB::table('booking_refunds')->where('id', $refund->id)->value('succeeded_at');

        $this->postWebhook($payload)->assertStatus(200);

        $this->assertSame(1, DB::table('payment_webhook_events')->where('provider_event_id', $eventId)->count());
        $this->assertSame($succeededAt, DB::table('booking_refunds')->where('id', $refund->id)->value('succeeded_at'));
    }

    public function test_refund_event_that_resolves_no_local_obligation_fails_ledger_without_mutation(): void
    {
        $eventId = 'evt_refund_unresolvable';

        $response = $this->postWebhook($this->fakeRefundWebhookPayload([
            'event_id' => $eventId,
            'provider_refund_reference' => 're_does_not_exist_locally',
            'provider_payment_reference' => 'pi_does_not_exist_locally',
            'refund_status' => 'succeeded',
        ]));

        $response->assertStatus(200);

        $ledger = DB::table('payment_webhook_events')->where('provider_event_id', $eventId)->first();
        $this->assertNotNull($ledger);
        $statusCode = DB::table('payment_webhook_event_statuses')->where('id', $ledger->status_id)->value('code');
        $this->assertSame('FAILED', $statusCode);
        $this->assertSame('REFUND_OBLIGATION_NOT_FOUND', $ledger->last_error_code);

        $this->assertSame(0, DB::table('booking_refunds')->where('status_id', BookingRefundStatuses::id('SUCCEEDED'))->count());
    }

    // -----------------------------------------------------------------
    // FIX PHASE item 5 (audit MEDIUM #2) - a 'succeeded' refund event's
    // own claimed amount/currency must match the obligation before it is
    // ever trusted to finalize it SUCCEEDED.
    // -----------------------------------------------------------------

    public function test_succeeded_refund_event_with_exact_amount_and_currency_succeeds(): void
    {
        $refund = $this->pendingRefund();
        $paymentIntentRef = DB::table('payment_attempts')->where('id', $refund->payment_attempt_id)->value('provider_session_reference');
        $currencyCode = DB::table('currencies')->where('id', $refund->currency_id)->value('code');

        $this->postWebhook($this->fakeRefundWebhookPayload([
            'provider_refund_reference' => 'fake_re_exact_match',
            'provider_payment_reference' => $paymentIntentRef,
            'refund_status' => 'succeeded',
            'amount' => (string) $refund->requested_amount,
            'currency' => $currencyCode,
        ]))->assertStatus(200);

        $fresh = DB::table('booking_refunds')->where('id', $refund->id)->first();
        $this->assertSame('SUCCEEDED', $this->bookingRefundStatusCode($fresh));
        $this->assertSame('fake_re_exact_match', $fresh->provider_refund_reference);
    }

    // -----------------------------------------------------------------
    // FIX PHASE 2 (financial safety) - an authoritative 'succeeded' event
    // whose amount/currency mismatches the obligation must NEVER be left
    // PENDING (the recovery command would treat it as safe to retry,
    // risking a SECOND real Stripe refund) - it is quarantined into the
    // terminal RECONCILIATION_REQUIRED status instead. BLUE V1 is
    // AED-only, so a currency mismatch is always an anomaly, never a
    // legitimate multi-currency case.
    // -----------------------------------------------------------------

    public function test_succeeded_refund_event_with_amount_mismatch_becomes_reconciliation_required(): void
    {
        $refund = $this->pendingRefund();
        $paymentIntentRef = DB::table('payment_attempts')->where('id', $refund->payment_attempt_id)->value('provider_session_reference');
        $eventId = 'evt_refund_amount_mismatch';

        $response = $this->postWebhook($this->fakeRefundWebhookPayload([
            'event_id' => $eventId,
            'provider_refund_reference' => 'fake_re_amount_mismatch',
            'provider_payment_reference' => $paymentIntentRef,
            'refund_status' => 'succeeded',
            'amount' => bcadd((string) $refund->requested_amount, '1.000000', 6),
        ]));

        $response->assertStatus(200);

        $fresh = DB::table('booking_refunds')->where('id', $refund->id)->first();
        $this->assertSame('RECONCILIATION_REQUIRED', $this->bookingRefundStatusCode($fresh));
        $this->assertNull($fresh->succeeded_at);
        $this->assertNotNull($fresh->failed_at);
        $this->assertSame('REFUND_AMOUNT_MISMATCH', $fresh->failure_code);
        // Never rewritten to match Stripe's unexpected figure.
        $this->assertSame((string) $refund->requested_amount, (string) $fresh->requested_amount);
        // The authentic provider reference IS preserved - safe/useful for
        // investigation.
        $this->assertSame('fake_re_amount_mismatch', $fresh->provider_refund_reference);
        // The actual Stripe-reported amount is preserved as evidence.
        $this->assertStringContainsString('Stripe reported refund amount', $fresh->failure_message);

        $ledger = DB::table('payment_webhook_events')->where('provider_event_id', $eventId)->first();
        $statusCode = DB::table('payment_webhook_event_statuses')->where('id', $ledger->status_id)->value('code');
        $this->assertSame('PROCESSED', $statusCode);
        $this->assertSame('REFUND_AMOUNT_MISMATCH', $ledger->last_error_code);
    }

    public function test_succeeded_refund_event_with_currency_mismatch_becomes_reconciliation_required(): void
    {
        $refund = $this->pendingRefund();
        $paymentIntentRef = DB::table('payment_attempts')->where('id', $refund->payment_attempt_id)->value('provider_session_reference');
        $eventId = 'evt_refund_currency_mismatch';

        $response = $this->postWebhook($this->fakeRefundWebhookPayload([
            'event_id' => $eventId,
            'provider_refund_reference' => 'fake_re_currency_mismatch',
            'provider_payment_reference' => $paymentIntentRef,
            'refund_status' => 'succeeded',
            'currency' => 'USD',
        ]));

        $response->assertStatus(200);

        $fresh = DB::table('booking_refunds')->where('id', $refund->id)->first();
        $this->assertSame('RECONCILIATION_REQUIRED', $this->bookingRefundStatusCode($fresh));
        $this->assertSame('REFUND_CURRENCY_MISMATCH', $fresh->failure_code);
        $this->assertStringContainsString('Stripe reported refund currency USD', $fresh->failure_message);

        $ledger = DB::table('payment_webhook_events')->where('provider_event_id', $eventId)->first();
        $statusCode = DB::table('payment_webhook_event_statuses')->where('id', $ledger->status_id)->value('code');
        $this->assertSame('PROCESSED', $statusCode);
        $this->assertSame('REFUND_CURRENCY_MISMATCH', $ledger->last_error_code);
    }

    public function test_duplicate_mismatched_refund_event_delivery_remains_idempotent(): void
    {
        $refund = $this->pendingRefund();
        $paymentIntentRef = DB::table('payment_attempts')->where('id', $refund->payment_attempt_id)->value('provider_session_reference');
        $eventId = 'evt_refund_mismatch_dup';

        $payload = $this->fakeRefundWebhookPayload([
            'event_id' => $eventId,
            'provider_refund_reference' => 'fake_re_mismatch_dup',
            'provider_payment_reference' => $paymentIntentRef,
            'refund_status' => 'succeeded',
            'amount' => bcadd((string) $refund->requested_amount, '1.000000', 6),
        ]);

        $this->postWebhook($payload)->assertStatus(200);
        $flaggedAt = DB::table('booking_refunds')->where('id', $refund->id)->value('failed_at');

        $this->postWebhook($payload)->assertStatus(200);

        $this->assertSame(1, DB::table('payment_webhook_events')->where('provider_event_id', $eventId)->count());
        $stillFlagged = DB::table('booking_refunds')->where('id', $refund->id)->first();
        $this->assertSame('RECONCILIATION_REQUIRED', $this->bookingRefundStatusCode($stillFlagged));
        $this->assertSame($flaggedAt, $stillFlagged->failed_at);
    }

    /**
     * A genuinely SECOND, DIFFERENT event id (Stripe redelivering the same
     * underlying refund lifecycle under a new event id) must also be
     * unable to mutate an obligation that already left PENDING - proves
     * idempotency does not depend on `payment_webhook_events`' own
     * provider_event_id dedup alone.
     */
    public function test_second_distinct_mismatch_event_for_the_same_refund_cannot_re_mutate_it(): void
    {
        $refund = $this->pendingRefund();
        $paymentIntentRef = DB::table('payment_attempts')->where('id', $refund->payment_attempt_id)->value('provider_session_reference');
        $mismatchedAmount = bcadd((string) $refund->requested_amount, '1.000000', 6);

        $this->postWebhook($this->fakeRefundWebhookPayload([
            'event_id' => 'evt_refund_mismatch_first',
            'provider_refund_reference' => 'fake_re_mismatch_redelivery',
            'provider_payment_reference' => $paymentIntentRef,
            'refund_status' => 'succeeded',
            'amount' => $mismatchedAmount,
        ]))->assertStatus(200);

        $flaggedAt = DB::table('booking_refunds')->where('id', $refund->id)->value('failed_at');

        $this->postWebhook($this->fakeRefundWebhookPayload([
            'event_id' => 'evt_refund_mismatch_second',
            'provider_refund_reference' => 'fake_re_mismatch_redelivery',
            'provider_payment_reference' => $paymentIntentRef,
            'refund_status' => 'succeeded',
            'amount' => $mismatchedAmount,
        ]))->assertStatus(200);

        $fresh = DB::table('booking_refunds')->where('id', $refund->id)->first();
        $this->assertSame('RECONCILIATION_REQUIRED', $this->bookingRefundStatusCode($fresh));
        $this->assertSame($flaggedAt, $fresh->failed_at);

        $ledger = DB::table('payment_webhook_events')->where('provider_event_id', 'evt_refund_mismatch_second')->first();
        $statusCode = DB::table('payment_webhook_event_statuses')->where('id', $ledger->status_id)->value('code');
        $this->assertSame('IGNORED', $statusCode);
    }

    // -----------------------------------------------------------------
    // FIX PHASE 2 - RECONCILIATION_REQUIRED must be excluded from the
    // automatic recovery path exactly like FAILED, and must never trigger
    // a second Stripe refund call.
    // -----------------------------------------------------------------

    public function test_amount_mismatch_reconciliation_required_is_never_selected_by_the_recovery_command(): void
    {
        $refund = $this->pendingRefund();
        $paymentIntentRef = DB::table('payment_attempts')->where('id', $refund->payment_attempt_id)->value('provider_session_reference');

        $this->postWebhook($this->fakeRefundWebhookPayload([
            'event_id' => 'evt_refund_mismatch_recovery_amount',
            'provider_refund_reference' => 'fake_re_mismatch_recovery_amount',
            'provider_payment_reference' => $paymentIntentRef,
            'refund_status' => 'succeeded',
            'amount' => bcadd((string) $refund->requested_amount, '1.000000', 6),
        ]))->assertStatus(200);

        $this->assertSame('RECONCILIATION_REQUIRED', $this->bookingRefundStatusCode(
            DB::table('booking_refunds')->where('id', $refund->id)->first()
        ));

        $callsBefore = count($this->fakeGateway()->refundPaymentCalls);

        $this->artisan('bookings:execute-pending-refunds')->assertSuccessful();

        // No second gateway refund call - the recovery command's own
        // WHERE status_id = PENDING query never selected this obligation.
        $this->assertCount($callsBefore, $this->fakeGateway()->refundPaymentCalls);
        $this->assertSame('RECONCILIATION_REQUIRED', $this->bookingRefundStatusCode(
            DB::table('booking_refunds')->where('id', $refund->id)->first()
        ));
    }

    public function test_currency_mismatch_reconciliation_required_is_never_selected_by_the_recovery_command(): void
    {
        $refund = $this->pendingRefund();
        $paymentIntentRef = DB::table('payment_attempts')->where('id', $refund->payment_attempt_id)->value('provider_session_reference');

        $this->postWebhook($this->fakeRefundWebhookPayload([
            'event_id' => 'evt_refund_mismatch_recovery_currency',
            'provider_refund_reference' => 'fake_re_mismatch_recovery_currency',
            'provider_payment_reference' => $paymentIntentRef,
            'refund_status' => 'succeeded',
            'currency' => 'USD',
        ]))->assertStatus(200);

        $this->assertSame('RECONCILIATION_REQUIRED', $this->bookingRefundStatusCode(
            DB::table('booking_refunds')->where('id', $refund->id)->first()
        ));

        $callsBefore = count($this->fakeGateway()->refundPaymentCalls);

        $this->artisan('bookings:execute-pending-refunds')->assertSuccessful();

        $this->assertCount($callsBefore, $this->fakeGateway()->refundPaymentCalls);
        $this->assertSame('RECONCILIATION_REQUIRED', $this->bookingRefundStatusCode(
            DB::table('booking_refunds')->where('id', $refund->id)->first()
        ));
    }

    public function test_reconciliation_required_obligation_is_a_no_op_if_directly_re_executed(): void
    {
        $refund = $this->pendingRefund();
        $paymentIntentRef = DB::table('payment_attempts')->where('id', $refund->payment_attempt_id)->value('provider_session_reference');

        $this->postWebhook($this->fakeRefundWebhookPayload([
            'event_id' => 'evt_refund_mismatch_direct_reexec',
            'provider_refund_reference' => 'fake_re_mismatch_direct',
            'provider_payment_reference' => $paymentIntentRef,
            'refund_status' => 'succeeded',
            'amount' => bcadd((string) $refund->requested_amount, '1.000000', 6),
        ]))->assertStatus(200);

        $callsBefore = count($this->fakeGateway()->refundPaymentCalls);

        // Even a direct call (bypassing the recovery command's own
        // PENDING-only WHERE clause) must be a safe no-op -
        // ExecuteBookingRefundAction's own guard is the second, structural
        // layer preventing a second Stripe call.
        app(ExecuteBookingRefundAction::class)
            ->handle(UuidBinary::toString($refund->id));

        $this->assertCount($callsBefore, $this->fakeGateway()->refundPaymentCalls);
        $this->assertSame('RECONCILIATION_REQUIRED', $this->bookingRefundStatusCode(
            DB::table('booking_refunds')->where('id', $refund->id)->first()
        ));
    }

    public function test_transient_network_failure_still_leaves_the_obligation_pending_and_retryable(): void
    {
        // Distinguishes UNKNOWN (transient) from the mismatch case above -
        // this MUST remain PENDING and selectable by the recovery command,
        // never quarantined.
        $refund = $this->pendingRefund();

        $this->assertSame('PENDING', $this->bookingRefundStatusCode($refund));

        $this->fakeGateway()->queueNextRefund(RefundCreationResult::unknown('simulated timeout'));
        $this->artisan('bookings:execute-pending-refunds')->assertSuccessful();

        $stillPending = DB::table('booking_refunds')->where('id', $refund->id)->first();
        $this->assertSame('PENDING', $this->bookingRefundStatusCode($stillPending));

        // A subsequent successful attempt (no queued result = the fake
        // gateway's deterministic default "succeeded") resolves it.
        $this->artisan('bookings:execute-pending-refunds')->assertSuccessful();

        $this->assertSame('SUCCEEDED', $this->bookingRefundStatusCode(
            DB::table('booking_refunds')->where('id', $refund->id)->first()
        ));
    }

    public function test_stale_refund_event_after_synchronous_success_cannot_regress_the_obligation(): void
    {
        // The default FakePaymentGateway::refundPayment() resolves
        // synchronously to "succeeded" - the obligation is already
        // SUCCEEDED before any webhook arrives.
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => now()->addDays(2),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        $this->postJson(
            '/api/v1/bookings/'.UuidBinary::toString($booking->id).'/cancel',
            [],
            ['Authorization' => 'Bearer '.$customer['access_token']]
        )->assertStatus(200);

        $refund = $this->bookingRefundRow($booking);
        $this->assertSame('SUCCEEDED', $this->bookingRefundStatusCode($refund));
        $succeededAt = $refund->succeeded_at;

        $this->postWebhook($this->fakeRefundWebhookPayload([
            'event_id' => 'evt_stale_refund_failed',
            'provider_refund_reference' => $refund->provider_refund_reference,
            'refund_status' => 'failed',
            'failure_code' => 'stale_out_of_order',
        ]))->assertStatus(200);

        $fresh = DB::table('booking_refunds')->where('id', $refund->id)->first();
        $this->assertSame('SUCCEEDED', $this->bookingRefundStatusCode($fresh));
        $this->assertSame($succeededAt, $fresh->succeeded_at);
    }
}
