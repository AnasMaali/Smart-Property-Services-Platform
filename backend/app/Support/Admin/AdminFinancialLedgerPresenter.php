<?php

namespace App\Support\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Admin Financial Ledger row shape (see App\Actions\Admin\Financial\
 * AdminListFinancialLedgerAction). Batch-loaded exactly like
 * App\Support\Admin\AdminPaymentPresenter::presentList() - never a query
 * per row. Deliberately exposes only a safe identifier
 * (`entry_reference`, the source row's own UUID - never which raw table
 * it came from) plus a normalized event/booking/customer summary - never a
 * raw binary id, provider credential, or card detail.
 */
final class AdminFinancialLedgerPresenter
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function presentList(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $currencyIds = $rows->pluck('currency_id')->unique()->values()->all();
        $bookingIds = $rows->pluck('booking_id')->filter()->unique()->values()->all();

        $currencies = DB::table('currencies')
            ->whereIn('id', $currencyIds)
            ->get(['id', 'code', 'symbol', 'minor_unit'])
            ->keyBy(fn ($row) => $row->id);

        $bookings = $bookingIds === [] ? collect() : DB::table('bookings')
            ->join('carts', 'carts.id', '=', 'bookings.cart_id')
            ->whereIn('bookings.id', $bookingIds)
            ->get(['bookings.id', 'bookings.booking_number', 'carts.customer_user_id'])
            ->keyBy(fn ($row) => $row->id);

        $customerIds = $bookings->pluck('customer_user_id')->unique()->values()->all();

        $customers = $customerIds === [] ? collect() : DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->whereIn('users.id', $customerIds)
            ->get(['users.id', 'users.phone_number', 'user_profiles.full_name'])
            ->keyBy(fn ($row) => $row->id);

        return $rows->map(function (object $row) use ($currencies, $bookings, $customers): array {
            $currency = $currencies->get($row->currency_id);
            $booking = $row->booking_id === null ? null : $bookings->get($row->booking_id);
            $customer = $booking === null ? null : $customers->get($booking->customer_user_id);

            return [
                'entry_reference' => UuidBinary::toString($row->reference_id),
                'event_type' => $row->event_type,
                'direction' => $row->direction,
                'status' => $row->status,
                'amount' => $row->amount,
                'currency' => $currency === null ? null : [
                    'code' => $currency->code,
                    'symbol' => $currency->symbol,
                    'decimal_places' => (int) $currency->minor_unit,
                ],
                'payment_method' => $row->payment_method,
                'occurred_at' => Carbon::parse($row->occurred_at)->toIso8601String(),
                'booking' => $booking === null ? null : [
                    'uuid' => UuidBinary::toString($booking->id),
                    'booking_number' => $booking->booking_number,
                ],
                'customer' => $customer === null ? null : [
                    'full_name' => $customer->full_name,
                    'phone_number' => $customer->phone_number,
                ],
            ];
        })->all();
    }
}
