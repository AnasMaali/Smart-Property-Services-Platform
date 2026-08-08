<?php

namespace App\Actions\ServiceCatalog;

use App\Support\ServiceCatalog\EffectivePricing;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GetServiceDetailsAction
{
    /**
     * Full service-details payload for the customer service-details screen:
     * identity, category summary, active media, currently effective base
     * price, and every active service option with its currently effective
     * pricing rules and active choices.
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

        $basePrice = EffectivePricing::scope(
            DB::table('service_prices')
                ->join('currencies', 'currencies.id', '=', 'service_prices.currency_id')
                ->where('service_prices.service_id', $service->id),
            'service_prices.effective_from',
            'service_prices.effective_to',
            $now,
        )
            ->select([
                'service_prices.base_amount',
                'currencies.code as currency_code',
                'currencies.symbol as currency_symbol',
                'currencies.minor_unit as currency_minor_unit',
            ])
            ->first();

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
            'base_price' => $basePrice === null ? null : [
                'amount' => $basePrice->base_amount,
                'currency' => [
                    'code' => $basePrice->currency_code,
                    'symbol' => $basePrice->currency_symbol,
                    'minor_unit' => $basePrice->currency_minor_unit,
                ],
            ],
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

        $choiceIds = $choicesByOptionId->flatten(1)->pluck('id')->all();

        $choicePricesByChoiceId = empty($choiceIds) ? collect() : EffectivePricing::scope(
            DB::table('service_option_choice_prices')
                ->join('currencies', 'currencies.id', '=', 'service_option_choice_prices.currency_id')
                ->whereIn('service_option_choice_prices.service_option_choice_id', $choiceIds),
            'service_option_choice_prices.effective_from',
            'service_option_choice_prices.effective_to',
            $now,
        )
            ->select([
                'service_option_choice_prices.service_option_choice_id',
                'service_option_choice_prices.additional_amount',
                'currencies.code as currency_code',
                'currencies.symbol as currency_symbol',
                'currencies.minor_unit as currency_minor_unit',
            ])
            ->get()
            ->keyBy(fn ($row) => bin2hex($row->service_option_choice_id));

        $numericPricingRulesByOptionId = EffectivePricing::scope(
            DB::table('service_option_numeric_pricing_rules')
                ->join('currencies', 'currencies.id', '=', 'service_option_numeric_pricing_rules.currency_id')
                ->whereIn('service_option_numeric_pricing_rules.service_option_id', $optionIds),
            'service_option_numeric_pricing_rules.effective_from',
            'service_option_numeric_pricing_rules.effective_to',
            $now,
        )
            ->select([
                'service_option_numeric_pricing_rules.service_option_id',
                'service_option_numeric_pricing_rules.included_value',
                'service_option_numeric_pricing_rules.charge_unit_size',
                'service_option_numeric_pricing_rules.amount_per_unit',
                'service_option_numeric_pricing_rules.minimum_additional_amount',
                'service_option_numeric_pricing_rules.maximum_additional_amount',
                'currencies.code as currency_code',
                'currencies.symbol as currency_symbol',
                'currencies.minor_unit as currency_minor_unit',
            ])
            ->get()
            ->keyBy(fn ($row) => bin2hex($row->service_option_id));

        return $options
            ->map(function ($option) use (
                $numericRulesByOptionId,
                $selectionRulesByOptionId,
                $choicesByOptionId,
                $choicePricesByChoiceId,
                $numericPricingRulesByOptionId,
            ) {
                return $this->mapOption(
                    $option,
                    $numericRulesByOptionId,
                    $selectionRulesByOptionId,
                    $choicesByOptionId,
                    $choicePricesByChoiceId,
                    $numericPricingRulesByOptionId,
                );
            })
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
        Collection $choicePricesByChoiceId,
        Collection $numericPricingRulesByOptionId,
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

            $pricingRule = $numericPricingRulesByOptionId->get($key);

            $payload['numeric_pricing_rule'] = $pricingRule === null ? null : [
                'currency' => [
                    'code' => $pricingRule->currency_code,
                    'symbol' => $pricingRule->currency_symbol,
                    'minor_unit' => $pricingRule->currency_minor_unit,
                ],
                'included_value' => $pricingRule->included_value,
                'charge_unit_size' => $pricingRule->charge_unit_size,
                'amount_per_unit' => $pricingRule->amount_per_unit,
                'minimum_additional_amount' => $pricingRule->minimum_additional_amount,
                'maximum_additional_amount' => $pricingRule->maximum_additional_amount,
            ];
        }

        if (in_array($option->type_code, ['SINGLE_SELECT', 'MULTI_SELECT'], true)) {
            $rule = $selectionRulesByOptionId->get($key);

            $payload['selection_rule'] = $rule === null ? null : [
                'minimum_selections' => (int) $rule->minimum_selections,
                'maximum_selections' => (int) $rule->maximum_selections,
            ];

            $payload['choices'] = ($choicesByOptionId->get($key) ?? collect())
                ->map(function ($choice) use ($choicePricesByChoiceId) {
                    $price = $choicePricesByChoiceId->get(bin2hex($choice->id));

                    return [
                        'uuid' => UuidBinary::toString($choice->id),
                        'code' => $choice->code,
                        'name' => $choice->name,
                        'description' => $choice->description,
                        'current_additional_price' => $price === null ? null : [
                            'amount' => $price->additional_amount,
                            'currency' => [
                                'code' => $price->currency_code,
                                'symbol' => $price->currency_symbol,
                                'minor_unit' => $price->currency_minor_unit,
                            ],
                        ],
                    ];
                })
                ->values()
                ->all();
        }

        return $payload;
    }
}
