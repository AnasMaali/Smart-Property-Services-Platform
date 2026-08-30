<?php

namespace App\Actions\Booking;

use App\Support\Booking\BookingItemRepairQuoteStatuses;
use App\Support\Booking\CustomerRepairQuotePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * BLUE V1 Phase B25 - customer acceptance of a SENT repair quote (POST
 * /v1/bookings/{booking}/quote/accept). Ownership-scoped identically to
 * App\Actions\Booking\GetBookingAction (booking -> cart -> customer_user_id
 * - a foreign/unknown Booking is always 404, never 403). Only a SENT quote
 * may be accepted; a repeated accept on an already-ACCEPTED quote is a
 * safe, deterministic 200 no-op rather than an error (BLUE V1 catalog spec
 * Phase B25 section 18) - never re-derives credit/balance a second time.
 */
final class AcceptRepairQuoteAction
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
            // most recently created one - mirrors App\Actions\Booking\
            // DeclineRepairQuoteAction's own resolution exactly, so e.g.
            // accepting an already-DECLINED (closed) quote is answered with
            // a deterministic "wrong state" rejection below rather than a
            // bare `whereNull('closed_at')` filter making it 404 instead.
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

            if ($statusCode === 'ACCEPTED') {
                return $this->ok(200, 'Repair quote already accepted.', ['quote' => CustomerRepairQuotePresenter::present($quote)]);
            }

            if ($statusCode !== 'SENT') {
                return $this->unprocessable('Only a SENT repair quote may be accepted.');
            }

            $timestamp = now()->format('Y-m-d H:i:s.u');

            DB::table('booking_item_repair_quotes')->where('id', $quote->id)->update([
                'status_id' => BookingItemRepairQuoteStatuses::id('ACCEPTED'),
                'accepted_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $fresh = DB::table('booking_item_repair_quotes')->where('id', $quote->id)->first();

            return $this->ok(200, 'Repair quote accepted.', ['quote' => CustomerRepairQuotePresenter::present($fresh)]);
        });
    }
}
