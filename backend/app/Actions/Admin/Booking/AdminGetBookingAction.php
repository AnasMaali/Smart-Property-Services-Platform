<?php

namespace App\Actions\Admin\Booking;

use App\Support\Admin\AdminBookingPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only Admin Booking detail lookup (BLUE V1 Phase 9B) - unlike
 * App\Actions\Booking\GetBookingAction, never ownership-scoped to one
 * customer. A malformed or unknown Booking UUID is reported identically as
 * 404, matching the existing customer-facing convention.
 */
final class AdminGetBookingAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $bookingUuid): array
    {
        try {
            $bookingIdBinary = UuidBinary::toBinary($bookingUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Booking not found.');
        }

        $row = DB::table('bookings')
            ->join('carts', 'carts.id', '=', 'bookings.cart_id')
            ->where('bookings.id', $bookingIdBinary)
            ->first(['bookings.*', 'carts.customer_user_id', 'carts.currency_id as cart_currency_id']);

        if ($row === null) {
            return $this->notFound('Booking not found.');
        }

        return $this->ok(200, 'Booking retrieved successfully.', ['booking' => AdminBookingPresenter::detail($row)]);
    }
}
