<?php

namespace App\Support\Booking;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resolves booking_item_repair_quote_statuses.id by code - mirrors
 * BookingItemStatuses/BookingStatuses exactly. BLUE V1 Phase B25.
 */
final class BookingItemRepairQuoteStatuses
{
    public static function id(string $code): int
    {
        $id = DB::table('booking_item_repair_quote_statuses')->where('code', $code)->where('is_active', 1)->value('id');

        if ($id === null) {
            throw new RuntimeException("Missing required reference row: booking_item_repair_quote_statuses.code = {$code}");
        }

        return (int) $id;
    }

    public static function code(int $id): string
    {
        $code = DB::table('booking_item_repair_quote_statuses')->where('id', $id)->value('code');

        if ($code === null) {
            throw new RuntimeException("Missing required reference row: booking_item_repair_quote_statuses.id = {$id}");
        }

        return $code;
    }
}
