<?php

namespace Tests\Feature\Booking\Concerns;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Payment\Concerns\CreatesPaymentFixtures;

/**
 * Booking-specific fixture builders, layered on top of the Phase 6A/6B
 * CreatesPaymentFixtures - every Booking test needs a payment attempt that
 * has already reached a driven-to-completion webhook outcome.
 */
trait CreatesBookingFixtures
{
    use CreatesPaymentFixtures;

    /**
     * Drives a brand new ready-for-payment customer all the way through a
     * clean SUCCEEDED webhook (amount matching, no reconciliation issue),
     * which - with Phase 7A wired into ProcessPaymentWebhookAction - also
     * triggers the one automatic Booking conversion attempt.
     *
     * @return array{customer: array{user_uuid: string, access_token: string}, payment: object, response: TestResponse}
     */
    private function successfulPayment(array $slotOverrides = []): array
    {
        $customer = $this->readyForPaymentCustomer($slotOverrides);
        $createResponse = $this->createPayment($customer['access_token'], (string) Str::uuid());
        $row = $this->paymentRow($createResponse->json('data.payment.uuid'));

        $webhookResponse = $this->postWebhook($this->fakeWebhookPayload([
            'provider_session_reference' => $row->provider_session_reference,
            'outcome' => 'SUCCEEDED',
            'amount' => $row->requested_amount,
        ]));

        return [
            'customer' => $customer,
            'payment' => $this->paymentRow(UuidBinary::toString($row->id)),
            'response' => $webhookResponse,
        ];
    }

    private function bookingRowForPayment(object $payment): ?object
    {
        return DB::table('bookings')->where('payment_attempt_id', $payment->id)->first();
    }

    private function bookingRow(string $bookingUuid): ?object
    {
        return DB::table('bookings')->where('id', UuidBinary::toBinary($bookingUuid))->first();
    }

    /**
     * BLUE V1 Phase B20 - the `booking_refunds` execution obligation row
     * for a Booking, if one was created.
     */
    private function bookingRefundRow(object $booking): ?object
    {
        return DB::table('booking_refunds')->where('booking_id', $booking->id)->first();
    }

    private function bookingRefundStatusCode(object $refundRow): ?string
    {
        return DB::table('booking_refund_statuses')->where('id', $refundRow->status_id)->value('code');
    }

    private function listBookings(string $accessToken): TestResponse
    {
        return $this->getJson('/api/v1/bookings', ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function getBooking(string $accessToken, string $bookingUuid): TestResponse
    {
        return $this->getJson('/api/v1/bookings/'.$bookingUuid, ['Authorization' => 'Bearer '.$accessToken]);
    }
}
