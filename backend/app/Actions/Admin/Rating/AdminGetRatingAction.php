<?php

namespace App\Actions\Admin\Rating;

use App\Support\Admin\AdminRatingPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * `ratings.booking_id` is its own primary key (a Booking has at most one
 * Rating), so the Admin identifier for a Rating is simply the Booking's own
 * UUID - there is no separate "rating id" anywhere in the schema.
 */
final class AdminGetRatingAction
{
    use BuildsCartResult;

    public function handle(string $bookingUuid): array
    {
        try {
            $bookingIdBinary = UuidBinary::toBinary($bookingUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Rating not found.');
        }

        $row = DB::table('ratings')
            ->join('bookings', 'bookings.id', '=', 'ratings.booking_id')
            ->join('booking_statuses', 'booking_statuses.id', '=', 'bookings.status_id')
            ->join('carts', 'carts.id', '=', 'bookings.cart_id')
            ->where('ratings.booking_id', $bookingIdBinary)
            ->first([
                'ratings.booking_id',
                'ratings.rating_value',
                'ratings.comment',
                'ratings.created_at',
                'bookings.booking_number',
                'booking_statuses.code as booking_status',
                'carts.customer_user_id',
            ]);

        if ($row === null) {
            return $this->notFound('Rating not found.');
        }

        return $this->ok(200, 'Rating retrieved successfully.', ['rating' => AdminRatingPresenter::detail($row)]);
    }
}
