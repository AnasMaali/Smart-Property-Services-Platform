<?php

namespace App\Support\Payment;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resolves payment_statuses.id by code instead of hardcoding numeric
 * lookup ids anywhere in Payment Actions - mirrors App\Support\Cart\
 * CartStatuses exactly. Only the five actual seeded codes (PENDING,
 * SUCCESSFUL, FAILED, CANCELLED, REFUNDED) ever exist - see
 * database/blue_v1_seed.sql.
 */
final class PaymentStatuses
{
    public static function id(string $code): int
    {
        $id = DB::table('payment_statuses')->where('code', $code)->where('is_active', 1)->value('id');

        if ($id === null) {
            throw new RuntimeException("Missing required reference row: payment_statuses.code = {$code}");
        }

        return (int) $id;
    }
}
