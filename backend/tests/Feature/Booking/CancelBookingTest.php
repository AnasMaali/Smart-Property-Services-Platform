<?php

namespace Tests\Feature\Booking;

use App\Actions\Booking\TransitionBookingStatusAction;
use App\Actions\Payment\ExecuteBookingRefundAction;
use App\Actions\Technician\AssignTechnicianToBookingItemAction;
use App\Support\Booking\BookingRefundStatuses;
use App\Support\Payment\Gateway\MinorUnitConverter;
use App\Support\Payment\Gateway\RefundCreationResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Technician\Concerns\CreatesTechnicianFixtures;
use Tests\TestCase;

class CancelBookingTest extends TestCase
{
    use CreatesTechnicianFixtures;
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function cancelBooking(string $accessToken, object $booking)
    {
        return $this->postJson(
            '/api/v1/bookings/'.UuidBinary::toString($booking->id).'/cancel',
            [],
            ['Authorization' => 'Bearer '.$accessToken]
        );
    }

    private function bookingStatus(object $booking): string
    {
        $statusId = DB::table('bookings')
            ->where('id', $booking->id)
            ->value('status_id');

        return (string) DB::table('booking_statuses')
            ->where('id', $statusId)
            ->value('code');
    }

    private function paymentStatus(object $payment): string
    {
        $statusId = DB::table('payment_attempts')
            ->where('id', $payment->id)
            ->value('status_id');

        return (string) DB::table('payment_statuses')
            ->where('id', $statusId)
            ->value('code');
    }

    public function test_cancellation_before_appointment_day_returns_100_percent_refund_due(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        /*
         * Freeze time only AFTER authentication/payment fixtures exist.
         */
        Carbon::setTestNow('2026-09-14 20:00:00');

        $response = $this->cancelBooking(
            $customer['access_token'],
            $booking
        );

        $response
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'booking' => [
                        'status' => 'CANCELLED',
                    ],
                    'refund_due' => [
                        'percentage' => 100,
                        'execution' => 'AUTOMATIC',
                    ],
                ],
            ]);

        $this->assertSame(
            'CANCELLED',
            $this->bookingStatus($booking)
        );

        /*
         * The original payment_attempts row is never rewritten by the
         * refund - it stays exactly SUCCESSFUL forever.
         */
        $this->assertSame(
            'SUCCESSFUL',
            $this->paymentStatus($payment)
        );

        $paidAmount = (string) DB::table('payment_attempts')
            ->where('id', $payment->id)
            ->value('confirmed_amount');

        $expectedRefund = bcdiv(
            bcmul($paidAmount, '100', 6),
            '100',
            6
        );

        $this->assertSame(
            $expectedRefund,
            $response->json('data.refund_due.amount')
        );

        /*
         * Exactly one booking_refunds obligation is created, for the
         * correct amount, and (via FakePaymentGateway's synchronous
         * "succeeded" default) resolves automatically.
         */
        $refundRow = $this->bookingRefundRow($booking);
        $this->assertNotNull($refundRow);
        $this->assertSame($expectedRefund, (string) $refundRow->requested_amount);
        $this->assertSame(100, (int) $refundRow->policy_percentage);
        $this->assertSame('SUCCEEDED', $this->bookingRefundStatusCode($refundRow));
        $this->assertNotNull($refundRow->provider_refund_reference);
        $this->assertSame('CUSTOMER', $refundRow->initiated_as);

        $this->assertCount(1, $this->fakeGateway()->refundPaymentCalls);
        $this->assertSame($expectedRefund, $this->fakeGateway()->refundPaymentCalls[0]->amount);
    }

    public function test_cancellation_from_start_of_appointment_day_returns_75_percent_refund_due(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        /*
         * Appointment day begins here.
         */
        Carbon::setTestNow('2026-09-15 00:00:00');

        $response = $this->cancelBooking(
            $customer['access_token'],
            $booking
        );

        $response
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'booking' => [
                        'status' => 'CANCELLED',
                    ],
                    'refund_due' => [
                        'percentage' => 75,
                        'execution' => 'AUTOMATIC',
                    ],
                ],
            ]);

        $paidAmount = (string) DB::table('payment_attempts')
            ->where('id', $payment->id)
            ->value('confirmed_amount');

        $expectedRefund = bcdiv(
            bcmul($paidAmount, '75', 6),
            '100',
            6
        );

        $this->assertSame(
            $expectedRefund,
            $response->json('data.refund_due.amount')
        );

        $this->assertSame(
            'SUCCESSFUL',
            $this->paymentStatus($payment)
        );
    }

    public function test_foreign_customer_cannot_cancel_another_customers_booking(): void
    {
        ['payment' => $payment] = $this->successfulPayment([
            'starts_at' => now()->addDays(2),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        $otherCustomer = $this->createAuthenticatedCartCustomer();

        $this->cancelBooking(
            $otherCustomer['access_token'],
            $booking
        )->assertStatus(404);

        $this->assertSame(
            'PAID',
            $this->bookingStatus($booking)
        );
    }

    public function test_malformed_booking_uuid_returns_clean_404_on_cancel(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->postJson(
            '/api/v1/bookings/not-a-uuid/cancel',
            [],
            ['Authorization' => 'Bearer '.$customer['access_token']]
        )->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Booking not found.',
            ]);
    }

    public function test_unknown_booking_uuid_returns_clean_404_on_cancel(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->postJson(
            '/api/v1/bookings/'.UuidBinary::generate().'/cancel',
            [],
            ['Authorization' => 'Bearer '.$customer['access_token']]
        )->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Booking not found.',
            ]);
    }

    public function test_foreign_customer_cancel_does_not_mutate_booking_or_history(): void
    {
        ['payment' => $payment] = $this->successfulPayment([
            'starts_at' => now()->addDays(2),
        ]);

        $booking = $this->bookingRowForPayment($payment);
        $otherCustomer = $this->createAuthenticatedCartCustomer();

        $historyBefore = DB::table('booking_status_history')
            ->where('booking_id', $booking->id)
            ->count();

        $cancelledAtBefore = DB::table('bookings')
            ->where('id', $booking->id)
            ->value('cancelled_at');

        $this->cancelBooking(
            $otherCustomer['access_token'],
            $booking
        )->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Booking not found.',
            ]);

        $fresh = DB::table('bookings')
            ->where('id', $booking->id)
            ->first();

        $historyAfter = DB::table('booking_status_history')
            ->where('booking_id', $booking->id)
            ->count();

        $this->assertSame('PAID', $this->bookingStatus($booking));
        $this->assertSame($cancelledAtBefore, $fresh->cancelled_at);
        $this->assertSame($historyBefore, $historyAfter);
    }

    public function test_foreign_and_unknown_booking_are_publicly_indistinguishable_on_cancel(): void
    {
        ['payment' => $payment] = $this->successfulPayment([
            'starts_at' => now()->addDays(2),
        ]);

        $booking = $this->bookingRowForPayment($payment);
        $stranger = $this->createAuthenticatedCartCustomer();

        $foreignResponse = $this->cancelBooking(
            $stranger['access_token'],
            $booking
        );

        $unknownResponse = $this->postJson(
            '/api/v1/bookings/'.UuidBinary::generate().'/cancel',
            [],
            ['Authorization' => 'Bearer '.$stranger['access_token']]
        );

        $foreignResponse->assertStatus(404);
        $unknownResponse->assertStatus(404);

        $this->assertSame(
            $unknownResponse->json('success'),
            $foreignResponse->json('success')
        );

        $this->assertSame(
            $unknownResponse->json('message'),
            $foreignResponse->json('message')
        );

        $this->assertSame('Booking not found.', $foreignResponse->json('message'));
    }

    public function test_cancellation_is_idempotent_and_does_not_duplicate_history(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        /*
         * First real cancellation happens BEFORE appointment day.
         * Therefore refund eligibility is 100%.
         */
        Carbon::setTestNow('2026-09-14 20:00:00');

        $first = $this->cancelBooking(
            $customer['access_token'],
            $booking
        );

        $first
            ->assertStatus(200)
            ->assertJsonPath('data.refund_due.percentage', 100);

        $historyAfterFirstCancellation = DB::table('booking_status_history')
            ->where('booking_id', $booking->id)
            ->count();

        /*
         * Retry happens on appointment day.
         *
         * It must still use the ORIGINAL cancellation timestamp,
         * so eligibility remains 100%, not 75%.
         */
        Carbon::setTestNow('2026-09-15 05:00:00');

        $retry = $this->cancelBooking(
            $customer['access_token'],
            $booking
        );

        $retry
            ->assertStatus(200)
            ->assertJsonPath('data.refund_due.percentage', 100);

        $this->assertSame(
            $historyAfterFirstCancellation,
            DB::table('booking_status_history')
                ->where('booking_id', $booking->id)
                ->count()
        );

        /*
         * Exactly one booking_refunds obligation ever exists for this
         * Booking, and Stripe is only ever called once - the retry, once
         * the obligation already resolved SUCCEEDED, must not call Stripe
         * again.
         */
        $this->assertSame(
            1,
            DB::table('booking_refunds')->where('booking_id', $booking->id)->count()
        );

        $this->assertCount(1, $this->fakeGateway()->refundPaymentCalls);
    }

    public function test_completed_booking_cannot_be_cancelled(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => now()->addDays(2),
        ]);

        $booking = $this->bookingRowForPayment($payment);
        $bookingUuid = UuidBinary::toString($booking->id);

        app(TransitionBookingStatusAction::class)
            ->assign($bookingUuid);

        app(TransitionBookingStatusAction::class)
            ->start($bookingUuid);

        app(TransitionBookingStatusAction::class)
            ->complete($bookingUuid);

        $response = $this->cancelBooking(
            $customer['access_token'],
            $booking
        );

        $response->assertStatus(409);

        $this->assertSame(
            'COMPLETED',
            $this->bookingStatus($booking)
        );

        $this->assertSame(
            'SUCCESSFUL',
            $this->paymentStatus($payment)
        );
    }

    public function test_refund_snapshot_before_appointment_day_survives_a_later_policy_config_change(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        Carbon::setTestNow('2026-09-14 20:00:00');

        $response = $this->cancelBooking(
            $customer['access_token'],
            $booking
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.refund_due.percentage', 100);

        $paidAmount = (string) DB::table('payment_attempts')
            ->where('id', $payment->id)
            ->value('confirmed_amount');

        $expectedAmount = bcdiv(
            bcmul($paidAmount, '100', 6),
            '100',
            6
        );

        $this->assertSame(
            $expectedAmount,
            $response->json('data.refund_due.amount')
        );

        /*
         * Refund policy snapshot must be stored immediately.
         */
        $row = DB::table('bookings')
            ->where('id', $booking->id)
            ->first();

        $this->assertSame(
            100,
            (int) $row->cancellation_refund_percentage
        );

        $this->assertSame(
            $expectedAmount,
            (string) $row->cancellation_refund_amount
        );

        /*
         * Company changes its policy AFTER this Booking was cancelled.
         */
        config([
            'cancellation.before_appointment_day_percentage' => 90,
            'cancellation.appointment_day_percentage' => 50,
        ]);

        $rowAfterConfigChange = DB::table('bookings')
            ->where('id', $booking->id)
            ->first();

        /*
         * Historical snapshot must never change.
         */
        $this->assertSame(
            100,
            (int) $rowAfterConfigChange->cancellation_refund_percentage
        );

        $this->assertSame(
            $expectedAmount,
            (string) $rowAfterConfigChange->cancellation_refund_amount
        );
    }

    public function test_refund_snapshot_on_appointment_day_survives_a_later_policy_config_change(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        Carbon::setTestNow('2026-09-15 00:00:00');

        $response = $this->cancelBooking(
            $customer['access_token'],
            $booking
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.refund_due.percentage', 75);

        config([
            'cancellation.before_appointment_day_percentage' => 90,
            'cancellation.appointment_day_percentage' => 50,
        ]);

        $row = DB::table('bookings')
            ->where('id', $booking->id)
            ->first();

        /*
         * Original 75% policy must remain frozen historically.
         */
        $this->assertSame(
            75,
            (int) $row->cancellation_refund_percentage
        );
    }

    public function test_cancellation_retry_after_config_change_returns_original_snapshot_and_never_overwrites_it(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        Carbon::setTestNow('2026-09-14 20:00:00');

        $first = $this->cancelBooking(
            $customer['access_token'],
            $booking
        );

        $first
            ->assertStatus(200)
            ->assertJsonPath('data.refund_due.percentage', 100);

        $rowAfterFirst = DB::table('bookings')
            ->where('id', $booking->id)
            ->first();

        /*
         * Policy changes after the real cancellation.
         */
        config([
            'cancellation.before_appointment_day_percentage' => 90,
            'cancellation.appointment_day_percentage' => 50,
        ]);

        $retry = $this->cancelBooking(
            $customer['access_token'],
            $booking
        );

        $retry
            ->assertStatus(200)
            ->assertJsonPath(
                'data.refund_due.percentage',
                100
            )
            ->assertJsonPath(
                'data.refund_due.amount',
                $rowAfterFirst->cancellation_refund_amount
            );

        $rowAfterRetry = DB::table('bookings')
            ->where('id', $booking->id)
            ->first();

        /*
         * Retry must not overwrite the historical snapshot.
         */
        $this->assertSame(
            (int) $rowAfterFirst->cancellation_refund_percentage,
            (int) $rowAfterRetry->cancellation_refund_percentage
        );

        $this->assertSame(
            (string) $rowAfterFirst->cancellation_refund_amount,
            (string) $rowAfterRetry->cancellation_refund_amount
        );

        /*
         * Payment remains successful.
         */
        $this->assertSame(
            'SUCCESSFUL',
            $this->paymentStatus($payment)
        );
    }

    public function test_refund_snapshot_amount_matches_exact_decimal_arithmetic_of_the_paid_amount(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        Carbon::setTestNow('2026-09-15 08:00:00');

        $response = $this->cancelBooking(
            $customer['access_token'],
            $booking
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.refund_due.percentage', 75);

        $paidAmount = (string) DB::table('payment_attempts')
            ->where('id', $payment->id)
            ->value('confirmed_amount');

        $expectedAmount = bcdiv(
            bcmul($paidAmount, '75', 6),
            '100',
            6
        );

        $this->assertSame(
            $expectedAmount,
            $response->json('data.refund_due.amount')
        );

        $row = DB::table('bookings')
            ->where('id', $booking->id)
            ->first();

        $this->assertSame(
            $expectedAmount,
            (string) $row->cancellation_refund_amount
        );
    }

    public function test_non_cancelled_booking_has_no_refund_snapshot(): void
    {
        ['payment' => $payment] = $this->successfulPayment([
            'starts_at' => now()->addDays(2),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        $row = DB::table('bookings')
            ->where('id', $booking->id)
            ->first();

        $this->assertNull(
            $row->cancellation_refund_percentage
        );

        $this->assertNull(
            $row->cancellation_refund_amount
        );
    }

    public function test_cancellation_cancels_open_item_and_releases_active_technician_assignment(): void
    {
        $fixture = $this->bookingWithAssignableItem([
            'slot' => [
                'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
            ],
        ]);

        $technician = $this->createEligibleTechnician(
            $fixture['specialization_id']
        );

        $adminUserUuid = $this->createAdminUser();

        app(
            AssignTechnicianToBookingItemAction::class
        )->assign(
            UuidBinary::toString($fixture['item']->id),
            $technician['uuid'],
            $adminUserUuid
        );

        /*
         * Confirm pre-cancellation state.
         */
        $itemBefore = DB::table('booking_items')
            ->where('id', $fixture['item']->id)
            ->first();

        $this->assertSame(
            'ASSIGNED',
            DB::table('booking_item_statuses')
                ->where('id', $itemBefore->status_id)
                ->value('code')
        );

        $activeAssignment = DB::table('technician_assignments')
            ->where('booking_item_id', $fixture['item']->id)
            ->whereNull('released_at')
            ->first();

        $this->assertNotNull($activeAssignment);

        /*
         * Customer cancels before appointment day.
         */
        Carbon::setTestNow('2026-09-14 20:00:00');

        $response = $this->cancelBooking(
            $fixture['customer']['access_token'],
            $fixture['booking']
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath(
                'data.booking.status',
                'CANCELLED'
            )
            ->assertJsonPath(
                'data.refund_due.percentage',
                100
            );

        /*
         * Parent Booking is cancelled.
         */
        $this->assertSame(
            'CANCELLED',
            $this->bookingStatus($fixture['booking'])
        );

        /*
         * Open Booking Item is also cancelled.
         */
        $itemAfter = DB::table('booking_items')
            ->where('id', $fixture['item']->id)
            ->first();

        $this->assertSame(
            'CANCELLED',
            DB::table('booking_item_statuses')
                ->where('id', $itemAfter->status_id)
                ->value('code')
        );

        $this->assertNotNull(
            $itemAfter->cancelled_at
        );

        /*
         * Technician assignment is released, never deleted.
         */
        $assignmentAfter = DB::table('technician_assignments')
            ->where('id', $activeAssignment->id)
            ->first();

        $this->assertNotNull($assignmentAfter);

        $this->assertNotNull(
            $assignmentAfter->released_at
        );

        $this->assertSame(
            UuidBinary::toBinary(
                $fixture['customer']['user_uuid']
            ),
            $assignmentAfter->released_by_user_id
        );

        $this->assertSame(
            'Customer cancelled booking.',
            $assignmentAfter->release_reason
        );

        /*
         * The original payment_attempts row is never rewritten.
         */
        $this->assertSame(
            'SUCCESSFUL',
            $this->paymentStatus($fixture['payment'])
        );
    }

    // -----------------------------------------------------------------
    // BLUE V1 Phase B20 - appointment-started cancellation restriction
    // -----------------------------------------------------------------

    public function test_cancellation_at_appointment_start_time_is_rejected_with_no_mutation(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 09:00:00'),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        Carbon::setTestNow('2026-09-15 09:00:00');

        $response = $this->cancelBooking($customer['access_token'], $booking);

        $response->assertStatus(409);

        $this->assertSame('PAID', $this->bookingStatus($booking));
        $this->assertNull($this->bookingRefundRow($booking));
        $this->assertCount(0, $this->fakeGateway()->refundPaymentCalls);

        $fresh = DB::table('bookings')->where('id', $booking->id)->first();
        $this->assertNull($fresh->cancellation_refund_percentage);
        $this->assertNull($fresh->cancellation_refund_amount);
    }

    public function test_cancellation_after_appointment_start_time_is_rejected(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 09:00:00'),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        Carbon::setTestNow('2026-09-15 09:30:00');

        $this->cancelBooking($customer['access_token'], $booking)->assertStatus(409);

        $this->assertSame('PAID', $this->bookingStatus($booking));
    }

    public function test_cancellation_one_second_before_appointment_start_is_allowed(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 09:00:00'),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        Carbon::setTestNow('2026-09-15 08:59:59');

        $response = $this->cancelBooking($customer['access_token'], $booking);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.booking.status', 'CANCELLED')
            ->assertJsonPath('data.refund_due.percentage', 75);
    }

    // -----------------------------------------------------------------
    // BLUE V1 Phase B20 - Stripe execution, idempotency, recovery
    // -----------------------------------------------------------------

    public function test_refund_uses_the_payment_intent_reference_and_a_deterministic_idempotency_key(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => now()->addDays(2),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        $this->cancelBooking($customer['access_token'], $booking)->assertStatus(200);

        $refundRow = $this->bookingRefundRow($booking);
        $this->assertNotNull($refundRow);

        $call = $this->fakeGateway()->refundPaymentCalls[0];
        $this->assertSame($payment->provider_session_reference, $call->providerPaymentReference);
        $this->assertSame($refundRow->idempotency_key, $call->providerIdempotencyKey);
        // 'FAKE' under the test environment (FakePaymentGateway) - the
        // real Stripe adapter writes 'STRIPE' in every other environment,
        // resolved the same way payment_attempts.provider_code already is.
        $this->assertSame($this->fakeGateway()->providerCode(), $refundRow->provider_code);
    }

    public function test_transient_stripe_failure_leaves_the_refund_pending_and_retryable(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => now()->addDays(2),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        $this->fakeGateway()->queueNextRefund(
            RefundCreationResult::unknown('simulated network timeout')
        );

        $response = $this->cancelBooking($customer['access_token'], $booking);
        $response->assertStatus(200);

        $refundRow = $this->bookingRefundRow($booking);
        $this->assertSame('PENDING', $this->bookingRefundStatusCode($refundRow));
        $this->assertNull($refundRow->provider_refund_reference);

        /*
         * Recovery command retries with the SAME idempotency key and
         * succeeds - never a second, different key.
         */
        $firstIdempotencyKey = $refundRow->idempotency_key;

        app(ExecuteBookingRefundAction::class)
            ->handle(UuidBinary::toString($refundRow->id));

        $resolved = $this->bookingRefundRow($booking);
        $this->assertSame('SUCCEEDED', $this->bookingRefundStatusCode($resolved));
        $this->assertNotNull($resolved->provider_refund_reference);

        $this->assertCount(2, $this->fakeGateway()->refundPaymentCalls);
        $this->assertSame($firstIdempotencyKey, $this->fakeGateway()->refundPaymentCalls[1]->providerIdempotencyKey);
    }

    public function test_definitive_stripe_rejection_marks_the_refund_failed_and_never_retries_automatically(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => now()->addDays(2),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        $this->fakeGateway()->queueNextRefund(
            RefundCreationResult::definitiveFailure('STRIPE_REQUEST_REJECTED', 'already refunded')
        );

        $this->cancelBooking($customer['access_token'], $booking)->assertStatus(200);

        $refundRow = $this->bookingRefundRow($booking);
        $this->assertSame('FAILED', $this->bookingRefundStatusCode($refundRow));
        $this->assertSame('STRIPE_REQUEST_REJECTED', $refundRow->failure_code);
        $this->assertNotNull($refundRow->failed_at);

        // The recovery command's own query only ever selects PENDING rows,
        // so a FAILED obligation is never retried automatically.
        $this->assertSame(
            0,
            DB::table('booking_refunds')->where('status_id', BookingRefundStatuses::id('PENDING'))->count()
        );
    }

    public function test_cancellation_never_mutates_the_captured_payment_amount(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => now()->addDays(2),
        ]);

        $booking = $this->bookingRowForPayment($payment);
        $before = DB::table('payment_attempts')->where('id', $payment->id)->first();

        $this->cancelBooking($customer['access_token'], $booking)->assertStatus(200);

        $after = DB::table('payment_attempts')->where('id', $payment->id)->first();

        $this->assertSame((string) $before->confirmed_amount, (string) $after->confirmed_amount);
        $this->assertSame($before->status_id, $after->status_id);
        $this->assertSame($before->provider_transaction_reference, $after->provider_transaction_reference);
    }

    // -----------------------------------------------------------------
    // FIX PHASE item 7 - confirmed_amount is the ONLY financial source
    // of truth for an automated refund; never silently substitute
    // requested_amount.
    // -----------------------------------------------------------------

    public function test_cancellation_rejects_when_confirmed_amount_is_missing_no_mutation(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => now()->addDays(2),
        ]);
        $booking = $this->bookingRowForPayment($payment);

        // chk_payment_attempts_successful_at requires successful_at to be
        // null whenever confirmed_amount is null - both are cleared
        // together to keep this an otherwise-valid reconciliation-failure
        // row.
        DB::table('payment_attempts')->where('id', $payment->id)->update([
            'confirmed_amount' => null,
            'successful_at' => null,
        ]);

        $response = $this->cancelBooking($customer['access_token'], $booking);

        $response->assertStatus(409);
        $this->assertStringContainsString('confirmed amount', strtolower($response->json('message')));

        $this->assertSame('PAID', $this->bookingStatus($booking));
        $this->assertNull($this->bookingRefundRow($booking));
        $this->assertCount(0, $this->fakeGateway()->refundPaymentCalls);

        $fresh = DB::table('bookings')->where('id', $booking->id)->first();
        $this->assertNull($fresh->cancellation_refund_percentage);
        $this->assertNull($fresh->cancellation_refund_amount);
    }

    // -----------------------------------------------------------------
    // FIX PHASE item 6 - the amount persisted as the refund obligation
    // and the integer amount sent to Stripe must represent the SAME
    // final monetary amount, and a sub-minor-unit remainder must never
    // silently truncate to a zero-value Stripe refund.
    // -----------------------------------------------------------------

    public function test_refund_amount_persisted_matches_the_normalized_amount_sent_to_stripe(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);
        DB::table('payment_attempts')->where('id', $payment->id)->update(['confirmed_amount' => '99.99']);

        $booking = $this->bookingRowForPayment($payment);
        Carbon::setTestNow('2026-09-15 05:00:00');

        $response = $this->cancelBooking($customer['access_token'], $booking);

        $response->assertStatus(200)->assertJsonPath('data.refund_due.percentage', 75);

        // 99.99 x 75% = 74.9925 -> normalized (half-up at AED's 2 decimal
        // places) to 74.99 BEFORE persistence - never the raw 74.992500
        // figure. Formatted at decimal(19,6), matching every other money
        // field this API returns (e.g. payment_attempts.confirmed_amount).
        $this->assertSame('74.990000', $response->json('data.refund_due.amount'));

        $refundRow = $this->bookingRefundRow($booking);
        $this->assertSame('74.990000', (string) $refundRow->requested_amount);

        // What is actually sent to the gateway/Stripe represents the exact
        // same 74.99 - never a different figure than what was persisted.
        $call = $this->fakeGateway()->refundPaymentCalls[0];
        $this->assertSame(0, bccomp($call->amount, '74.99', 2));
        $this->assertSame(7499, MinorUnitConverter::toMinorUnits($call->amount, 2));
    }

    public function test_refund_below_the_smallest_currency_unit_is_marked_failed_without_calling_stripe(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => now()->addDays(2),
        ]);

        // An amount so small that, even after correct half-up rounding,
        // 75% of it rounds to zero AED - the pathological case
        // roundToMinorUnit() cannot rescue (< half a minor unit).
        DB::table('payment_attempts')->where('id', $payment->id)->update(['confirmed_amount' => '0.001']);

        $booking = $this->bookingRowForPayment($payment);

        $this->cancelBooking($customer['access_token'], $booking)->assertStatus(200);

        $refundRow = $this->bookingRefundRow($booking);
        $this->assertSame('FAILED', $this->bookingRefundStatusCode($refundRow));
        $this->assertSame('REFUND_AMOUNT_BELOW_MINIMUM_UNIT', $refundRow->failure_code);

        // Stripe must never be called with a zero-value refund.
        $this->assertCount(0, $this->fakeGateway()->refundPaymentCalls);
    }
}
