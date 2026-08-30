<?php

namespace App\Actions\Booking;

use App\Support\Booking\BookingItemRepairQuoteStatuses;
use App\Support\Booking\CustomerRepairQuotePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * BLUE V1 Phase B25 - customer decline of a SENT repair quote (POST
 * /v1/bookings/{booking}/quote/decline). Mirrors App\Actions\Booking\
 * AcceptRepairQuoteAction's ownership scoping exactly. A repeated decline
 * on an already-DECLINED quote is a safe, deterministic 200 no-op; an
 * already-ACCEPTED quote can never be declined through this ordinary
 * customer endpoint (BLUE V1 catalog spec Phase B25 section 18) - that
 * would silently undo a financial acceptance an Admin/balance-payment flow
 * may already be relying on.
 */
final class DeclineRepairQuoteAction
{
    use BuildsCartResult;

    public function handle(string $userUuid, string $bookingUuid): array
    {
        try {
            $bookingIdBinary = UuidBinary::toBinary($bookingUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Booking not found.');
        }

        return DB::transaction(function () use ($userUuid, $bookingIdBinary): array {
            $userIdBinary = UuidBinary::toBinary($userUuid);

            $booking = DB::table('bookings')
                ->join('carts', 'carts.id', '=', 'bookings.cart_id')
                ->where('bookings.id', $bookingIdBinary)
                ->where('carts.customer_user_id', $userIdBinary)
                ->first(['bookings.id']);

            if ($booking === null) {
                return $this->notFound('Booking not found.');
            }

            // Resolves the currently-active quote when one exists, else the
            // most recently created one - a repeat decline call must still
            // find its own already-DECLINED quote (which no longer has
            // closed_at NULL) to answer idempotently, rather than a bare
            // `whereNull('closed_at')` filter making the repeat call 404.
            $quote = DB::table('booking_item_repair_quotes')
                ->where('booking_id', $bookingIdBinary)
                ->orderByRaw('closed_at IS NULL DESC')
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if ($quote === null) {
                return $this->notFound('No actionable repair quote for this Booking.');
            }

            $statusCode = BookingItemRepairQuoteStatuses::code((int) $quote->status_id);

            if ($statusCode === 'DECLINED') {
                return $this->ok(200, 'Repair quote already declined.', ['quote' => CustomerRepairQuotePresenter::present($quote)]);
            }

            if ($statusCode !== 'SENT') {
                return $this->unprocessable('Only a SENT repair quote may be declined.');
            }

            $timestamp = now()->format('Y-m-d H:i:s.u');

            DB::table('booking_item_repair_quotes')->where('id', $quote->id)->update([
                'status_id' => BookingItemRepairQuoteStatuses::id('DECLINED'),
                'declined_at' => $timestamp,
                'closed_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $fresh = DB::table('booking_item_repair_quotes')->where('id', $quote->id)->first();

            return $this->ok(200, 'Repair quote declined.', ['quote' => CustomerRepairQuotePresenter::present($fresh)]);
        });
    }
}
