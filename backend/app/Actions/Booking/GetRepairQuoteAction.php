<?php

namespace App\Actions\Booking;

use App\Support\Booking\CustomerRepairQuotePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * BLUE V1 Phase B25 - read-only, ownership-scoped repair quote lookup (GET
 * /v1/bookings/{booking}/quote). Mirrors App\Actions\Booking\
 * GetBookingAction's ownership scoping exactly. A Booking with no repair
 * quote (or a Booking a customer does not own) both resolve to a safe
 * result - "not found" for the latter, `quote: null` for the former (see
 * App\Http\Controllers\Api\V1\Booking\GetRepairQuoteController).
 */
final class GetRepairQuoteAction
{
    use BuildsCartResult;

    public function handle(string $userUuid, string $bookingUuid): array
    {
        try {
            $bookingIdBinary = UuidBinary::toBinary($bookingUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Booking not found.');
        }

        $userIdBinary = UuidBinary::toBinary($userUuid);

        $booking = DB::table('bookings')
            ->join('carts', 'carts.id', '=', 'bookings.cart_id')
            ->where('bookings.id', $bookingIdBinary)
            ->where('carts.customer_user_id', $userIdBinary)
            ->first(['bookings.id']);

        if ($booking === null) {
            return $this->notFound('Booking not found.');
        }

        $quote = CustomerRepairQuotePresenter::forBooking($bookingIdBinary);

        return $this->ok(200, 'Repair quote retrieved successfully.', ['quote' => $quote]);
    }
}
