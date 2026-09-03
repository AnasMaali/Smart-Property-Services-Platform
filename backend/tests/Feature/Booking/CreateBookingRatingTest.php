<?php

namespace Tests\Feature\Booking;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Booking\Concerns\CreatesBookingFixtures;
use Tests\TestCase;

class CreateBookingRatingTest extends TestCase
{
    use CreatesBookingFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    /**
     * @return array{customer: array{user_uuid: string, access_token: string}, booking: object, bookingUuid: string}
     */
    private function completedBooking(): array
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);

        DB::table('bookings')->where('id', $booking->id)->update([
            'status_id' => DB::table('booking_statuses')->where('code', 'COMPLETED')->value('id'),
            'completed_at' => $booking->created_at,
        ]);

        return [
            'customer' => $customer,
            'booking' => $booking,
            'bookingUuid' => UuidBinary::toString($booking->id),
        ];
    }

    private function rateUrl(string $bookingUuid): string
    {
        return '/api/v1/bookings/'.$bookingUuid.'/rating';
    }

    public function test_unauthenticated_request_cannot_rate_a_booking(): void
    {
        $this->postJson($this->rateUrl(UuidBinary::generate()), ['rating_value' => 5])
            ->assertStatus(401);
    }

    public function test_paid_booking_cannot_be_rated(): void
    {
        ['customer' => $customer, 'payment' => $payment] = $this->successfulPayment();
        $booking = $this->bookingRowForPayment($payment);
        $bookingUuid = UuidBinary::toString($booking->id);

        $this->postJson(
            $this->rateUrl($bookingUuid),
            ['rating_value' => 5],
            ['Authorization' => 'Bearer '.$customer['access_token']]
        )->assertStatus(409);

        $this->getBooking($customer['access_token'], $bookingUuid)
            ->assertStatus(200)
            ->assertJsonPath('data.booking.can_rate', false)
            ->assertJsonPath('data.booking.rating', null);
    }

    public function test_completed_booking_is_rateable_and_rating_is_returned_on_get(): void
    {
        $fixture = $this->completedBooking();

        $this->getBooking($fixture['customer']['access_token'], $fixture['bookingUuid'])
            ->assertStatus(200)
            ->assertJsonPath('data.booking.can_rate', true)
            ->assertJsonPath('data.booking.rating', null);

        $response = $this->postJson(
            $this->rateUrl($fixture['bookingUuid']),
            ['rating_value' => 4, 'comment' => 'Great visit.'],
            ['Authorization' => 'Bearer '.$fixture['customer']['access_token']]
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.rating.booking_uuid', $fixture['bookingUuid'])
            ->assertJsonPath('data.rating.rating_value', 4)
            ->assertJsonPath('data.rating.comment', 'Great visit.');

        $this->getBooking($fixture['customer']['access_token'], $fixture['bookingUuid'])
            ->assertStatus(200)
            ->assertJsonPath('data.booking.can_rate', false)
            ->assertJsonPath('data.booking.rating.rating_value', 4)
            ->assertJsonPath('data.booking.rating.comment', 'Great visit.');
    }

    public function test_duplicate_rating_is_rejected(): void
    {
        $fixture = $this->completedBooking();

        $this->postJson(
            $this->rateUrl($fixture['bookingUuid']),
            ['rating_value' => 5],
            ['Authorization' => 'Bearer '.$fixture['customer']['access_token']]
        )->assertStatus(201);

        $this->postJson(
            $this->rateUrl($fixture['bookingUuid']),
            ['rating_value' => 3],
            ['Authorization' => 'Bearer '.$fixture['customer']['access_token']]
        )->assertStatus(409);
    }

    public function test_foreign_customer_cannot_rate_another_customers_booking(): void
    {
        $fixture = $this->completedBooking();
        $other = $this->createAuthenticatedCartCustomer();

        $this->postJson(
            $this->rateUrl($fixture['bookingUuid']),
            ['rating_value' => 5],
            ['Authorization' => 'Bearer '.$other['access_token']]
        )->assertStatus(404);
    }

    public function test_invalid_rating_value_is_rejected(): void
    {
        $fixture = $this->completedBooking();

        $this->postJson(
            $this->rateUrl($fixture['bookingUuid']),
            ['rating_value' => 6],
            ['Authorization' => 'Bearer '.$fixture['customer']['access_token']]
        )->assertStatus(422);
    }
}
