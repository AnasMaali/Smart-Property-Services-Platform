<?php

namespace App\Support\Booking;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resolves booking_sources.id by code instead of hardcoding numeric lookup
 * ids anywhere in Booking Actions - mirrors App\Support\Booking\
 * BookingStatuses exactly. Only two codes are ever seeded (BLUE V1 Phase
 * 10F): STANDARD (a Booking created from a successful customer Payment) and
 * CONTRACT (a Booking created by consuming an active Service Contract
 * entitlement, with no new customer Payment) - see
 * database/blue_v1_seed.sql "30. BOOKING SOURCES".
 */
final class BookingSources
{
    public static function id(string $code): int
    {
        $id = DB::table('booking_sources')->where('code', $code)->where('is_active', 1)->value('id');

        if ($id === null) {
            throw new RuntimeException("Missing required reference row: booking_sources.code = {$code}");
        }

        return (int) $id;
    }

    public static function code(int $id): string
    {
        $code = DB::table('booking_sources')->where('id', $id)->value('code');

        if ($code === null) {
            throw new RuntimeException("Missing required reference row: booking_sources.id = {$id}");
        }

        return $code;
    }
}
