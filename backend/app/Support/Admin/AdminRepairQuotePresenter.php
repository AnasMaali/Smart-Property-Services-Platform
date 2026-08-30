<?php

namespace App\Support\Admin;

use App\Support\Booking\BookingItemRepairQuoteStatuses;
use App\Support\Booking\RepairQuoteFundingStatus;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B25 - the Admin-facing `booking_item_repair_quotes` JSON
 * shape, embedded inside App\Support\Admin\AdminBookingPresenter's own
 * Booking Item payload. Unlike the customer-facing presenter (see
 * App\Support\Booking\CustomerRepairQuotePresenter), this one may expose
 * the creating Admin's UUID and every historical revision link - it is
 * never returned from a customer-facing route.
 */
final class AdminRepairQuotePresenter
{
    /**
     * @return array<string, mixed>|null
     */
    public static function forBookingItem(string $bookingItemIdBinary): ?array
    {
        $quote = DB::table('booking_item_repair_quotes')
            ->where('booking_item_id', $bookingItemIdBinary)
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
        $credit = DB::table('repair_quote_credits')->where('quote_id', $quote->id)->first(['source_booking_item_id', 'source_payment_attempt_id', 'amount']);
        $supersededBy = DB::table('booking_item_repair_quotes')->where('supersedes_quote_id', $quote->id)->value('id');

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
            'credit_amount' => (string) $quote->credit_amount,
            'balance_due_amount' => (string) $quote->balance_due_amount,
            'inspection_credit_source' => $credit === null ? null : [
                'source_payment_attempt_uuid' => UuidBinary::toString($credit->source_payment_attempt_id),
            ],
            'created_by_admin_user_uuid' => UuidBinary::toString($quote->created_by_admin_user_id),
            'supersedes_quote_uuid' => $quote->supersedes_quote_id === null ? null : UuidBinary::toString($quote->supersedes_quote_id),
            'superseded_by_quote_uuid' => $supersededBy === null ? null : UuidBinary::toString($supersededBy),
            'created_at' => Carbon::parse($quote->created_at)->toIso8601String(),
            'sent_at' => $quote->sent_at === null ? null : Carbon::parse($quote->sent_at)->toIso8601String(),
            'accepted_at' => $quote->accepted_at === null ? null : Carbon::parse($quote->accepted_at)->toIso8601String(),
            'declined_at' => $quote->declined_at === null ? null : Carbon::parse($quote->declined_at)->toIso8601String(),
            'expired_at' => $quote->expired_at === null ? null : Carbon::parse($quote->expired_at)->toIso8601String(),
            'cancelled_at' => $quote->cancelled_at === null ? null : Carbon::parse($quote->cancelled_at)->toIso8601String(),
        ];
    }
}
