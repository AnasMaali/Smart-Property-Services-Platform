<?php

namespace App\Support\Booking;

use App\Support\Payment\Gateway\MinorUnitConverter;
use Carbon\CarbonImmutable;

/**
 * The one implementation of BLUE V1's Booking cancellation/refund policy
 * (BLUE V1 Phase B20 - Automated Booking Refunds via Stripe). Called
 * exactly once per Booking, by App\Actions\Booking\CancelBookingAction, at
 * the moment of the Booking's FIRST real cancellation - never on an
 * idempotent retry, and never by any Booking read presenter. Never calls
 * Stripe, never changes `payment_attempts`, and never persists anything
 * itself - purely a function of (appointment start, cancellation time, paid
 * amount, current `config('cancellation.*')`); the caller is responsible
 * for persisting the result as a historical snapshot
 * (`bookings.cancellation_refund_percentage` / `cancellation_refund_amount`)
 * so a later change to the policy config can never retroactively change
 * what an already-cancelled Booking is shown to owe. `App\Support\Booking\
 * BookingPresenter` and `App\Support\Admin\AdminBookingPresenter` read that
 * persisted snapshot directly and never call this calculator.
 *
 * Also the single source of truth for whether a Booking is cancellable at
 * all - CancelBookingAction (customer and Admin alike, via the same shared
 * Action) must reject cancellation at or after `appointmentStartsAt`
 * without applying either percentage, per BLUE V1's cancellation policy:
 * a Booking can never be cancelled once its appointment has started. This
 * is a plain absolute-instant comparison ($cancelledAt >= $appointmentStartsAt)
 * and deliberately does NOT go through the business timezone conversion
 * below - two real timestamps either have or have not crossed each other,
 * independent of which calendar day either one displays as.
 *
 * The percentage split for a still-cancellable Booking is based on the
 * BUSINESS-LOCAL calendar day, not on "24 hours before appointment":
 * before the calendar day of the appointment, 100% is due; from 00:00 on
 * the appointment day onward (but still before the appointment starts),
 * 75% is due. Appointment/cancellation timestamps are stored under the
 * application's configured storage timezone (`config('app.timezone')`),
 * then converted to the configured business timezone
 * (`config('cancellation.timezone')`) only for this calendar-day
 * comparison.
 *
 * BLUE V1 Phase B20 fix - $currencyMinorUnit (currencies.minor_unit,
 * e.g. 2 for AED) is required so the returned `amount` is normalized -
 * via App\Support\Payment\Gateway\MinorUnitConverter::roundToMinorUnit(),
 * standard monetary half-up rounding, never truncation - to the
 * currency's own precision BEFORE it is ever persisted. Without this, a
 * 75% split (e.g. AED 99.99 x 75% = AED 74.9925) would persist a
 * sub-minor-unit amount that does not match the integer amount
 * StripePaymentGateway::refundPayment() actually sends Stripe - the
 * persisted refund obligation and the real-world Stripe refund must
 * always represent the exact same final monetary amount.
 */
final class RefundEligibilityCalculator
{
    /**
     * @return array{cancellable: bool, percentage: ?int, amount: ?string, reason_code: string}
     */
    public static function evaluate(string $appointmentStartsAt, string $cancelledAt, string $paidAmount, int $currencyMinorUnit): array
    {
        $businessTimezone = (string) config('cancellation.timezone', 'UTC');
        $storageTimezone = (string) config('app.timezone', 'UTC');

        $appointmentStorage = CarbonImmutable::parse($appointmentStartsAt, $storageTimezone);
        $cancelledStorage = CarbonImmutable::parse($cancelledAt, $storageTimezone);

        if ($cancelledStorage->gte($appointmentStorage)) {
            return [
                'cancellable' => false,
                'percentage' => null,
                'amount' => null,
                'reason_code' => 'APPOINTMENT_ALREADY_STARTED',
            ];
        }

        $appointmentLocal = $appointmentStorage->setTimezone($businessTimezone);
        $cancelledLocal = $cancelledStorage->setTimezone($businessTimezone);
        $appointmentDayStartsAt = $appointmentLocal->startOfDay();

        $beforeAppointmentDay = $cancelledLocal->lt($appointmentDayStartsAt);

        $percentage = $beforeAppointmentDay
            ? (int) config('cancellation.before_appointment_day_percentage', 100)
            : (int) config('cancellation.appointment_day_percentage', 75);

        $rawRefundAmount = bcdiv(
            bcmul($paidAmount, (string) $percentage, 6),
            '100',
            6
        );

        // Normalize to the currency's own precision (half-up, never
        // truncated), then pad back out to decimal(19,6) - the same
        // 6-decimal string convention every other money field in this API
        // already uses (payment_attempts.confirmed_amount, etc.), so the
        // amount returned here is byte-for-byte identical whether it is
        // read fresh (this response) or read back later from
        // `bookings.cancellation_refund_amount` / `booking_refunds.
        // requested_amount` - never two different-looking numbers for the
        // exact same refund.
        $refundAmount = bcadd(
            MinorUnitConverter::roundToMinorUnit($rawRefundAmount, $currencyMinorUnit),
            '0',
            6
        );

        return [
            'cancellable' => true,
            'percentage' => $percentage,
            'amount' => $refundAmount,
            'reason_code' => $beforeAppointmentDay ? 'BEFORE_APPOINTMENT_DAY' : 'APPOINTMENT_DAY_BEFORE_START',
        ];
    }
}
