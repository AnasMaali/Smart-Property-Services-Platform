<?php

namespace App\Actions\Booking;

use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateBookingRatingAction
{
    use BuildsCartResult;

    /**
     * @param  array{rating_value: int, comment?: ?string}  $data
     * @return array<string, mixed>
     */
    public function handle(string $customerUserUuid, string $bookingUuid, array $data): array
    {
        try {
            $bookingIdBinary = UuidBinary::toBinary($bookingUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Booking not found.');
        }

        return DB::transaction(function () use ($customerUserUuid, $bookingIdBinary, $bookingUuid, $data): array {
            $booking = DB::table('bookings')
                ->join('carts', 'carts.id', '=', 'bookings.cart_id')
                ->join('booking_statuses', 'booking_statuses.id', '=', 'bookings.status_id')
                ->where('bookings.id', $bookingIdBinary)
                ->where('carts.customer_user_id', UuidBinary::toBinary($customerUserUuid))
                ->lockForUpdate()
                ->first(['bookings.id', 'booking_statuses.code as status_code']);

            if ($booking === null) {
                return $this->notFound('Booking not found.');
            }

            if ($booking->status_code !== 'COMPLETED') {
                return $this->conflict('Only completed bookings can be rated.');
            }

            $existing = DB::table('ratings')->where('booking_id', $bookingIdBinary)->exists();
            if ($existing) {
                return $this->conflict('This booking has already been rated.');
            }

            $now = now();
            $comment = isset($data['comment']) ? trim((string) $data['comment']) : null;
            if ($comment === '') {
                $comment = null;
            }

            DB::table('ratings')->insert([
                'booking_id' => $bookingIdBinary,
                'rating_value' => (int) $data['rating_value'],
                'comment' => $comment,
                'created_at' => $now,
            ]);

            return $this->ok(201, 'Rating submitted successfully.', [
                'rating' => [
                    'booking_uuid' => $bookingUuid,
                    'rating_value' => (int) $data['rating_value'],
                    'comment' => $comment,
                    'created_at' => Carbon::parse($now)->toIso8601String(),
                ],
            ]);
        });
    }
}
