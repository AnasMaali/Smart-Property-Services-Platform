<?php

namespace App\Support\Booking;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resolves booking_refund_statuses.id by code instead of hardcoding
 * numeric lookup ids anywhere in Booking/Payment Actions - mirrors
 * App\Support\Payment\PaymentStatuses exactly. Only the four seeded codes
 * ever exist - see database/phase19_booking_refund_automation_migration.sql:
 *
 * - PENDING: not yet resolved - safe and required to retry
 *   (App\Console\Commands\ExecutePendingBookingRefunds selects only this).
 * - SUCCEEDED: Stripe confirmed the refund.
 * - FAILED: Stripe definitively rejected the refund request itself -
 *   terminal, not retryable.
 * - RECONCILIATION_REQUIRED (fix phase 2): an authoritative 'succeeded'
 *   webhook's own reported amount/currency did not match the persisted
 *   obligation (BLUE V1 is AED-only) - terminal, never auto-resolved, and
 *   distinct from FAILED so an Admin can tell "Stripe rejected this" apart
 *   from "Stripe did something we did not expect and a human must check."
 */
final class BookingRefundStatuses
{
    public static function id(string $code): int
    {
        $id = DB::table('booking_refund_statuses')->where('code', $code)->where('is_active', 1)->value('id');

        if ($id === null) {
            throw new RuntimeException("Missing required reference row: booking_refund_statuses.code = {$code}");
        }

        return (int) $id;
    }

    public static function code(int $id): string
    {
        $code = DB::table('booking_refund_statuses')->where('id', $id)->value('code');

        if ($code === null) {
            throw new RuntimeException("Missing required reference row: booking_refund_statuses.id = {$id}");
        }

        return (string) $code;
    }
}
