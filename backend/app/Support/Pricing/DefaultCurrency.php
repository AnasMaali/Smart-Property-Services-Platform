<?php

namespace App\Support\Pricing;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resolves the platform's default currency for public catalog screens that
 * have no customer-selected currency yet (Service Details preview, category
 * listings). V1 seeds exactly one active currency (AED); this reads that
 * from data instead of hardcoding a currency code in Action classes, so a
 * future multi-currency V2 only needs a real currency-selection mechanism,
 * not a code change here.
 */
final class DefaultCurrency
{
    public static function code(): string
    {
        $code = DB::table('currencies')->where('is_active', 1)->orderBy('id')->value('code');

        if ($code === null) {
            throw new RuntimeException('No active currency configured.');
        }

        return $code;
    }
}
