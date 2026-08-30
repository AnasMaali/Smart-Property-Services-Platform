<?php

namespace App\Support\Booking;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B25 - the customer-safe `booking_item_repair_quotes` JSON
 * shape (see App\Support\Admin\AdminRepairQuotePresenter for the Admin
 * equivalent). Never exposes an Admin user UUID, a raw payment-provider
 * reference, an internal `repair_quote_credits`/`repair_quote_payment_
 * attempts` row id, or any other internal DB id - only what BLUE V1
 * catalog spec Phase B25 section 17 documents as safe.
 */
final class CustomerRepairQuotePresenter
{
    /**
     * The most relevant quote for a Booking: the currently-active
     * (non-terminal) one if it exists, else the most recently created one
     * (so a customer can still see WHY their booking has no actionable
     * quote right now - e.g. a declined/expired quote - rather than
     * silently returning null forever).
     *
     * @return array<string, mixed>|null
     */
    public static function forBooking(string $bookingIdBinary): ?array
    {
        $quote = DB::table('booking_item_repair_quotes')
            ->where('booking_id', $bookingIdBinary)
            ->orderByRaw('closed_at IS NULL DESC')
            ->orderByDesc('created_at')
            ->first();

        return $quote === null ? null : self::present($quote);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(object $quote): array
    {
        $statusCode = BookingItemRepairQuoteStatuses::code((int) $quote->status_id);
        $currency = DB::table('currencies')->where('id', $quote->currency_id)->first(['code', 'symbol', 'minor_unit']);

        return [
            'uuid' => UuidBinary::toString($quote->id),
            'status' => $statusCode,
            'funding_status' => RepairQuoteFundingStatus::for($quote),
            'currency' => $currency === null ? null : [
                'code' => $currency->code,
                'symbol' => $currency->symbol,
                'decimal_places' => (int) $currency->minor_unit,
            ],
            'quoted_amount' => (string) $quote->quoted_amount,
            'inspection_credit' => (string) $quote->credit_amount,
            'balance_due' => (string) $quote->balance_due_amount,
            'sent_at' => $quote->sent_at === null ? null : Carbon::parse($quote->sent_at)->toIso8601String(),
            'accepted_at' => $quote->accepted_at === null ? null : Carbon::parse($quote->accepted_at)->toIso8601String(),
            'declined_at' => $quote->declined_at === null ? null : Carbon::parse($quote->declined_at)->toIso8601String(),
            'expired_at' => $quote->expired_at === null ? null : Carbon::parse($quote->expired_at)->toIso8601String(),
        ];
    }
}
