<?php

namespace Tests\Feature\Booking;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Technician\Concerns\CreatesTechnicianFixtures;
use Tests\TestCase;
use App\Actions\Technician\AssignTechnicianToBookingItemAction;

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
                        'execution' => 'MANUAL',
                    ],
                ],
            ]);

        $this->assertSame(
            'CANCELLED',
            $this->bookingStatus($booking)
        );

        /*
         * Cancellation must not execute a Stripe refund.
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
                        'execution' => 'MANUAL',
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
    }

    // A) Cancellation before appointment day snapshots 100% and a later
    // policy config change never alters the persisted snapshot.
    public function test_refund_snapshot_before_appointment_day_survives_a_later_policy_config_change(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        Carbon::setTestNow('2026-09-14 20:00:00');

        $response = $this->cancelBooking($customer['access_token'], $booking);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.refund_due.percentage', 100);

        $paidAmount = (string) DB::table('payment_attempts')->where('id', $payment->id)->value('confirmed_amount');
        $expectedAmount = bcdiv(bcmul($paidAmount, '100', 6), '100', 6);
        $this->assertSame($expectedAmount, $response->json('data.refund_due.amount'));

        /*
         * Persisted immediately, in the same transaction as the
         * cancellation itself.
         */
        $row = DB::table('bookings')->where('id', $booking->id)->first();
        $this->assertSame(100, (int) $row->cancellation_refund_percentage);
        $this->assertSame($expectedAmount, (string) $row->cancellation_refund_amount);

        /*
         * Company changes its cancellation policy AFTER this Booking was
         * already cancelled.
         */
        config([
            'cancellation.before_appointment_day_percentage' => 90,
            'cancellation.appointment_day_percentage' => 50,
        ]);

        $rowAfterConfigChange = DB::table('bookings')->where('id', $booking->id)->first();
        $this->assertSame(100, (int) $rowAfterConfigChange->cancellation_refund_percentage);
        $this->assertSame($expectedAmount, (string) $rowAfterConfigChange->cancellation_refund_amount);
    }

    // B) Cancellation on/after the appointment day snapshots 75% and a
    // later policy config change never alters the persisted snapshot.
    public function test_refund_snapshot_on_appointment_day_survives_a_later_policy_config_change(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        Carbon::setTestNow('2026-09-15 00:00:00');

        $response = $this->cancelBooking($customer['access_token'], $booking);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.refund_due.percentage', 75);

        config([
            'cancellation.before_appointment_day_percentage' => 90,
            'cancellation.appointment_day_percentage' => 50,
        ]);

        $row = DB::table('bookings')->where('id', $booking->id)->first();
        $this->assertSame(75, (int) $row->cancellation_refund_percentage);
    }

    // C) A retry after the policy config changed must return the ORIGINAL
    // snapshot, not a fresh recalculation, and must not overwrite it in
    // the database.
    public function test_cancellation_retry_after_config_change_returns_original_snapshot_and_never_overwrites_it(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        Carbon::setTestNow('2026-09-14 20:00:00');

        $first = $this->cancelBooking($customer['access_token'], $booking);
        $first->assertStatus(200)->assertJsonPath('data.refund_due.percentage', 100);

        $rowAfterFirst = DB::table('bookings')->where('id', $booking->id)->first();

        config([
            'cancellation.before_appointment_day_percentage' => 90,
            'cancellation.appointment_day_percentage' => 50,
        ]);

        $retry = $this->cancelBooking($customer['access_token'], $booking);

        $retry
            ->assertStatus(200)
            ->assertJsonPath('data.refund_due.percentage', 100)
            ->assertJsonPath('data.refund_due.amount', $rowAfterFirst->cancellation_refund_amount);

        $rowAfterRetry = DB::table('bookings')->where('id', $booking->id)->first();

        $this->assertSame(
            (int) $rowAfterFirst->cancellation_refund_percentage,
            (int) $rowAfterRetry->cancellation_refund_percentage
        );
        $this->assertSame(
            (string) $rowAfterFirst->cancellation_refund_amount,
            (string) $rowAfterRetry->cancellation_refund_amount
        );

        /*
         * Payment still SUCCESSFUL through the whole config-drift scenario.
         */
        $this->assertSame('SUCCESSFUL', $this->paymentStatus($payment));
    }

    // D) Snapshot amount is exact BCMath decimal arithmetic against the
    // actual confirmed paid amount - never float rounding.
    public function test_refund_snapshot_amount_matches_exact_decimal_arithmetic_of_the_paid_amount(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        Carbon::setTestNow('2026-09-15 08:00:00');

        $response = $this->cancelBooking($customer['access_token'], $booking);
        $response->assertStatus(200)->assertJsonPath('data.refund_due.percentage', 75);

        $paidAmount = (string) DB::table('payment_attempts')->where('id', $payment->id)->value('confirmed_amount');
        $expectedAmount = bcdiv(bcmul($paidAmount, '75', 6), '100', 6);

        $this->assertSame($expectedAmount, $response->json('data.refund_due.amount'));

        $row = DB::table('bookings')->where('id', $booking->id)->first();
        $this->assertSame($expectedAmount, (string) $row->cancellation_refund_amount);
    }

    // E) A non-cancelled Booking never has a refund snapshot.
    public function test_non_cancelled_booking_has_no_refund_snapshot(): void
    {
        ['payment' => $payment] = $this->successfulPayment([
            'starts_at' => now()->addDays(2),
        ]);

        $booking = $this->bookingRowForPayment($payment);

        $row = DB::table('bookings')->where('id', $booking->id)->first();

        $this->assertNull($row->cancellation_refund_percentage);
        $this->assertNull($row->cancellation_refund_amount);
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

    $assignmentResult = app(
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
        ->assertJsonPath('data.booking.status', 'CANCELLED')
        ->assertJsonPath('data.refund_due.percentage', 100);

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

    $this->assertNotNull($itemAfter->cancelled_at);

    /*
     * Technician assignment is released, never deleted.
     */
    $assignmentAfter = DB::table('technician_assignments')
        ->where('id', $activeAssignment->id)
        ->first();

    $this->assertNotNull($assignmentAfter);
    $this->assertNotNull($assignmentAfter->released_at);

    $this->assertSame(
        UuidBinary::toBinary($fixture['customer']['user_uuid']),
        $assignmentAfter->released_by_user_id
    );

    $this->assertSame(
        'Customer cancelled booking.',
        $assignmentAfter->release_reason
    );

    /*
     * Payment is still SUCCESSFUL.
     * Refund execution remains manual in Stripe.
     */
    $this->assertSame(
        'SUCCESSFUL',
        $this->paymentStatus($fixture['payment'])
    );
}
public function test_completed_booking_cannot_be_cancelled(): void
{
    ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
        'starts_at' => now()->addDays(2),
    ]);

    $booking = $this->bookingRowForPayment($payment);

    $bookingUuid = UuidBinary::toString($booking->id);

    app(\App\Actions\Booking\TransitionBookingStatusAction::class)
        ->assign($bookingUuid);

    app(\App\Actions\Booking\TransitionBookingStatusAction::class)
        ->start($bookingUuid);

    app(\App\Actions\Booking\TransitionBookingStatusAction::class)
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
}