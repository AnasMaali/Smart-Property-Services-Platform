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
        $this->assertFalse($response->json('data.booking.can_rate'));
        $this->assertNull($response->json('data.booking.rating'));
        $this->assertNotEmpty($response->json('data.booking.items.0.service.slug'));
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
        $this->assertSame('AUTOMATIC', $data['refund_due']['execution']);
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

        // The cancel response's refund_due is the immediate policy result
        // {percentage, amount, execution}; the GET response's refund_due
        // (App\Support\Booking\BookingPresenter) additionally carries the
        // later-resolved execution status - so only the shared
        // percentage/amount, both read from the same frozen
        // bookings.cancellation_refund_* snapshot, must agree exactly.
        $this->assertSame(
            $cancelResponse->json('data.refund_due.percentage'),
            $getResponse->json('data.booking.refund_due.percentage')
        );
        $this->assertSame(
            $cancelResponse->json('data.refund_due.amount'),
            $getResponse->json('data.booking.refund_due.amount')
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

    // 28. refund_due on a CANCELLED Booking never leaks payment/provider
    // internals beyond the explicit, safe execution-status fields BLUE V1
    // Phase B20 added - never a raw Stripe object or client_secret.
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
        $this->assertSame(
            ['percentage', 'amount', 'execution', 'status', 'method', 'requested_at', 'succeeded_at', 'failed_at'],
            array_keys($refundDue)
        );

        // The customer-facing API never names the provider, unlike the
        // Admin equivalent (App\Support\Admin\AdminBookingPresenter) -
        // 'method' => 'ORIGINAL_PAYMENT_METHOD' is the one safe, fixed
        // fact exposed here.
        $this->assertStringNotContainsString('client_secret', strtolower($response->getContent()));
        $this->assertStringNotContainsString('stripe', strtolower($response->getContent()));
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
        $this->assertSame('AUTOMATIC', $response->json('data.booking.refund_due.execution'));
    }

    // 30. Customer Booking detail response never exposes raw relational FK
    // ids, or Admin/audit/technician-operational fields - either at the
    // top level, nested inside `items`, or anywhere in the raw JSON.
    public function test_customer_booking_detail_never_exposes_internal_admin_or_assignment_fields(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        $response = $this->getBooking($customer['access_token'], UuidBinary::toString($booking->id));

        $response->assertStatus(200);
        $data = $response->json('data.booking');

        $forbiddenTopLevelKeys = [
            // Raw database FK / internal fields.
            'customer_user_id',
            'cart_id',
            'payment_attempt_id',
            'appointment_slot_id',
            'booking_source_id',
            'cart_currency_id',
            'service_contract_id',
            'service_contract_item_id',
            'cancellation_refund_percentage',
            'cancellation_refund_amount',
            // Admin / audit / technician operational fields.
            'technician_assignments',
            'technician_assignment',
            'technician_uuid',
            'assigned_by_user_id',
            'released_by_user_id',
            'release_reason',
            'internal_note',
            'status_history',
            'booking_status_history',
            'changed_by_user_id',
        ];

        foreach ($forbiddenTopLevelKeys as $forbiddenKey) {
            $this->assertArrayNotHasKey($forbiddenKey, $data, "Booking response leaked forbidden field: {$forbiddenKey}");
        }

        // The safe presented `contract` field is intentional and must
        // remain - only the raw FK ids above are forbidden.
        $this->assertArrayHasKey('contract', $data);

        $this->assertNotEmpty($data['items']);

        $forbiddenItemKeys = [
            'booking_id',
            'service_id',
            'status_id',
            'pricing_scheme_version_id',
            'assigned_by_user_id',
            'changed_by_user_id',
            'technician_id',
            'technician_assignment_id',
            'internal_note',
        ];

        foreach ($data['items'] as $item) {
            foreach ($forbiddenItemKeys as $forbiddenKey) {
                $this->assertArrayNotHasKey($forbiddenKey, $item, "Booking item response leaked forbidden field: {$forbiddenKey}");
            }

            // The safe nested identifiers are intentional and must remain.
            $this->assertArrayHasKey('uuid', $item);
            $this->assertArrayHasKey('uuid', $item['service']);
            $this->assertArrayHasKey('pricing_scheme_version_uuid', $item['pricing']);
        }

        $raw = $response->getContent();
        foreach ([
            'payment_attempt_id',
            'customer_user_id',
            'technician_assignments',
            'changed_by_user_id',
            'checkout_snapshot',
            'checkout_snapshot_hash',
        ] as $forbiddenString) {
            $this->assertStringNotContainsString($forbiddenString, $raw, "Raw Booking JSON leaked forbidden field name: {$forbiddenString}");
        }

        $this->assertTrue(mb_check_encoding($raw, 'UTF-8'));
        json_decode($raw, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

    // 31. item.pricing.adjustments (booking_items.pricing_breakdown,
    // decoded verbatim by BookingPresenter) is the fixed, server-controlled
    // PricingAdjustment::toArray() shape - never raw pricing-rule ids,
    // condition/tier internals, or any other injected field.
    public function test_customer_booking_item_pricing_breakdown_exposes_only_the_safe_adjustment_shape(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        $response = $this->getBooking($customer['access_token'], UuidBinary::toString($booking->id));

        $response->assertStatus(200);
        $data = $response->json('data.booking');

        $this->assertNotEmpty($data['items']);

        foreach ($data['items'] as $item) {
            $this->assertArrayHasKey('adjustments', $item['pricing']);
            $this->assertNotEmpty($item['pricing']['adjustments']);

            foreach ($item['pricing']['adjustments'] as $adjustment) {
                $keys = array_keys($adjustment);
                sort($keys);
                $this->assertSame(
                    ['amount_or_factor', 'effect_type', 'label', 'rule_code', 'running_total_after'],
                    $keys
                );

                foreach ([
                    'id',
                    'pricing_rule_id',
                    'pricing_scheme_version_id',
                    'priority',
                    'effect_amount',
                    'effect_subject_type',
                    'effect_subject_service_option_id',
                    'tier_calculation_mode',
                    'tiers',
                    'stop_processing',
                    'condition_groups',
                    'conditions',
                    'rules',
                    'internal_note',
                ] as $forbiddenKey) {
                    $this->assertArrayNotHasKey($forbiddenKey, $adjustment, "Booking item adjustment leaked forbidden field: {$forbiddenKey}");
                }
            }

            // The fixture's one base SET_PRICE rule (createCartPricingRule's
            // default, via createPricedCartService) - proves the safe
            // fields still carry the real rule data through storage and
            // back, not just an empty shape.
            $baseAdjustment = $item['pricing']['adjustments'][0];
            $this->assertStringStartsWith('BASE_', $baseAdjustment['rule_code']);
            $this->assertSame('Base price', $baseAdjustment['label']);
            $this->assertSame('SET_PRICE', $baseAdjustment['effect_type']);
            $this->assertSame('100.000000', $baseAdjustment['amount_or_factor']);
            $this->assertSame('100.000000', $baseAdjustment['running_total_after']);
        }

        $raw = $response->getContent();
        foreach ([
            'condition_groups',
            'pricing_rule_id',
            'effect_subject_service_option_id',
            'tier_calculation_mode',
            'stop_processing',
        ] as $forbiddenString) {
            $this->assertStringNotContainsString($forbiddenString, $raw, "Raw Booking JSON leaked forbidden pricing-rule field name: {$forbiddenString}");
        }

        $this->assertTrue(mb_check_encoding($raw, 'UTF-8'));
        json_decode($raw, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }
}
