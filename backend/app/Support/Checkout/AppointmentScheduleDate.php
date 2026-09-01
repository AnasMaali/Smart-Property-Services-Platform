<?php

namespace App\Support\Checkout;

use Illuminate\Support\Carbon;

/**
 * BLUE V1 Phase B27 (Appointment Schedule Management). Resolves a
 * `Y-m-d` calendar-date string into a half-open UTC instant window
 * `[from, to)`, using `config('checkout.timezone')` (Asia/Dubai by
 * default) as the business calendar - the same storage-vs-business-
 * timezone split already established by App\Support\Admin\
 * AdminFinancialDateRange (Finance) and App\Support\Booking\
 * RefundEligibilityCalculator (cancellation policy). `appointment_slots`
 * always stores `starts_at`/`ends_at` under `config('app.timezone')`
 * (UTC); only the CALENDAR DAY a customer/Admin means by "2026-09-05" is
 * ever interpreted in Dubai time.
 *
 * Used by the customer `?date=` filter (App\Actions\Checkout\
 * GetAppointmentSlotsAction) and the Admin Appointment Schedule day
 * view/generator (App\Actions\Admin\AppointmentSchedule\*) - never a
 * second, divergent date-parsing implementation.
 */
final class AppointmentScheduleDate
{
    public static function timezone(): string
    {
        return (string) config('checkout.timezone', 'Asia/Dubai');
    }

    /**
     * Strictly validates `$date` is a real `Y-m-d` calendar date (rejects
     * malformed input and out-of-range values like `2026-02-30`, which
     * `Carbon::createFromFormat()` alone would silently roll over rather
     * than reject) and returns its Dubai-midnight-to-next-Dubai-midnight
     * window, already converted to UTC instants.
     *
     * @return array{from: Carbon, to: Carbon}|null null when `$date` is not a valid `Y-m-d` calendar date
     */
    public static function utcDayRange(string $date): ?array
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
            return null;
        }

        [, $year, $month, $day] = $matches;

        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        $timezone = self::timezone();
        $from = Carbon::createFromFormat('Y-m-d H:i:s', "{$date} 00:00:00", $timezone);

        if ($from === false) {
            return null;
        }

        $to = $from->copy()->addDay();

        return [
            'from' => $from->clone()->setTimezone('UTC'),
            'to' => $to->clone()->setTimezone('UTC'),
        ];
    }
}
