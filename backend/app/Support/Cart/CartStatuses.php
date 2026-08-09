<?php

namespace App\Support\Cart;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resolves cart_statuses.id by code instead of hardcoding numeric lookup
 * ids anywhere in cart Actions.
 */
final class CartStatuses
{
    public static function id(string $code): int
    {
        $id = DB::table('cart_statuses')->where('code', $code)->where('is_active', 1)->value('id');

        if ($id === null) {
            throw new RuntimeException("Missing required reference row: cart_statuses.code = {$code}");
        }

        return (int) $id;
    }
}
