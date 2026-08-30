<?php

namespace App\Support\Booking;

use App\Support\Payment\PaymentStatuses;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B25 - the ONE place the historical inspection credit for a
 * Booking Item is ever computed. Never reads services.original_price, never
 * calls PricingEngine, never re-derives from any LIVE Service price -
 * every value comes from `booking_items.line_total_amount` (an immutable
 * snapshot column, only ever written once at Booking-creation time - see
 * App\Support\Booking\BookingSnapshotConverter) and the confirmed online
 * PaymentAttempt that funded it.
 *
 * Multi-item-Booking safety: `App\Actions\Booking\
 * CreateBookingFromSuccessfulPaymentAction::buildBookingItems()` never
 * creates a Booking at all unless the sum of every `booking_items.
 * line_total_amount` in it exactly equals the funding PaymentAttempt's
 * `confirmed_amount` (its own AMOUNT_MISMATCH reconciliation guard - see
 * that Action's docblock). Because that invariant is already enforced at
 * Booking-creation time for every Booking that exists, crediting exactly
 * ONE item's own `line_total_amount` can never exceed what was actually,
 * truthfully collected for that Booking - regardless of how many other
 * items share the same Booking/payment. Nothing here needs (or assumes) a
 * single-item Booking.
 */
final class InspectionCreditCalculator
{
    /**
     * @return array{eligible: bool, reason: ?string, amount: ?string, booking_id: ?string, payment_attempt_id: ?string}
     */
    public static function eligibilityFor(object $bookingItem, object $booking): array
    {
        if ((int) $bookingItem->status_id !== BookingItemStatuses::id('COMPLETED') || $bookingItem->cancelled_at !== null) {
            return self::ineligible('The inspection Booking Item is not yet completed.');
        }

        if ($booking->payment_attempt_id === null) {
            return self::ineligible('The inspection Booking has no online payment to credit from.');
        }

        $payment = DB::table('payment_attempts')->where('id', $booking->payment_attempt_id)->first(['id', 'status_id', 'confirmed_amount', 'successful_at']);

        if ($payment === null
            || (int) $payment->status_id !== PaymentStatuses::id('SUCCESSFUL')
            || $payment->confirmed_amount === null
            || $payment->successful_at === null
        ) {
            return self::ineligible('The inspection Booking has no confirmed online payment to credit from.');
        }

        return [
            'eligible' => true,
            'reason' => null,
            'amount' => (string) $bookingItem->line_total_amount,
            'booking_id' => $booking->id,
            'payment_attempt_id' => $payment->id,
        ];
    }

    /**
     * @return array{eligible: bool, reason: string, amount: null, booking_id: null, payment_attempt_id: null}
     */
    private static function ineligible(string $reason): array
    {
        return ['eligible' => false, 'reason' => $reason, 'amount' => null, 'booking_id' => null, 'payment_attempt_id' => null];
    }
}
