<?php

namespace App\Support\ServiceCatalog;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Every BLUE pricing table (service_prices, service_option_choice_prices,
 * service_option_numeric_pricing_rules) versions rows by effective_from /
 * effective_to instead of an is_active flag. Exactly one row per
 * (entity, currency) is open-ended (effective_to IS NULL) at any time; this
 * applies the "currently effective" rule shared by all three tables.
 */
final class EffectivePricing
{
    public static function scope(Builder $query, string $fromColumn, string $toColumn, Carbon $now): Builder
    {
        return $query
            ->where($fromColumn, '<=', $now)
            ->where(function (Builder $q) use ($toColumn, $now) {
                $q->whereNull($toColumn)->orWhere($toColumn, '>', $now);
            });
    }
}
