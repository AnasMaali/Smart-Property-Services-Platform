<?php

namespace App\Support\Booking;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resolves booking_statuses.id by code instead of hardcoding numeric lookup
 * ids anywhere in Booking Actions - mirrors App\Support\Cart\CartStatuses /
 * App\Support\Payment\PaymentStatuses exactly. Only the five actual seeded
 * codes (PAID, ASSIGNED, IN_PROGRESS, COMPLETED, CANCELLED) ever exist - see
 * database/blue_v1_seed.sql. Phase 7A only ever writes PAID (the initial
 * status the moment a Booking is created from a successful payment) -
 * ASSIGNED/IN_PROGRESS/COMPLETED/CANCELLED belong to later phases
 * (technician assignment, fulfillment, admin management).
 */
final class BookingStatuses
{
    public static function id(string $code): int
    {
        $id = DB::table('booking_statuses')->where('code', $code)->where('is_active', 1)->value('id');

        if ($id === null) {
            throw new RuntimeException("Missing required reference row: booking_statuses.code = {$code}");
        }

        return (int) $id;
    }

    public static function code(int $id): string
    {
        $code = DB::table('booking_statuses')->where('id', $id)->value('code');

        if ($code === null) {
            throw new RuntimeException("Missing required reference row: booking_statuses.id = {$id}");
        }

        return $code;
    }
}
