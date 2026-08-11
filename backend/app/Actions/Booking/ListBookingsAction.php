<?php

namespace App\Actions\Booking;

use App\Support\Booking\BookingPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * Read-only, ownership-scoped Booking listing (GET /v1/bookings) - every
 * Booking belonging to the authenticated customer, newest first. Never
 * reveals another customer's Bookings; never reprices or re-reads live
 * catalog/profile data (see App\Support\Booking\BookingPresenter).
 */
class ListBookingsAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $userUuid): array
    {
        $userIdBinary = UuidBinary::toBinary($userUuid);

        $rows = DB::table('bookings')
            ->join('carts', 'carts.id', '=', 'bookings.cart_id')
            ->where('carts.customer_user_id', $userIdBinary)
            ->orderByDesc('bookings.created_at')
            ->get(['bookings.*', 'carts.currency_id as cart_currency_id']);

        $bookings = $rows->map(BookingPresenter::present(...))->all();

        return $this->ok(200, 'Bookings retrieved successfully.', ['bookings' => $bookings]);
    }
}
