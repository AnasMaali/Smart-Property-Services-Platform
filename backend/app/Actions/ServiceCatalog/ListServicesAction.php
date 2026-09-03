<?php

namespace App\Actions\ServiceCatalog;

use App\Support\Pricing\DefaultCurrency;
use App\Support\Pricing\PricingEngine;
use App\Support\Pricing\PricingStatus;
use App\Support\Pricing\ServiceCapabilities;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

class ListServicesAction
{
    public function __construct(
        private readonly PricingEngine $pricingEngine = new PricingEngine,
        private readonly ServiceCapabilities $capabilities = new ServiceCapabilities,
    ) {}

    /**
     * Flat, searchable list of active services for the mobile catalog.
     * Optional `$capabilityCode` (e.g. SUBSCRIPTION) restricts to services
     * that currently carry that active capability — used by the contract
     * request/approve pickers so ineligible services never appear.
     *
     * @return array{
     *     query: ?string,
     *     category: ?array{id: int, code: string, name: string, description: ?string},
     *     services: array<int, array<string, mixed>>,
     * }
     */
    public function handle(?string $query = null, ?int $categoryId = null, ?string $capabilityCode = null): array
    {
        $servicesQuery = DB::table('services')
            ->join('service_categories', 'service_categories.id', '=', 'services.category_id')
            ->where('services.is_active', 1)
            ->where('service_categories.is_active', 1)
            ->orderBy('service_categories.display_order')
            ->orderBy('services.display_order')
            ->select([
                'services.id',
                'services.code',
                'services.slug',
                'services.name',
                'services.short_description',
                'services.category_id',
                'service_categories.code as category_code',
                'service_categories.name as category_name',
                'service_categories.description as category_description',
            ]);

        if ($categoryId !== null) {
            $servicesQuery->where('services.category_id', $categoryId);
        }

        $normalizedCapability = $capabilityCode === null ? null : strtoupper(trim($capabilityCode));
        if ($normalizedCapability !== null && $normalizedCapability !== '') {
            $servicesQuery
                ->join('service_capabilities', 'service_capabilities.service_id', '=', 'services.id')
                ->join('service_capability_types', 'service_capability_types.id', '=', 'service_capabilities.capability_type_id')
                ->where('service_capability_types.code', $normalizedCapability)
                ->where('service_capability_types.is_active', 1);
        }

        $normalizedQuery = $query === null ? null : trim($query);
        if ($normalizedQuery !== null && $normalizedQuery !== '') {
            $like = '%'.$normalizedQuery.'%';
            $servicesQuery->where(function ($builder) use ($like) {
                $builder
                    ->where('services.name', 'like', $like)
                    ->orWhere('services.short_description', 'like', $like)
                    ->orWhere('services.code', 'like', $like)
                    ->orWhere('services.slug', 'like', $like);
            });
        }

        $services = $servicesQuery->get();

        $categoryPayload = null;
        if ($categoryId !== null) {
            $category = DB::table('service_categories')
                ->where('id', $categoryId)
                ->where('is_active', 1)
                ->first(['id', 'code', 'name', 'description']);

            if ($category === null) {
                return [
                    'query' => $normalizedQuery,
                    'category' => null,
                    'services' => [],
                ];
            }

            $categoryPayload = (array) $category;
        }

        if ($services->isEmpty()) {
            return [
                'query' => $normalizedQuery,
                'category' => $categoryPayload,
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

        $capabilitiesByServiceId = $this->capabilities->codesByServiceId($serviceIds);

        $currencyCode = DefaultCurrency::code();
        $currency = DB::table('currencies')->where('code', $currencyCode)->first(['code', 'symbol', 'minor_unit']);

        $serviceUuids = $services->map(fn ($service) => UuidBinary::toString($service->id))->all();
        $pricingPreviewsByServiceUuid = $this->pricingEngine->previewMany($serviceUuids, $currencyCode);

        $servicePayloads = $services
            ->map(function ($service) use ($primaryImagesByServiceId, $pricingPreviewsByServiceUuid, $currency, $capabilitiesByServiceId) {
                $key = bin2hex($service->id);
                $primaryImage = $primaryImagesByServiceId->get($key);
                $pricingPreview = $pricingPreviewsByServiceUuid[UuidBinary::toString($service->id)];

                return [
                    'uuid' => UuidBinary::toString($service->id),
                    'code' => $service->code,
                    'slug' => $service->slug,
                    'name' => $service->name,
                    'short_description' => $service->short_description,
                    'category' => [
                        'id' => (int) $service->category_id,
                        'code' => $service->category_code,
                        'name' => $service->category_name,
                    ],
                    'capabilities' => $capabilitiesByServiceId[$key] ?? [],
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
            'query' => $normalizedQuery,
            'category' => $categoryPayload,
            'services' => $servicePayloads,
        ];
    }
}
