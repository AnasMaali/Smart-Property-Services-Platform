<?php

namespace App\Support\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B23-ext. The one place Cart mutation Actions check a
 * Service's `min_quantity`/`max_quantity` catalog policy - never
 * duplicated inline. `cart_items.quantity` remains "how many whole
 * instances of this Service item" (see AddCartItemAction/
 * UpdateCartItemAction's docblocks); internal per-service counters (hours,
 * AC units, ducts, coils, rooms, ...) are always represented as NUMBER
 * Service Options priced via ADD_PER_UNIT, never by overloading this
 * bound. A single-quantity package (e.g. Studio Painting, an oil-change
 * package) sets min=max=1.
 */
final class ServiceQuantityPolicy
{
    /**
     * @return array{min: int, max: int}
     */
    public static function forService(string $serviceIdBinary): array
    {
        $row = DB::table('services')->where('id', $serviceIdBinary)->first(['min_quantity', 'max_quantity']);

        if ($row === null) {
            return ['min' => 1, 'max' => 1000];
        }

        return ['min' => (int) $row->min_quantity, 'max' => (int) $row->max_quantity];
    }

    /**
     * @return ?string Null when in range, otherwise a customer-facing error message.
     */
    public static function violation(int $quantity, int $min, int $max): ?string
    {
        if ($quantity < $min || $quantity > $max) {
            return $min === $max
                ? "This service only allows a quantity of {$min}."
                : "This service allows a quantity between {$min} and {$max}.";
        }

        return null;
    }
}
