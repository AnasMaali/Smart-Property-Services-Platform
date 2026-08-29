<?php

namespace App\Support\Notifications\Email;

use App\Support\Notifications\TechnicianJobNotificationContent;
use Illuminate\Support\Facades\DB;

/**
 * Assembles the exact, safe operational content of a Customer-facing
 * technician-assigned/technician-changed email from already-committed
 * Booking/Booking Item/Technician/Payment data - never anything
 * client-supplied. Reuses App\Support\Notifications\
 * TechnicianJobNotificationContent::forNewAssignment() for every field that
 * does not depend on WHO the recipient is (service/appointment/location/
 * customer name/technician name) rather than re-querying the same rows a
 * second time - only the two customer-specific additions
 * (paid_amount/currency/booking_status) are assembled here.
 *
 * `paid_amount` is deliberately read from `payment_attempts.confirmed_amount
 * ?? requested_amount` for the Booking's OWN `payment_attempt_id` - the
 * exact same authoritative historical snapshot
 * App\Support\Admin\AdminBookingPresenter::detail() already exposes to
 * Admin - never recomputed from the Service's current live price/pricing
 * scheme, which may have changed since this Booking was paid for. `null`
 * only for a contract-billed Booking (no `payment_attempt_id` at all - BLUE
 * V1 Phase 11 contract billing is a separate payment path outside this
 * phase's scope); the Mailable/view must render that as "covered by your
 * service contract," never as a fabricated amount.
 */
final class CustomerAssignmentEmailContent
{
    /**
     * BLUE V1 is AED-only (see database/README.md) - never computed from a
     * live currency lookup that could theoretically diverge.
     */
    private const CURRENCY = 'AED';

    /**
     * @return array<string, string|null>
     */
    public static function build(string $bookingItemIdBinary, string $technicianIdBinary): array
    {
        $fields = TechnicianJobNotificationContent::forNewAssignment($bookingItemIdBinary, $technicianIdBinary);

        $item = DB::table('booking_items')->where('id', $bookingItemIdBinary)->first(['booking_id']);

        $booking = DB::table('bookings')
            ->join('booking_statuses', 'booking_statuses.id', '=', 'bookings.status_id')
            ->where('bookings.id', $item->booking_id)
            ->first(['bookings.payment_attempt_id', 'booking_statuses.name as status_name']);

        $fields['paid_amount'] = self::paidAmount($booking->payment_attempt_id);
        $fields['currency'] = self::CURRENCY;
        $fields['booking_status'] = (string) $booking->status_name;
        $fields['address_summary'] = TechnicianJobNotificationContent::locationSummary($fields);

        return $fields;
    }

    private static function paidAmount(?string $paymentAttemptIdBinary): ?string
    {
        if ($paymentAttemptIdBinary === null) {
            return null;
        }

        $payment = DB::table('payment_attempts')
            ->where('id', $paymentAttemptIdBinary)
            ->first(['confirmed_amount', 'requested_amount']);

        if ($payment === null) {
            return null;
        }

        return (string) ($payment->confirmed_amount ?? $payment->requested_amount);
    }
}
