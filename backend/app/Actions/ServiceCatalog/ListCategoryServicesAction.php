<?php

namespace App\Actions\ServiceCatalog;

use App\Support\Pricing\DefaultCurrency;
use App\Support\Pricing\PricingEngine;
use App\Support\Pricing\PricingStatus;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

class ListCategoryServicesAction
{
    public function __construct(private readonly PricingEngine $pricingEngine = new PricingEngine) {}

    /**
     * Active services inside one active category, ordered by display_order,
     * each carrying its primary active image and a generic pricing preview
     * (from the flexible pricing engine, evaluated with no selections) for
     * the customer's category → services screen.
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
            ->get(['id', 'code', 'slug', 'name', 'short_description', 'original_price', 'is_featured', 'estimated_duration_minutes', 'min_quantity', 'max_quantity']);

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

        $currencyCode = DefaultCurrency::code();
        $currency = DB::table('currencies')->where('code', $currencyCode)->first(['code', 'symbol', 'minor_unit']);

        $serviceUuids = $services->map(fn ($service) => UuidBinary::toString($service->id))->all();
        $pricingPreviewsByServiceUuid = $this->pricingEngine->previewMany($serviceUuids, $currencyCode);

        $servicePayloads = $services
            ->map(function ($service) use ($primaryImagesByServiceId, $pricingPreviewsByServiceUuid, $currency) {
                $key = bin2hex($service->id);
                $primaryImage = $primaryImagesByServiceId->get($key);
                $pricingPreview = $pricingPreviewsByServiceUuid[UuidBinary::toString($service->id)];
                $originalPrice = $service->original_price === null ? null : (string) $service->original_price;

                return [
                    'uuid' => UuidBinary::toString($service->id),
                    'code' => $service->code,
                    'slug' => $service->slug,
                    'name' => $service->name,
                    'short_description' => $service->short_description,
                    'is_featured' => (bool) $service->is_featured,
                    'estimated_duration_minutes' => $service->estimated_duration_minutes === null ? null : (int) $service->estimated_duration_minutes,
                    'quantity' => ['min' => (int) $service->min_quantity, 'max' => (int) $service->max_quantity],
                    // BLUE V1 Phase B23 - additive two-price display block,
                    // computed from the SAME batched previewMany() call
                    // already made above for `pricing_preview` - never a
                    // second pricing calculation. Matches the shape
                    // App\Actions\ServiceCatalog\GetServiceDetailsAction
                    // exposes on the Service-details screen.
                    'pricing' => [
                        'currency' => $currency->code,
                        'original_amount' => $originalPrice,
                        'current_amount' => $pricingPreview->unitTotal,
                        'has_discount' => $originalPrice !== null && $pricingPreview->unitTotal !== null
                            && bccomp($originalPrice, $pricingPreview->unitTotal, 6) > 0,
                    ],
                    'primary_image' => $primaryImage === null ? null : [
                        'storage_key' => $primaryImage->storage_key,
                        'mime_type' => $primaryImage->mime_type,
                        'alt_text' => $primaryImage->alt_text,
                        'caption' => $primaryImage->caption,
                        'width_pixels' => $primaryImage->width_pixels,
                        'height_pixels' => $primaryImage->height_pixels,
                    ],
                    'pricing_preview' => [
                        'pricing_status' => $pricingPreview->status->value,
                        'unit_total' => $pricingPreview->unitTotal,
                        'currency' => $pricingPreview->status === PricingStatus::UNAVAILABLE ? null : [
                            'code' => $currency->code,
                            'symbol' => $currency->symbol,
                            'minor_unit' => $currency->minor_unit,
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
