<?php

namespace App\Support\Admin;

use App\Support\Pricing\DefaultCurrency;
use App\Support\Pricing\PricingSchemeRepository;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B8. Unlike the customer-facing App\Actions\ServiceCatalog\
 * GetServiceDetailsAction (which only ever shows active options/choices to
 * a shopper), this presenter deliberately shows Admin every row regardless
 * of its own `is_active` flag - an operator needs to see what is currently
 * hidden from the mobile app, not just what a customer would see.
 *
 * Options/Capabilities/Specializations/Media are presented read-only here.
 * See App\Actions\Admin\Service\AdminGetServiceAction's docblock for why:
 * in short, each one gates real downstream behavior (Cart/Contract
 * eligibility, technician-candidate matching, pricing) or has no
 * established mutation-safety story yet, so BLUE V1 standing policy is to
 * report rather than invent a mutation policy for them.
 */
final class AdminServicePresenter
{
    /**
     * The single query every Admin Service Action shares to load one
     * Service + its Category for `detail()` - avoids re-typing the same
     * join/select list in AdminGetServiceAction, AdminUpdateServiceMetadataAction,
     * AdminActivateServiceAction, and AdminDeactivateServiceAction.
     */
    public static function loadForDetail(string $serviceIdBinary): ?object
    {
        return DB::table('services')
            ->join('service_categories', 'service_categories.id', '=', 'services.category_id')
            ->where('services.id', $serviceIdBinary)
            ->select([
                'services.id', 'services.code', 'services.slug', 'services.name',
                'services.short_description', 'services.description', 'services.is_active',
                'services.display_order', 'services.created_at', 'services.updated_at',
                'service_categories.id as category_id', 'service_categories.code as category_code',
                'service_categories.name as category_name', 'service_categories.is_active as category_is_active',
            ])
            ->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function presentList(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $serviceIds = $rows->pluck('id')->all();
        $capabilitiesByServiceId = self::capabilitiesFor($serviceIds);

        return $rows->map(fn (object $row) => [
            'uuid' => UuidBinary::toString($row->id),
            'code' => $row->code,
            'name' => $row->name,
            'is_active' => (bool) $row->is_active,
            'display_order' => (int) $row->display_order,
            'category' => [
                'id' => $row->category_id,
                'name' => $row->category_name,
            ],
            'capabilities' => $capabilitiesByServiceId->get(bin2hex($row->id), []),
            'updated_at' => Carbon::parse($row->updated_at)->toIso8601String(),
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(object $row): array
    {
        $serviceId = $row->id;

        return [
            'uuid' => UuidBinary::toString($serviceId),
            'code' => $row->code,
            'slug' => $row->slug,
            'name' => $row->name,
            'short_description' => $row->short_description,
            'description' => $row->description,
            'is_active' => (bool) $row->is_active,
            'display_order' => (int) $row->display_order,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse($row->updated_at)->toIso8601String(),
            'category' => [
                'id' => $row->category_id,
                'code' => $row->category_code,
                'name' => $row->category_name,
                'is_active' => (bool) $row->category_is_active,
            ],
            'capabilities' => self::capabilitiesFor([$serviceId])->get(bin2hex($serviceId), []),
            'specializations' => self::specializationsFor($serviceId),
            'options' => self::optionsFor($serviceId),
            'media' => self::mediaFor($serviceId),
            'pricing' => self::pricingSummaryFor($serviceId),
        ];
    }

    /**
     * @param  array<int, string>  $serviceIdsBinary
     * @return Collection<string, array<int, array{code: string, name: string, description: ?string}>> Keyed by hex service id.
     */
    private static function capabilitiesFor(array $serviceIdsBinary): Collection
    {
        if ($serviceIdsBinary === []) {
            return collect();
        }

        return DB::table('service_capabilities')
            ->join('service_capability_types', 'service_capability_types.id', '=', 'service_capabilities.capability_type_id')
            ->whereIn('service_capabilities.service_id', $serviceIdsBinary)
            ->orderBy('service_capability_types.code')
            ->get(['service_capabilities.service_id', 'service_capability_types.code', 'service_capability_types.name', 'service_capability_types.description'])
            ->groupBy(fn (object $r) => bin2hex($r->service_id))
            ->map(fn (Collection $rows) => $rows->map(fn (object $r) => [
                'code' => $r->code,
                'name' => $r->name,
                'description' => $r->description,
            ])->values()->all());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function specializationsFor(string $serviceIdBinary): array
    {
        return DB::table('service_specializations')
            ->join('specializations', 'specializations.id', '=', 'service_specializations.specialization_id')
            ->where('service_specializations.service_id', $serviceIdBinary)
            ->orderByDesc('service_specializations.is_primary')
            ->orderBy('service_specializations.display_order')
            ->get([
                'specializations.code',
                'specializations.name',
                'service_specializations.is_primary',
                'service_specializations.is_active',
            ])
            ->map(fn (object $r) => [
                'code' => $r->code,
                'name' => $r->name,
                'is_primary' => (bool) $r->is_primary,
                'is_active' => (bool) $r->is_active,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function optionsFor(string $serviceIdBinary): array
    {
        $options = DB::table('service_options')
            ->join('service_option_types', 'service_option_types.id', '=', 'service_options.option_type_id')
            ->where('service_options.service_id', $serviceIdBinary)
            ->orderBy('service_options.display_order')
            ->select([
                'service_options.id',
                'service_options.code',
                'service_options.name',
                'service_options.description',
                'service_options.is_required',
                'service_options.is_active',
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
                'measurement_units.code as measurement_unit_code',
                'measurement_units.symbol as measurement_unit_symbol',
            ])
            ->get()
            ->keyBy(fn (object $r) => bin2hex($r->service_option_id));

        $selectionRulesByOptionId = DB::table('service_option_selection_rules')
            ->whereIn('service_option_id', $optionIds)
            ->get(['service_option_id', 'minimum_selections', 'maximum_selections'])
            ->keyBy(fn (object $r) => bin2hex($r->service_option_id));

        $choicesByOptionId = DB::table('service_option_choices')
            ->whereIn('service_option_id', $optionIds)
            ->orderBy('display_order')
            ->get(['id', 'service_option_id', 'code', 'name', 'is_active'])
            ->groupBy(fn (object $r) => bin2hex($r->service_option_id));

        return $options->map(function (object $option) use ($numericRulesByOptionId, $selectionRulesByOptionId, $choicesByOptionId) {
            $key = bin2hex($option->id);

            $payload = [
                'uuid' => UuidBinary::toString($option->id),
                'code' => $option->code,
                'name' => $option->name,
                'description' => $option->description,
                'type' => $option->type_code,
                'is_required' => (bool) $option->is_required,
                'is_active' => (bool) $option->is_active,
            ];

            if ($option->type_code === 'NUMBER') {
                $rule = $numericRulesByOptionId->get($key);

                $payload['numeric_rule'] = $rule === null ? null : [
                    'min_value' => $rule->minimum_value,
                    'max_value' => $rule->maximum_value,
                    'step_value' => $rule->step_value,
                    'default_value' => $rule->default_value,
                    'decimal_places' => (int) $rule->decimal_places,
                    'measurement_unit_code' => $rule->measurement_unit_code,
                    'measurement_unit_symbol' => $rule->measurement_unit_symbol,
                ];
            }

            if (in_array($option->type_code, ['SINGLE_SELECT', 'MULTI_SELECT'], true)) {
                $rule = $selectionRulesByOptionId->get($key);

                $payload['selection_rule'] = $rule === null ? null : [
                    'minimum_selections' => (int) $rule->minimum_selections,
                    'maximum_selections' => (int) $rule->maximum_selections,
                ];

                $payload['choices'] = ($choicesByOptionId->get($key) ?? collect())
                    ->map(fn (object $choice) => [
                        'uuid' => UuidBinary::toString($choice->id),
                        'code' => $choice->code,
                        'name' => $choice->name,
                        'is_active' => (bool) $choice->is_active,
                    ])
                    ->values()
                    ->all();
            }

            return $payload;
        })->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function mediaFor(string $serviceIdBinary): array
    {
        return DB::table('service_media')
            ->where('service_id', $serviceIdBinary)
            ->orderBy('display_order')
            ->get(['id', 'storage_key', 'mime_type', 'alt_text', 'caption', 'width_pixels', 'height_pixels', 'is_primary', 'is_active'])
            ->map(fn (object $item) => [
                'uuid' => UuidBinary::toString($item->id),
                'storage_key' => $item->storage_key,
                'mime_type' => $item->mime_type,
                'alt_text' => $item->alt_text,
                'caption' => $item->caption,
                'width_pixels' => $item->width_pixels,
                'height_pixels' => $item->height_pixels,
                'is_primary' => (bool) $item->is_primary,
                'is_active' => (bool) $item->is_active,
            ])
            ->values()
            ->all();
    }

    /**
     * Read-only: which pricing_scheme_versions exist for this Service in
     * BLUE's default currency, and their publish status - never a rule
     * evaluation (that remains PricingEngine's job) and never editable here.
     * Full pricing-rule authoring/publishing is BLUE V1 Phase B9's exclusive
     * domain - see docs/api-contracts/admin-operations-v1.md "Pricing
     * boundary with B9".
     *
     * @return array<string, mixed>
     */
    private static function pricingSummaryFor(string $serviceIdBinary): array
    {
        $currencyCode = DefaultCurrency::code();
        $currencyId = (int) DB::table('currencies')->where('code', $currencyCode)->value('id');

        $schemeVersions = (new PricingSchemeRepository)->schemeVersionsFor($serviceIdBinary, $currencyId);

        return [
            'currency_code' => $currencyCode,
            'scheme_versions' => $schemeVersions,
        ];
    }
}
