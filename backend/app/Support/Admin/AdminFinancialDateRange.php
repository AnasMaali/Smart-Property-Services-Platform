<?php

namespace App\Support\Admin;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Resolves the Admin Financial Dashboard/Ledger's `range` + `from`/`to`
 * request filters into a half-open UTC instant window
 * `[from, to)` safe to bind directly into a `WHERE occurred_at >= from AND
 * occurred_at < to` query. `bookings`/`payment_attempts`/etc. all store
 * timestamps under `config('app.timezone')` (UTC), but a UAE Admin's
 * notion of "Today"/"This Month" is a UAE calendar day
 * (`config('finance.timezone')`, Asia/Dubai by default) - the same
 * storage-vs-business-timezone split already established by
 * App\Support\Booking\RefundEligibilityCalculator for the Booking
 * cancellation policy. The half-open (exclusive upper bound) convention
 * avoids any end-of-day microsecond rounding ambiguity.
 */
final class AdminFinancialDateRange
{
    public const PRESETS = ['TODAY', 'LAST_7_DAYS', 'THIS_MONTH', 'CUSTOM'];

    /**
     * @return array{preset: string, from: Carbon, to: Carbon}
     */
    public static function resolve(string $preset, ?string $from, ?string $to): array
    {
        $timezone = (string) config('finance.timezone', 'Asia/Dubai');
        $businessNow = Carbon::now($timezone);

        return match ($preset) {
            'TODAY' => self::window($businessNow->copy()->startOfDay(), $businessNow->copy()->startOfDay()->addDay(), 'TODAY'),
            'LAST_7_DAYS' => self::window($businessNow->copy()->startOfDay()->subDays(6), $businessNow->copy()->startOfDay()->addDay(), 'LAST_7_DAYS'),
            'THIS_MONTH' => self::window($businessNow->copy()->startOfMonth(), $businessNow->copy()->startOfMonth()->addMonthNoOverflow(), 'THIS_MONTH'),
            'CUSTOM' => self::customWindow($from, $to, $timezone),
            default => throw new InvalidArgumentException("Unknown financial date range preset: {$preset}"),
        };
    }

    /**
     * @return array{preset: string, from: Carbon, to: Carbon}
     */
    private static function customWindow(?string $from, ?string $to, string $timezone): array
    {
        if ($from === null || $to === null) {
            throw new InvalidArgumentException('The from/to dates are required for a CUSTOM range.');
        }

        $fromDay = Carbon::createFromFormat('Y-m-d', $from, $timezone)?->startOfDay();
        $toDay = Carbon::createFromFormat('Y-m-d', $to, $timezone)?->startOfDay();

        if ($fromDay === null || $toDay === null) {
            throw new InvalidArgumentException('The from/to dates must be Y-m-d.');
        }

        if ($fromDay->greaterThan($toDay)) {
            throw new InvalidArgumentException('The from date must not be after the to date.');
        }

        return self::window($fromDay, $toDay->addDay(), 'CUSTOM');
    }

    /**
     * @return array{preset: string, from: Carbon, to: Carbon}
     */
    private static function window(Carbon $businessFrom, Carbon $businessTo, string $preset): array
    {
        return [
            'preset' => $preset,
            'from' => $businessFrom->clone()->setTimezone('UTC'),
            'to' => $businessTo->clone()->setTimezone('UTC'),
        ];
    }
}
