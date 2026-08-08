<?php

namespace App\Actions\ServiceCatalog;

use App\Support\ServiceCatalog\EffectivePricing;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

class ListCategoryServicesAction
{
    /**
     * Active services inside one active category, ordered by display_order,
     * each carrying its primary active image and currently effective base
     * price (if any) for the customer's category → services screen.
     *
     * Returns null when the category does not exist or is inactive, so the
     * controller can answer with a 404.
     *
     * @return ?array{
     *     category: array{id: int, code: string, name: string, description: ?string},
     *     services: array<int, array<string, mixed>>,
     * }
     */
    public function handle(int $categoryId): ?array
    {
        $category = DB::table('service_categories')
            ->where('id', $categoryId)
            ->where('is_active', 1)
            ->first(['id', 'code', 'name', 'description']);

        if ($category === null) {
            return null;
        }

        $services = DB::table('services')
            ->where('category_id', $categoryId)
            ->where('is_active', 1)
            ->orderBy('display_order')
            ->get(['id', 'code', 'slug', 'name', 'short_description']);

        if ($services->isEmpty()) {
            return [
                'category' => (array) $category,
                'services' => [],
            ];
        }

        $serviceIds = $services->pluck('id')->all();

        $primaryImagesByServiceId = DB::table('service_media')
            ->whereIn('service_id', $serviceIds)
            ->where('is_active', 1)
            ->where('is_primary', 1)
            ->get(['service_id', 'storage_key', 'mime_type', 'alt_text', 'caption', 'width_pixels', 'height_pixels'])
            ->keyBy(fn ($row) => bin2hex($row->service_id));

        $now = now();

        $currentPricesByServiceId = EffectivePricing::scope(
            DB::table('service_prices')
                ->join('currencies', 'currencies.id', '=', 'service_prices.currency_id')
                ->whereIn('service_prices.service_id', $serviceIds),
            'service_prices.effective_from',
            'service_prices.effective_to',
            $now,
        )
            ->select([
                'service_prices.service_id',
                'service_prices.base_amount',
                'currencies.code as currency_code',
                'currencies.symbol as currency_symbol',
                'currencies.minor_unit as currency_minor_unit',
            ])
            ->get()
            ->keyBy(fn ($row) => bin2hex($row->service_id));

        $servicePayloads = $services
            ->map(function ($service) use ($primaryImagesByServiceId, $currentPricesByServiceId) {
                $key = bin2hex($service->id);
                $primaryImage = $primaryImagesByServiceId->get($key);
                $currentPrice = $currentPricesByServiceId->get($key);

                return [
                    'uuid' => UuidBinary::toString($service->id),
                    'code' => $service->code,
                    'slug' => $service->slug,
                    'name' => $service->name,
                    'short_description' => $service->short_description,
                    'primary_image' => $primaryImage === null ? null : [
                        'storage_key' => $primaryImage->storage_key,
                        'mime_type' => $primaryImage->mime_type,
                        'alt_text' => $primaryImage->alt_text,
                        'caption' => $primaryImage->caption,
                        'width_pixels' => $primaryImage->width_pixels,
                        'height_pixels' => $primaryImage->height_pixels,
                    ],
                    'current_price' => $currentPrice === null ? null : [
                        'amount' => $currentPrice->base_amount,
                        'currency' => [
                            'code' => $currentPrice->currency_code,
                            'symbol' => $currentPrice->currency_symbol,
                            'minor_unit' => $currentPrice->currency_minor_unit,
                        ],
                    ],
                ];
            })
            ->values()
            ->all();

        return [
            'category' => (array) $category,
            'services' => $servicePayloads,
        ];
    }
}
