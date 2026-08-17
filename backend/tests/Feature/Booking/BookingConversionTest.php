<?php

namespace Tests\Feature\Booking;

use App\Actions\Booking\CreateBookingFromSuccessfulPaymentAction;
use App\Console\Commands\ConvertSuccessfulPaymentsToBookings;
use App\Support\Booking\BookingConversionOutcome;
use App\Support\Booking\BookingSources;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Booking\Concerns\CreatesBookingFixtures;
use Tests\TestCase;

class BookingConversionTest extends TestCase
{
    use CreatesBookingFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    // 1. SUCCESSFUL payment creates exactly one Booking.
    public function test_successful_payment_creates_exactly_one_booking(): void
    {
        ['payment' => $payment] = $this->successfulPayment();

        $this->assertSame(1, DB::table('bookings')->where('payment_attempt_id', $payment->id)->count());
    }

    // 2. PENDING -> no Booking.
    public function test_pending_payment_creates_no_booking(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $row = $this->paymentRow($response->json('data.payment.uuid'));

        $this->assertSame('PENDING', $this->paymentStatusCode($row));
        $this->assertSame(0, DB::table('bookings')->where('payment_attempt_id', $row->id)->count());
    }

    // 3. FAILED -> no Booking.
    public function test_failed_payment_creates_no_booking(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $row = $this->paymentRow($response->json('data.payment.uuid'));

        DB::table('payment_attempts')->where('id', $row->id)->update([
            'status_id' => DB::table('payment_statuses')->where('code', 'FAILED')->value('id'),
            'finalized_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        $result = app(CreateBookingFromSuccessfulPaymentAction::class)->handle(UuidBinary::toString($row->id));

        $this->assertSame(BookingConversionOutcome::NOT_ELIGIBLE, $result->outcome);
        $this->assertSame(0, DB::table('bookings')->where('payment_attempt_id', $row->id)->count());
    }

    // 4. CANCELLED -> no Booking.
    public function test_cancelled_payment_creates_no_booking(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $cartUuid = $this->getCheckout($customer['access_token'])->json('data.checkout.cart_uuid');
        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $row = $this->paymentRow($response->json('data.payment.uuid'));

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'CANCELED',
        ]))->assertStatus(200);

        $this->assertSame('CANCELLED', $this->paymentStatusCode($this->paymentRow($this->uuidOfRow($row))));
        $this->assertSame('ACTIVE', $this->cartStatusCode($cartUuid));
        $this->assertSame(0, DB::table('bookings')->where('payment_attempt_id', $row->id)->count());
    }

    // 5. REFUNDED -> no automatic Booking.
    public function test_refunded_payment_creates_no_booking(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $bookingBefore = $this->bookingRowForPayment($payment);
        $this->assertNotNull($bookingBefore);

        // Phase 6A/7A never writes REFUNDED through any code path - directly
        // simulate the schema-legal state to prove Phase 7A's own gate
        // would also refuse a *second*, hypothetical conversion attempt for
        // an already-refunded attempt (defense in depth: refund execution
        // itself is out of Phase 7A scope).
        DB::table('payment_attempts')->where('id', $payment->id)->update([
            'status_id' => DB::table('payment_statuses')->where('code', 'REFUNDED')->value('id'),
        ]);

        // The existing Booking is untouched, and re-running conversion is
        // still idempotent (ALREADY_EXISTS), never a second Booking.
        $result = app(CreateBookingFromSuccessfulPaymentAction::class)->handle(UuidBinary::toString($payment->id));

        $this->assertSame(BookingConversionOutcome::ALREADY_EXISTS, $result->outcome);
        $this->assertSame(1, DB::table('bookings')->where('payment_attempt_id', $payment->id)->count());
    }

    // 6. Reconciliation-required SUCCESSFUL -> no Booking.
    public function test_reconciliation_flagged_successful_payment_creates_no_booking(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $row = $this->paymentRow($response->json('data.payment.uuid'));

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => '1.000000',
        ]))->assertStatus(200);

        $fresh = $this->paymentRow($this->uuidOfRow($row));
        $this->assertSame('SUCCESSFUL', $this->paymentStatusCode($fresh));
        $this->assertSame(1, (int) $fresh->requires_reconciliation);
        $this->assertSame(0, DB::table('bookings')->where('payment_attempt_id', $row->id)->count());

        // Retrying conversion directly is still safely refused.
        $result = app(CreateBookingFromSuccessfulPaymentAction::class)->handle($this->uuidOfRow($row));
        $this->assertSame(BookingConversionOutcome::NOT_ELIGIBLE, $result->outcome);
    }

    // 7. Invalid snapshot hash -> no Booking + reconciliation.
    public function test_corrupted_snapshot_hash_blocks_booking_and_flags_reconciliation(): void
    {
        $row = $this->manuallySucceedPayment($this->readyForPaymentCustomer());

        DB::table('payment_attempts')->where('id', $row->id)->update([
            'checkout_snapshot_hash' => random_bytes(32),
        ]);

        $result = app(CreateBookingFromSuccessfulPaymentAction::class)->handle(UuidBinary::toString($row->id));

        $this->assertSame(BookingConversionOutcome::RECONCILIATION_REQUIRED, $result->outcome);
        $this->assertSame('SNAPSHOT_INTEGRITY_FAILURE', $result->reason);

        $fresh = $this->paymentRow(UuidBinary::toString($row->id));
        $this->assertSame('SUCCESSFUL', $this->paymentStatusCode($fresh));
        $this->assertSame(1, (int) $fresh->requires_reconciliation);
        $this->assertSame('SNAPSHOT_INTEGRITY_FAILURE', $fresh->reconciliation_reason_code);
        $this->assertSame(0, DB::table('bookings')->where('payment_attempt_id', $row->id)->count());
    }

    // 8. Retry conversion -> same Booking.
    public function test_retrying_conversion_returns_the_same_booking(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $first = app(CreateBookingFromSuccessfulPaymentAction::class)->handle(UuidBinary::toString($payment->id));

        $second = app(CreateBookingFromSuccessfulPaymentAction::class)->handle(UuidBinary::toString($payment->id));
        $third = app(CreateBookingFromSuccessfulPaymentAction::class)->handle(UuidBinary::toString($payment->id));

        $this->assertSame(BookingConversionOutcome::ALREADY_EXISTS, $second->outcome);
        $this->assertSame(BookingConversionOutcome::ALREADY_EXISTS, $third->outcome);
        $this->assertSame($first->bookingUuid, $second->bookingUuid);
        $this->assertSame($first->bookingUuid, $third->bookingUuid);
        $this->assertSame(1, DB::table('bookings')->where('payment_attempt_id', $payment->id)->count());
    }

    // 9. Duplicate success webhook -> still one Booking.
    public function test_duplicate_success_webhook_still_creates_one_booking(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $row = $this->paymentRow($response->json('data.payment.uuid'));
        $eventId = 'evt_'.Str::uuid();

        $payload = $this->fakeWebhookPayload([
            'event_id' => $eventId,
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $row->requested_amount,
        ]);

        $this->postWebhook($payload)->assertStatus(200);
        $this->postWebhook($payload)->assertStatus(200);

        $this->assertSame(1, DB::table('bookings')->where('payment_attempt_id', $row->id)->count());
    }

    // 10. Unique payment_attempt_id DB constraint backstop.
    public function test_unique_payment_attempt_id_constraint_backstops_a_second_booking_insert(): void
    {
        ['payment' => $payment] = $this->successfulPayment();

        $this->expectException(UniqueConstraintViolationException::class);

        $timestamp = now()->format('Y-m-d H:i:s.u');

        DB::table('bookings')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'booking_number' => 'BLU-TESTDUPE01',
            'cart_id' => $payment->cart_id,
            'payment_attempt_id' => $payment->id,
            'booking_source_id' => BookingSources::id('STANDARD'),
            'appointment_slot_id' => DB::table('appointment_holds')->where('id', $payment->appointment_hold_id)->value('appointment_slot_id'),
            'status_id' => DB::table('booking_statuses')->where('code', 'PAID')->value('id'),
            'status_changed_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    // 11. cart CHECKOUT -> CONVERTED.
    public function test_cart_transitions_from_checkout_to_converted(): void
    {
        $customer = $this->readyForPaymentCustomer();
        $cartUuid = $this->getCheckout($customer['access_token'])->json('data.checkout.cart_uuid');

        $this->assertSame('ACTIVE', $this->cartStatusCode($cartUuid));

        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $this->assertSame('CHECKOUT', $this->cartStatusCode($cartUuid));

        $row = $this->paymentRow($response->json('data.payment.uuid'));
        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $row->requested_amount,
        ]))->assertStatus(200);

        $this->assertSame('CONVERTED', $this->cartStatusCode($cartUuid));
    }

    // 12. Unrelated new ACTIVE cart remains untouched.
    public function test_a_customers_new_active_cart_is_never_touched_by_conversion(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();

        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid'], 'quantity' => 1])->assertStatus(201);

        $newCartUuid = $this->getCart($customer['access_token'])->json('data.cart.uuid');
        $oldCartUuid = UuidBinary::toString($payment->cart_id);

        $this->assertNotSame($oldCartUuid, $newCartUuid);
        $this->assertSame('ACTIVE', $this->cartStatusCode($newCartUuid));
        $this->assertSame('CONVERTED', $this->cartStatusCode($oldCartUuid));
    }

    // 13. Hold converted_at set exactly once.
    public function test_appointment_hold_converted_at_is_set_exactly_once(): void
    {
        ['payment' => $payment] = $this->successfulPayment();

        $hold = DB::table('appointment_holds')->where('id', $payment->appointment_hold_id)->first();
        $this->assertNotNull($hold->converted_at);

        app(CreateBookingFromSuccessfulPaymentAction::class)->handle(UuidBinary::toString($payment->id));

        $holdAfterRetry = DB::table('appointment_holds')->where('id', $payment->appointment_hold_id)->first();
        $this->assertSame($hold->converted_at, $holdAfterRetry->converted_at);
    }

    // 14. Booking Item count matches frozen snapshot.
    public function test_booking_item_count_matches_frozen_snapshot_item_count(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        $snapshot = json_decode((string) $payment->checkout_snapshot, true);

        $this->assertSame(count($snapshot['items']), DB::table('booking_items')->where('booking_id', $booking->id)->count());
    }

    // 24. Booking conversion creates initial status history exactly once.
    public function test_initial_booking_status_history_row_is_created_exactly_once(): void
    {
        ['payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        app(CreateBookingFromSuccessfulPaymentAction::class)->handle(UuidBinary::toString($payment->id));

        $history = DB::table('booking_status_history')->where('booking_id', $booking->id)->get();

        $this->assertCount(1, $history);
        $this->assertNull($history->first()->from_status_id);
        $this->assertSame((int) $booking->status_id, (int) $history->first()->to_status_id);
    }

    // 25 & 27. A DB exception mid-conversion rolls back every Booking-domain
    // write while Payment stays SUCCESSFUL, and a later retry (after the
    // transient obstruction is cleared) still converges on exactly one
    // Booking - no duplicate.
    public function test_conversion_failure_rolls_back_completely_and_is_safely_retryable(): void
    {
        $customerA = $this->readyForPaymentCustomer();
        $cartItemA = DB::table('cart_items')
            ->where('cart_id', UuidBinary::toBinary($this->getCheckout($customerA['access_token'])->json('data.checkout.cart_uuid')))
            ->first();

        $responseA = $this->createPayment($customerA['access_token'], (string) Str::uuid());
        $rowA = $this->paymentRow($responseA->json('data.payment.uuid'));

        // An unrelated, already-successful decoy Booking to own a valid
        // parent booking_id for the conflicting row below.
        ['payment' => $decoyPayment] = $this->successfulPayment(['starts_at' => now()->addDays(3)]);
        $decoyBooking = $this->bookingRowForPayment($decoyPayment);
        $decoyItem = DB::table('booking_items')->where('booking_id', $decoyBooking->id)->first();

        // Force a UNIQUE(source_cart_item_id) collision that only fires
        // once CreateBookingFromSuccessfulPaymentAction reaches the
        // booking_items insert loop - i.e. strictly after bookings /
        // booking_status_history / booking_locations were already written
        // for customer A inside the same transaction.
        $decoyItemTimestamp = now()->format('Y-m-d H:i:s.u');

        DB::table('booking_items')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'booking_id' => $decoyBooking->id,
            'source_cart_item_id' => $cartItemA->id,
            'service_id' => $decoyItem->service_id,
            'pricing_scheme_version_id' => $decoyItem->pricing_scheme_version_id,
            'status_id' => $decoyItem->status_id,
            'service_code_snapshot' => $decoyItem->service_code_snapshot,
            'service_name_snapshot' => $decoyItem->service_name_snapshot,
            'quantity' => 1,
            'pricing_status_snapshot' => 'PRICED',
            'base_amount_snapshot' => '1.000000',
            'pricing_breakdown' => '[]',
            'unit_total_amount' => '1.000000',
            'line_total_amount' => '1.000000',
            'display_order' => 99,
            'status_changed_at' => $decoyItemTimestamp,
            'created_at' => $decoyItemTimestamp,
            'updated_at' => $decoyItemTimestamp,
        ]);

        $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $rowA->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $rowA->requested_amount,
        ]))->assertStatus(200);

        $freshA = $this->paymentRow($this->uuidOfRow($rowA));
        $this->assertSame('SUCCESSFUL', $this->paymentStatusCode($freshA));
        $this->assertSame(0, (int) $freshA->requires_reconciliation);
        $this->assertSame(0, DB::table('bookings')->where('payment_attempt_id', $rowA->id)->count());
        // Only the decoy Booking's own location row should exist - the
        // bookings/booking_status_history/booking_locations rows Customer
        // A's attempt wrote before hitting the collision were rolled back
        // along with everything else in that transaction.
        $this->assertSame(1, DB::table('booking_locations')->count());

        // Clear the transient obstruction and retry - exactly one Booking
        // for customer A, never a duplicate of anything already committed.
        DB::table('booking_items')->where('source_cart_item_id', $cartItemA->id)->delete();

        $retry = app(CreateBookingFromSuccessfulPaymentAction::class)->handle(UuidBinary::toString($rowA->id));

        $this->assertSame(BookingConversionOutcome::CREATED, $retry->outcome);
        $this->assertSame(1, DB::table('bookings')->where('payment_attempt_id', $rowA->id)->count());
    }

    // 26. Safe behavior if the hold becomes unsafe before conversion runs.
    public function test_hold_expired_after_success_blocks_booking_and_flags_reconciliation(): void
    {
        $row = $this->manuallySucceedPayment($this->readyForPaymentCustomer());

        // expires_at > held_at is a DB CHECK constraint
        // (chk_appointment_holds_expiration), so an expired-but-valid hold
        // must move both timestamps into the past off one captured $now
        // rather than just backdating expires_at against the original
        // (much newer) held_at - mirrors ReconciliationTest's own
        // HOLD_EXPIRED fixture exactly.
        $now = now();
        DB::table('appointment_holds')->where('id', $row->appointment_hold_id)->update([
            'held_at' => $now->copy()->subMinutes(20)->format('Y-m-d H:i:s.u'),
            'expires_at' => $now->copy()->subMinutes(10)->format('Y-m-d H:i:s.u'),
        ]);

        $result = app(CreateBookingFromSuccessfulPaymentAction::class)->handle(UuidBinary::toString($row->id));

        $this->assertSame(BookingConversionOutcome::RECONCILIATION_REQUIRED, $result->outcome);
        $this->assertSame('HOLD_EXPIRED', $result->reason);

        $fresh = $this->paymentRow(UuidBinary::toString($row->id));
        $this->assertSame('SUCCESSFUL', $this->paymentStatusCode($fresh));
        $this->assertSame(1, (int) $fresh->requires_reconciliation);
        $this->assertSame(0, DB::table('bookings')->where('payment_attempt_id', $row->id)->count());
    }

    public function test_hold_released_after_success_blocks_booking_and_flags_reconciliation(): void
    {
        $row = $this->manuallySucceedPayment($this->readyForPaymentCustomer());

        DB::table('appointment_holds')->where('id', $row->appointment_hold_id)->update([
            'released_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        $result = app(CreateBookingFromSuccessfulPaymentAction::class)->handle(UuidBinary::toString($row->id));

        $this->assertSame(BookingConversionOutcome::RECONCILIATION_REQUIRED, $result->outcome);
        $this->assertSame('HOLD_RELEASED', $result->reason);
        $this->assertSame(0, DB::table('bookings')->where('payment_attempt_id', $row->id)->count());
    }

    public function test_recovery_command_converts_a_stranded_successful_payment(): void
    {
        $row = $this->manuallySucceedPayment($this->readyForPaymentCustomer());

        $this->assertSame(0, DB::table('bookings')->where('payment_attempt_id', $row->id)->count());

        $this->artisan(ConvertSuccessfulPaymentsToBookings::class)->assertExitCode(0);

        $this->assertSame(1, DB::table('bookings')->where('payment_attempt_id', $row->id)->count());
    }

    public function test_recovery_command_is_a_safe_no_op_when_nothing_is_stranded(): void
    {
        $this->successfulPayment();

        $this->artisan(ConvertSuccessfulPaymentsToBookings::class)->assertExitCode(0);

        $this->assertSame(1, DB::table('bookings')->count());
    }

    /**
     * Drives a payment attempt straight to SUCCESSFUL (bypassing the
     * webhook pipeline entirely, so Phase 7A's own independent safety
     * checks - snapshot hash, hold safety - are what's under test here,
     * not Phase 6A's webhook-time equivalents) without ever invoking
     * Booking conversion.
     */
    private function manuallySucceedPayment(array $customer): object
    {
        $response = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $row = $this->paymentRow($response->json('data.payment.uuid'));
        $timestamp = now()->format('Y-m-d H:i:s.u');

        DB::table('payment_attempts')->where('id', $row->id)->update([
            'status_id' => DB::table('payment_statuses')->where('code', 'SUCCESSFUL')->value('id'),
            'confirmed_amount' => $row->requested_amount,
            'requires_reconciliation' => 0,
            'reconciliation_reason_code' => null,
            'successful_at' => $timestamp,
            'finalized_at' => $timestamp,
            'status_changed_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $this->paymentRow(UuidBinary::toString($row->id));
    }

    private function uuidOfRow(object $row): string
    {
        return UuidBinary::toString($row->id);
    }
}
