<?php

namespace App\Support\Booking;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resolves booking_item_statuses.id by code - mirrors BookingStatuses.
 * Phase 7A only ever writes PENDING_ASSIGNMENT (the initial status for every
 * Booking Item the moment it is created) - ASSIGNED/IN_PROGRESS/COMPLETED/
 * CANCELLED belong to later phases (technician assignment, fulfillment,
 * admin management).
 */
final class BookingItemStatuses
{
    public static function id(string $code): int
    {
        $id = DB::table('booking_item_statuses')->where('code', $code)->where('is_active', 1)->value('id');

        if ($id === null) {
            throw new RuntimeException("Missing required reference row: booking_item_statuses.code = {$code}");
        }

        return (int) $id;
    }

    public static function code(int $id): string
    {
        $code = DB::table('booking_item_statuses')->where('id', $id)->value('code');

        if ($code === null) {
            throw new RuntimeException("Missing required reference row: booking_item_statuses.id = {$id}");
        }

        return $code;
    }
}
