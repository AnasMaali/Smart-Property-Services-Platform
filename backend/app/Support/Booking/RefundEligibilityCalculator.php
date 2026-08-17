<?php

namespace App\Support\Booking;

use Carbon\CarbonImmutable;

/**
 * The one implementation of BLUE V1's manual-refund cancellation policy.
 * Called exactly once per Booking, by App\Actions\Booking\CancelBookingAction,
 * at the moment of the Booking's FIRST real cancellation - never on an
 * idempotent retry, and never by any Booking read presenter. Never calls
 * Stripe, never changes `payment_attempts`, and never persists anything
 * itself - purely a function of (appointment start, cancellation time, paid
 * amount, current `config('cancellation.*')`); the caller is responsible for
 * persisting the result as a historical snapshot
 * (`bookings.cancellation_refund_percentage` / `cancellation_refund_amount`)
 * so a later change to the policy config can never retroactively change what
 * an already-cancelled Booking is shown to owe. `App\Support\Booking\
 * BookingPresenter` and `App\Support\Admin\AdminBookingPresenter` read that
 * persisted snapshot directly and never call this calculator.
 *
 * Refund policy is based on the BUSINESS-LOCAL calendar day, not on "24
 * hours before appointment": before the calendar day of the appointment,
 * 100% is due; from 00:00 on the appointment day onward, 75% is due.
 * Appointment/cancellation timestamps are stored under the application's
 * configured storage timezone, then converted to the configured business
 * timezone before comparing calendar dates.
 */
final class RefundEligibilityCalculator
{
    /**
     * @return array{percentage: int, amount: string, execution: 'MANUAL'}
     */
    public static function calculate(string $appointmentStartsAt, string $cancelledAt, string $paidAmount): array
    {
        $businessTimezone = (string) config('cancellation.timezone', 'UTC');
        $storageTimezone = (string) config('app.timezone', 'UTC');

        $appointmentLocal = CarbonImmutable::parse($appointmentStartsAt, $storageTimezone)->setTimezone($businessTimezone);
        $cancelledLocal = CarbonImmutable::parse($cancelledAt, $storageTimezone)->setTimezone($businessTimezone);

        $appointmentDayStartsAt = $appointmentLocal->startOfDay();

        $percentage = $cancelledLocal->lt($appointmentDayStartsAt)
            ? (int) config('cancellation.before_appointment_day_percentage', 100)
            : (int) config('cancellation.appointment_day_percentage', 75);

        $refundAmount = bcdiv(
            bcmul($paidAmount, (string) $percentage, 6),
            '100',
            6
        );

        return [
            'percentage' => $percentage,
            'amount' => $refundAmount,
            'execution' => 'MANUAL',
        ];
    }
}
