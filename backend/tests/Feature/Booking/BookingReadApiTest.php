<?php

namespace Tests\Feature\Booking;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Booking\Concerns\CreatesBookingFixtures;
use Tests\TestCase;

class BookingReadApiTest extends TestCase
{
    use CreatesBookingFixtures;
    use DatabaseTransactions;

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

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

    public function test_get_booking_returns_the_owning_customers_booking(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        $response = $this->getBooking($customer['access_token'], UuidBinary::toString($booking->id));

        $response->assertStatus(200);
        $this->assertSame(UuidBinary::toString($booking->id), $response->json('data.booking.uuid'));
        $this->assertSame('PAID', $response->json('data.booking.status'));
    }

    public function test_list_bookings_returns_only_the_authenticated_customers_bookings(): void
    {
        ['customer' => $customerA, 'payment' => $paymentA] = $this->successfulPayment(['starts_at' => now()->addDays(2)]);
        ['payment' => $paymentB] = $this->successfulPayment(['starts_at' => now()->addDays(3)]);

        $bookingA = $this->bookingRowForPayment($paymentA);
        $bookingB = $this->bookingRowForPayment($paymentB);

        $response = $this->listBookings($customerA['access_token']);

        $response->assertStatus(200);
        $uuids = collect($response->json('data.bookings'))->pluck('uuid')->all();

        $this->assertContains(UuidBinary::toString($bookingA->id), $uuids);
        $this->assertNotContains(UuidBinary::toString($bookingB->id), $uuids);
    }

    // 20. Foreign customer cannot read Booking.
    public function test_a_foreign_customer_cannot_read_another_customers_booking(): void
    {
        ['payment' => $payment] = $this->successfulPayment(['starts_at' => now()->addDays(2)]);
        $booking = $this->bookingRowForPayment($payment);

        $otherCustomer = $this->createAuthenticatedCartCustomer();

        $response = $this->getBooking($otherCustomer['access_token'], UuidBinary::toString($booking->id));

        $response->assertStatus(404);
        $this->assertNull($response->json('data'));
    }

    // 21. Malformed / unknown Booking UUID -> safe 404.
    public function test_unknown_booking_uuid_returns_safe_404(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $response = $this->getBooking($customer['access_token'], (string) Str::uuid());

        $response->assertStatus(404);
    }

    public function test_malformed_booking_uuid_returns_safe_404(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $response = $this->getBooking($customer['access_token'], 'not-a-real-uuid');

        $response->assertStatus(404);
    }

    public function test_booking_read_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/bookings')->assertStatus(401);
        $this->getJson('/api/v1/bookings/'.Str::uuid())->assertStatus(401);
    }

    // 22. Booking API exposes UUID strings only - never raw binary(16) ids.
    public function test_booking_response_exposes_only_uuid_strings(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        $response = $this->getBooking($customer['access_token'], UuidBinary::toString($booking->id));
        $data = $response->json('data.booking');

        $this->assertMatchesRegularExpression(self::UUID_PATTERN, $data['uuid']);
        $this->assertMatchesRegularExpression(self::UUID_PATTERN, $data['appointment']['slot']['uuid']);
        $this->assertNotEmpty($data['items']);

        foreach ($data['items'] as $item) {
            $this->assertMatchesRegularExpression(self::UUID_PATTERN, $item['uuid']);
            $this->assertMatchesRegularExpression(self::UUID_PATTERN, $item['service']['uuid']);
            $this->assertMatchesRegularExpression(self::UUID_PATTERN, $item['pricing']['pricing_scheme_version_uuid']);
        }
    }

    // 23. No Stripe/payment secrets or internal pricing-rule/provider
    // details ever leak through the Booking read API.
    public function test_booking_response_never_leaks_payment_or_provider_internals(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        $response = $this->getBooking($customer['access_token'], UuidBinary::toString($booking->id));
        $data = $response->json('data.booking');

        foreach (['payment_attempt_id', 'checkout_snapshot', 'checkout_snapshot_hash', 'client_secret', 'idempotency_key', 'provider', 'reconciliation_reason_code'] as $forbiddenKey) {
            $this->assertArrayNotHasKey($forbiddenKey, $data);
        }

        $raw = strtolower($response->getContent());
        foreach (['client_secret', 'stripe', 'cvv', 'password', 'pan', 'checkout_snapshot_hash'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw);
        }
    }

    public function test_booking_response_is_clean_utf8_json_with_no_raw_binary_leakage(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        $response = $this->getBooking($customer['access_token'], UuidBinary::toString($booking->id));

        // A raw binary(16) id leaking into the payload would break clean
        // UTF-8 JSON encoding outright - json_decode succeeding here is
        // itself proof every id was converted through UuidBinary::toString()
        // before serialization.
        json_decode($response->getContent(), true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());

    }

    // 24. Non-cancelled Booking read response never claims a refund is due.
    public function test_non_cancelled_booking_response_does_not_incorrectly_claim_a_refund_is_due(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        $response = $this->getBooking($customer['access_token'], UuidBinary::toString($booking->id));

        $response->assertStatus(200);
        $data = $response->json('data.booking');

        $this->assertArrayHasKey('refund_due', $data);
        $this->assertNull($data['refund_due']);
    }

    // 25. Customer GET on a CANCELLED Booking exposes cancelled_at and the
    // correct refund_due percentage/amount/execution.
    public function test_customer_get_cancelled_booking_exposes_cancelled_at_and_refund_due(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);
        $booking = $this->bookingRowForPayment($payment);

        Carbon::setTestNow('2026-09-14 20:00:00');

        $this->cancelBooking($customer['access_token'], $booking)->assertStatus(200);

        $response = $this->getBooking($customer['access_token'], UuidBinary::toString($booking->id));

        $response->assertStatus(200);
        $data = $response->json('data.booking');

        $this->assertSame('CANCELLED', $data['status']);
        $this->assertNotNull($data['cancelled_at']);

        $paidAmount = (string) DB::table('payment_attempts')->where('id', $payment->id)->value('confirmed_amount');
        $expectedRefund = bcdiv(bcmul($paidAmount, '100', 6), '100', 6);

        $this->assertSame(100, $data['refund_due']['percentage']);
        $this->assertSame($expectedRefund, $data['refund_due']['amount']);
        $this->assertSame('MANUAL', $data['refund_due']['execution']);
    }

    // 26. Refund calculation on read uses the ORIGINAL cancelled_at, and
    // stays consistent with what the cancel endpoint itself returned - not
    // a fresh recompute against "now".
    public function test_get_booking_refund_due_is_consistent_with_the_cancel_response_and_uses_original_cancelled_at(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);
        $booking = $this->bookingRowForPayment($payment);

        /*
         * Cancel before appointment day - 100% is due and locked in.
         */
        Carbon::setTestNow('2026-09-14 20:00:00');

        $cancelResponse = $this->cancelBooking($customer['access_token'], $booking);
        $cancelResponse->assertStatus(200);

        /*
         * Move "now" onto the appointment day before reading (but not so
         * far forward that the customer's login session itself would have
         * expired) - if the read API recomputed against "now" instead of
         * the persisted cancelled_at, this would incorrectly drop to 75%.
         */
        Carbon::setTestNow('2026-09-15 10:30:00');

        $getResponse = $this->getBooking($customer['access_token'], UuidBinary::toString($booking->id));
        $getResponse->assertStatus(200);

        $this->assertSame(
            $cancelResponse->json('data.refund_due'),
            $getResponse->json('data.booking.refund_due')
        );

        $this->assertTrue(
            Carbon::parse($cancelResponse->json('data.booking.cancelled_at'))
                ->equalTo(Carbon::parse($getResponse->json('data.booking.cancelled_at')))
        );

        $this->assertSame(100, $getResponse->json('data.booking.refund_due.percentage'));
    }

    // 27. List Bookings (which shares BookingPresenter with Get) also
    // exposes refund_due for a CANCELLED Booking in the list.
    public function test_list_bookings_exposes_refund_due_for_a_cancelled_booking(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);
        $booking = $this->bookingRowForPayment($payment);

        Carbon::setTestNow('2026-09-15 05:00:00');

        $this->cancelBooking($customer['access_token'], $booking)->assertStatus(200);

        $response = $this->listBookings($customer['access_token']);
        $response->assertStatus(200);

        $row = collect($response->json('data.bookings'))
            ->firstWhere('uuid', UuidBinary::toString($booking->id));

        $this->assertNotNull($row);
        $this->assertSame('CANCELLED', $row['status']);
        $this->assertSame(75, $row['refund_due']['percentage']);
    }

    // 28. refund_due on a CANCELLED Booking is strictly
    // {percentage, amount, execution} - no payment/provider internals.
    public function test_cancelled_booking_refund_due_never_leaks_payment_or_provider_internals(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);
        $booking = $this->bookingRowForPayment($payment);

        Carbon::setTestNow('2026-09-14 20:00:00');
        $this->cancelBooking($customer['access_token'], $booking)->assertStatus(200);

        $response = $this->getBooking($customer['access_token'], UuidBinary::toString($booking->id));
        $response->assertStatus(200);

        $refundDue = $response->json('data.booking.refund_due');
        $this->assertSame(['percentage', 'amount', 'execution'], array_keys($refundDue));

        $this->assertStringNotContainsString('client_secret', strtolower($response->getContent()));
    }

    // 29. Customer GET on a CANCELLED Booking keeps showing the ORIGINAL
    // refund_due percentage/amount after the cancellation policy config
    // changes - the read API must never recompute from current config.
    public function test_customer_get_cancelled_booking_refund_due_survives_a_later_policy_config_change(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment([
            'starts_at' => Carbon::parse('2026-09-15 10:00:00'),
        ]);
        $booking = $this->bookingRowForPayment($payment);

        Carbon::setTestNow('2026-09-14 20:00:00');

        $this->cancelBooking($customer['access_token'], $booking)->assertStatus(200);

        $paidAmount = (string) DB::table('payment_attempts')->where('id', $payment->id)->value('confirmed_amount');
        $expectedAmount = bcdiv(bcmul($paidAmount, '100', 6), '100', 6);

        /*
         * Company changes its cancellation policy AFTER this Booking was
         * already cancelled.
         */
        config([
            'cancellation.before_appointment_day_percentage' => 90,
            'cancellation.appointment_day_percentage' => 50,
        ]);

        $response = $this->getBooking($customer['access_token'], UuidBinary::toString($booking->id));
        $response->assertStatus(200);

        $this->assertSame(100, $response->json('data.booking.refund_due.percentage'));
        $this->assertSame($expectedAmount, $response->json('data.booking.refund_due.amount'));
        $this->assertSame('MANUAL', $response->json('data.booking.refund_due.execution'));
    }
}
