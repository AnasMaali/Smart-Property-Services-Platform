<?php

namespace App\Actions\ServiceCatalog;

use App\Support\Pricing\DefaultCurrency;
use App\Support\Pricing\PricingEngine;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GetServiceDetailsAction
{
    public function __construct(private readonly PricingEngine $pricingEngine = new PricingEngine) {}

    /**
     * Full service-details payload for the customer service-details screen:
     * identity, category summary, active media, generic option/input
     * metadata (for Flutter to render TEXT/NUMBER/BOOLEAN/SINGLE_SELECT/
     * MULTI_SELECT controls generically), and a pricing preview computed by
     * the flexible PricingEngine with no customer selections yet.
     *
     * The pricing preview is never authoritative - it is the same
     * PricingEngine that Cart/Checkout will call again once real selections
     * exist, so there is only ever one pricing calculation in the system.
     *
     * Returns null when the slug does not resolve to an active service, so
     * the controller can answer with a 404.
     *
     * @return ?array<string, mixed>
     */
    public function handle(string $slug): ?array
    {
        $service = DB::table('services')
            ->join('service_categories', 'service_categories.id', '=', 'services.category_id')
            ->where('services.slug', $slug)
            ->where('services.is_active', 1)
            ->select([
                'services.id',
                'services.code',
                'services.slug',
                'services.name',
                'services.short_description',
                'services.description',
                'service_categories.id as category_id',
                'service_categories.code as category_code',
                'service_categories.name as category_name',
                'service_categories.description as category_description',
            ])
            ->first();

        if ($service === null) {
            return null;
        }

        $now = now();

        $media = DB::table('service_media')
            ->where('service_id', $service->id)
            ->where('is_active', 1)
            ->orderBy('display_order')
            ->get(['id', 'storage_key', 'mime_type', 'alt_text', 'caption', 'width_pixels', 'height_pixels', 'is_primary'])
            ->map(fn ($item) => [
                'uuid' => UuidBinary::toString($item->id),
                'storage_key' => $item->storage_key,
                'mime_type' => $item->mime_type,
                'alt_text' => $item->alt_text,
                'caption' => $item->caption,
                'width_pixels' => $item->width_pixels,
                'height_pixels' => $item->height_pixels,
                'is_primary' => (bool) $item->is_primary,
            ])
            ->values()
            ->all();

        $currencyCode = DefaultCurrency::code();

        $pricingPreview = $this->pricingEngine->evaluate(
            serviceIdUuid: UuidBinary::toString($service->id),
            selections: [],
            quantity: 1,
            currencyCode: $currencyCode,
            context: [],
            at: $now,
        );

        return [
            'uuid' => UuidBinary::toString($service->id),
            'code' => $service->code,
            'slug' => $service->slug,
            'name' => $service->name,
            'short_description' => $service->short_description,
            'description' => $service->description,
            'category' => [
                'id' => $service->category_id,
                'code' => $service->category_code,
                'name' => $service->category_name,
                'description' => $service->category_description,
            ],
            'media' => $media,
            'pricing_preview' => $pricingPreview->toArray(),
            'options' => $this->loadOptions($service->id, $now),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadOptions(string $serviceIdBinary, Carbon $now): array
    {
        $options = DB::table('service_options')
            ->join('service_option_types', 'service_option_types.id', '=', 'service_options.option_type_id')
            ->where('service_options.service_id', $serviceIdBinary)
            ->where('service_options.is_active', 1)
            ->orderBy('service_options.display_order')
            ->select([
                'service_options.id',
                'service_options.code',
                'service_options.name',
                'service_options.description',
                'service_options.is_required',
                'service_option_types.code as type_code',
            ])
            ->get();

        if ($options->isEmpty()) {
            return [];
        }

        $optionIds = $options->pluck('id')->all();

        $numericRulesByOptionId = DB::table('service_option_numeric_rules')
            ->leftJoin('measurement_units', 'measurement_units.id', '=', 'service_option_numeric_rules.measurement_unit_id')
            ->whereIn('service_option_numeric_rules.service_option_id', $optionIds)
            ->select([
                'service_option_numeric_rules.service_option_id',
                'service_option_numeric_rules.minimum_value',
                'service_option_numeric_rules.maximum_value',
                'service_option_numeric_rules.step_value',
                'service_option_numeric_rules.default_value',
                'service_option_numeric_rules.decimal_places',
                'measurement_units.id as measurement_unit_id',
                'measurement_units.code as measurement_unit_code',
                'measurement_units.name as measurement_unit_name',
                'measurement_units.symbol as measurement_unit_symbol',
            ])
            ->get()
            ->keyBy(fn ($row) => bin2hex($row->service_option_id));

        $selectionRulesByOptionId = DB::table('service_option_selection_rules')
            ->whereIn('service_option_id', $optionIds)
            ->get(['service_option_id', 'minimum_selections', 'maximum_selections'])
            ->keyBy(fn ($row) => bin2hex($row->service_option_id));

        $choicesByOptionId = DB::table('service_option_choices')
            ->whereIn('service_option_id', $optionIds)
            ->where('is_active', 1)
            ->orderBy('display_order')
            ->get(['id', 'service_option_id', 'code', 'name', 'description'])
            ->groupBy(fn ($row) => bin2hex($row->service_option_id));

        return $options
            ->map(fn ($option) => $this->mapOption($option, $numericRulesByOptionId, $selectionRulesByOptionId, $choicesByOptionId))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapOption(
        object $option,
        Collection $numericRulesByOptionId,
        Collection $selectionRulesByOptionId,
        Collection $choicesByOptionId,
    ): array {
        $key = bin2hex($option->id);

        $payload = [
            'uuid' => UuidBinary::toString($option->id),
            'code' => $option->code,
            'name' => $option->name,
            'description' => $option->description,
            'type' => $option->type_code,
            'is_required' => (bool) $option->is_required,
        ];

        if ($option->type_code === 'NUMBER') {
            $rule = $numericRulesByOptionId->get($key);

            $payload['numeric_rule'] = $rule === null ? null : [
                'min_value' => $rule->minimum_value,
                'max_value' => $rule->maximum_value,
                'step_value' => $rule->step_value,
                'default_value' => $rule->default_value,
                'decimal_places' => (int) $rule->decimal_places,
                'measurement_unit' => $rule->measurement_unit_id === null ? null : [
                    'id' => $rule->measurement_unit_id,
                    'code' => $rule->measurement_unit_code,
                    'name' => $rule->measurement_unit_name,
                    'symbol' => $rule->measurement_unit_symbol,
                ],
            ];
        }

        if (in_array($option->type_code, ['SINGLE_SELECT', 'MULTI_SELECT'], true)) {
            $rule = $selectionRulesByOptionId->get($key);

            $payload['selection_rule'] = $rule === null ? null : [
                'minimum_selections' => (int) $rule->minimum_selections,
                'maximum_selections' => (int) $rule->maximum_selections,
            ];

            $payload['choices'] = ($choicesByOptionId->get($key) ?? collect())
                ->map(fn ($choice) => [
                    'uuid' => UuidBinary::toString($choice->id),
                    'code' => $choice->code,
                    'name' => $choice->name,
                    'description' => $choice->description,
                ])
                ->values()
                ->all();
        }

        return $payload;
    }
}
