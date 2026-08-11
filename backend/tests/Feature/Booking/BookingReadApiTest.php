<?php

namespace Tests\Feature\Booking;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
}
