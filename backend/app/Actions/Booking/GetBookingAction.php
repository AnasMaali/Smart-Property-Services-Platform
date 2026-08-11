<?php

namespace App\Actions\Booking;

use App\Support\Booking\BookingPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only, ownership-scoped Booking lookup (GET /v1/bookings/{booking}).
 * Ownership is always booking -> cart -> customer_user_id, mirroring
 * App\Actions\Payment\GetPaymentAction exactly - there is no separate
 * customer_id column on `bookings` by schema design. A foreign or unknown
 * Booking UUID is reported identically as 404, never 403, so a customer
 * can never distinguish "not yours" from "does not exist."
 */
class GetBookingAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $userUuid, string $bookingUuid): array
    {
        try {
            $bookingIdBinary = UuidBinary::toBinary($bookingUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Booking not found.');
        }

        $userIdBinary = UuidBinary::toBinary($userUuid);

        $row = DB::table('bookings')
            ->join('carts', 'carts.id', '=', 'bookings.cart_id')
            ->where('bookings.id', $bookingIdBinary)
            ->where('carts.customer_user_id', $userIdBinary)
            ->first(['bookings.*', 'carts.currency_id as cart_currency_id']);

        if ($row === null) {
            return $this->notFound('Booking not found.');
        }

        return $this->ok(200, 'Booking retrieved successfully.', ['booking' => BookingPresenter::present($row)]);
    }
}
