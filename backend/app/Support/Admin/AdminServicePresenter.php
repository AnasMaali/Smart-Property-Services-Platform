<?php

namespace App\Support\Admin;

use App\Support\Payment\ServicePaymentPolicy;
use App\Support\Pricing\DefaultCurrency;
use App\Support\Pricing\PricingEngine;
use App\Support\Pricing\PricingSchemeRepository;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B8, extended through Phase B23-ext. Unlike the customer
 * -facing App\Actions\ServiceCatalog\GetServiceDetailsAction (which only
 * ever shows active rows to a shopper), this presenter deliberately shows
 * Admin every row regardless of its own `is_active` flag - an operator
 * needs to see what is currently hidden from the mobile app, not just what
 * a customer would see.
 *
 * `service_capabilities` remains presented read-only - see
 * App\Actions\Admin\Service\AdminGetServiceAction's docblock for why.
 * Everything else here (Options/Choices/Specializations/Media, and as of
 * Phase B23-ext: content sections, checkpoint groups/checkpoints, and
 * per-choice structured attributes) has an explicit, reviewed mutation
 * Action - see each domain's own Action docblock for its specific safety
 * story.
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
                'services.display_order', 'services.original_price', 'services.is_featured',
                'services.estimated_duration_minutes', 'services.min_quantity', 'services.max_quantity',
                'services.created_at', 'services.updated_at',
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
        $currentAmountByServiceUuid = self::currentAmountsFor($rows->pluck('id')->map(UuidBinary::toString(...))->all());

        return $rows->map(function (object $row) use ($capabilitiesByServiceId, $currentAmountByServiceUuid) {
            $serviceUuid = UuidBinary::toString($row->id);
            $originalPrice = $row->original_price === null ? null : (string) $row->original_price;
            $currentAmount = $currentAmountByServiceUuid[$serviceUuid] ?? null;

            return [
                'uuid' => $serviceUuid,
                'code' => $row->code,
                'name' => $row->name,
                'is_active' => (bool) $row->is_active,
                'is_featured' => (bool) $row->is_featured,
                'estimated_duration_minutes' => $row->estimated_duration_minutes === null ? null : (int) $row->estimated_duration_minutes,
                'quantity' => ['min' => (int) $row->min_quantity, 'max' => (int) $row->max_quantity],
                'display_order' => (int) $row->display_order,
                'category' => [
                    'id' => $row->category_id,
                    'name' => $row->category_name,
                ],
                'capabilities' => $capabilitiesByServiceId->get(bin2hex($row->id), []),
                'pricing' => [
                    'currency' => DefaultCurrency::code(),
                    'original_amount' => $originalPrice,
                    'current_amount' => $currentAmount,
                    'has_discount' => $originalPrice !== null && $currentAmount !== null && bccomp($originalPrice, $currentAmount, 6) > 0,
                ],
                'updated_at' => Carbon::parse($row->updated_at)->toIso8601String(),
            ];
        })->all();
    }

    /**
     * BLUE V1 Phase B23 - the SAME App\Support\Pricing\PricingEngine every
     * customer catalog/cart/checkout call already uses, batched for a whole
     * list screen - never a second, divergent "list price" calculation.
     *
     * @param  array<int, string>  $serviceUuids
     * @return array<string, ?string> Keyed by service UUID string.
     */
    private static function currentAmountsFor(array $serviceUuids): array
    {
        if ($serviceUuids === []) {
            return [];
        }

        $results = (new PricingEngine)->previewMany($serviceUuids, DefaultCurrency::code());

        return array_map(fn ($result) => $result->unitTotal, $results);
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
            'is_featured' => (bool) $row->is_featured,
            'estimated_duration_minutes' => $row->estimated_duration_minutes === null ? null : (int) $row->estimated_duration_minutes,
            'quantity' => ['min' => (int) $row->min_quantity, 'max' => (int) $row->max_quantity],
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
            'pricing' => self::pricingSummaryFor($serviceId, $row->original_price === null ? null : (string) $row->original_price),
            'content_sections' => self::contentSectionsFor($serviceId),
            'checkpoint_groups' => self::checkpointGroupsFor($serviceId),
            'payment_policy' => self::paymentPolicyFor($serviceId),
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
                'service_options.display_order',
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
            ->get(['id', 'service_option_id', 'code', 'name', 'description', 'display_order', 'is_active'])
            ->groupBy(fn (object $r) => bin2hex($r->service_option_id));

        $choiceIds = $choicesByOptionId->flatten(1)->pluck('id')->all();
        $attributesByChoiceId = self::choiceAttributesFor($choiceIds);

        return $options->map(function (object $option) use ($numericRulesByOptionId, $selectionRulesByOptionId, $choicesByOptionId, $attributesByChoiceId) {
            $key = bin2hex($option->id);

            $payload = [
                'uuid' => UuidBinary::toString($option->id),
                'code' => $option->code,
                'name' => $option->name,
                'description' => $option->description,
                'type' => $option->type_code,
                'is_required' => (bool) $option->is_required,
                'display_order' => (int) $option->display_order,
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
                        'description' => $choice->description,
                        'display_order' => (int) $choice->display_order,
                        'is_active' => (bool) $choice->is_active,
                        'attributes' => $attributesByChoiceId->get(bin2hex($choice->id), collect())->values()->all(),
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
     * Which pricing_scheme_versions exist for this Service in BLUE's
     * default currency (and their publish status - read-only, never
     * editable here), PLUS the two-price catalog display block BLUE V1
     * Phase B23 adds: `original_amount` is the additive `services.
     * original_price` catalog metadata (never a pricing authority);
     * `current_amount` is computed by the SAME App\Support\Pricing\
     * PricingEngine every customer catalog/cart/checkout call already
     * uses (`evaluate()` with no selections, quantity 1) - never a second,
     * divergent calculation, and `null` exactly when the customer catalog
     * would also see no available price yet. Full pricing-rule authoring/
     * publishing beyond the simple current-price convenience
     * (App\Actions\Admin\Pricing\AdminSetServiceCurrentPriceAction) remains
     * BLUE V1 Phase B9's exclusive domain - see docs/api-contracts/
     * admin-operations-v1.md "Pricing boundary with B9".
     *
     * @return array<string, mixed>
     */
    private static function pricingSummaryFor(string $serviceIdBinary, ?string $originalPrice): array
    {
        $currencyCode = DefaultCurrency::code();
        $currencyId = (int) DB::table('currencies')->where('code', $currencyCode)->value('id');

        $schemeVersions = (new PricingSchemeRepository)->schemeVersionsFor($serviceIdBinary, $currencyId);

        $currentAmount = self::currentSellingPrice(UuidBinary::toString($serviceIdBinary));

        return [
            'currency_code' => $currencyCode,
            'scheme_versions' => $schemeVersions,
            'currency' => $currencyCode,
            'original_amount' => $originalPrice,
            'current_amount' => $currentAmount,
            'has_discount' => $originalPrice !== null && $currentAmount !== null && bccomp($originalPrice, $currentAmount, 6) > 0,
        ];
    }

    /**
     * The one place any Actions/Admin class may learn "what does this
     * Service currently sell for" - App\Support\Pricing\PricingEngine
     * itself is deliberately called only from this Support-layer presenter
     * (never directly from an app/Actions/Admin/**\/*.php file), matching
     * this codebase's Admin/pricing-engine isolation boundary asserted by
     * Tests\Feature\Admin\AdminFinancialIsolationTest. Same `evaluate()`
     * call (no selections, quantity 1) every customer catalog/cart/
     * checkout call already uses - never a second, divergent calculation.
     */
    public static function currentSellingPrice(string $serviceUuid): ?string
    {
        return (new PricingEngine)->evaluate(
            serviceIdUuid: $serviceUuid,
            selections: [],
            quantity: 1,
            currencyCode: DefaultCurrency::code(),
        )->unitTotal;
    }

    /**
     * BLUE V1 Phase B23-ext - the Admin Pricing Preview/tester tool's ONLY
     * calculation call, kept out of App\Actions\Admin\Pricing\
     * AdminPreviewServicePricingAction directly per this codebase's Admin/
     * pricing-engine isolation boundary (same reasoning as
     * currentSellingPrice() above). Selections have already been validated
     * and shaped by App\Support\Cart\CartSelectionValidator (the same
     * validator the real Cart Add/Update flow uses) before this is called -
     * never a second, divergent calculation or a JS-side reimplementation.
     *
     * @param  array<string, array<string, mixed>>  $selections
     * @return array<string, mixed>
     */
    public static function previewPricing(string $serviceUuid, array $selections, int $quantity): array
    {
        return (new PricingEngine)->evaluate(
            serviceIdUuid: $serviceUuid,
            selections: $selections,
            quantity: $quantity,
            currencyCode: DefaultCurrency::code(),
        )->toArray();
    }

    /**
     * BLUE V1 Phase B23-ext. Generic typed key/value attributes on a
     * SINGLE_SELECT/MULTI_SELECT choice (e.g. a car-service package
     * choice's oil brand/grade, duration, recommended odometer) - see
     * App\Actions\Admin\Service\AdminServiceOptionChoiceAttributeAction's
     * docblock. Every value is presented regardless of its own `is_active`
     * flag, matching this presenter's Admin-sees-everything convention.
     *
     * @param  array<int, string>  $choiceIdsBinary
     * @return Collection<string, Collection<int, array<string, mixed>>> Keyed by hex choice id.
     */
    private static function choiceAttributesFor(array $choiceIdsBinary): Collection
    {
        if ($choiceIdsBinary === []) {
            return collect();
        }

        return DB::table('service_option_choice_attributes')
            ->join('service_option_choice_attribute_types', 'service_option_choice_attribute_types.id', '=', 'service_option_choice_attributes.attribute_type_id')
            ->whereIn('service_option_choice_attributes.choice_id', $choiceIdsBinary)
            ->orderBy('service_option_choice_attribute_types.display_order')
            ->select([
                'service_option_choice_attributes.id',
                'service_option_choice_attributes.choice_id',
                'service_option_choice_attributes.value_string',
                'service_option_choice_attributes.value_number',
                'service_option_choice_attributes.is_active',
                'service_option_choice_attribute_types.code as attribute_type_code',
                'service_option_choice_attribute_types.name as attribute_type_name',
                'service_option_choice_attribute_types.data_type',
            ])
            ->get()
            ->groupBy(fn (object $r) => bin2hex($r->choice_id))
            ->map(fn (Collection $rows) => $rows->map(fn (object $r) => [
                'uuid' => UuidBinary::toString($r->id),
                'attribute_type_code' => $r->attribute_type_code,
                'attribute_type_name' => $r->attribute_type_name,
                'data_type' => $r->data_type,
                'value' => $r->data_type === 'NUMBER' ? $r->value_number : $r->value_string,
                'is_active' => (bool) $r->is_active,
            ]));
    }

    /**
     * BLUE V1 Phase B23-ext. Generic ordered content blocks for a Service
     * (Overview / Recommended For / What's Included / free-form headings)
     * - see App\Actions\Admin\Service\AdminServiceContentSectionAction's
     * docblock.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function contentSectionsFor(string $serviceIdBinary): array
    {
        return DB::table('service_content_sections')
            ->join('service_content_section_types', 'service_content_section_types.id', '=', 'service_content_sections.section_type_id')
            ->where('service_content_sections.service_id', $serviceIdBinary)
            ->orderBy('service_content_sections.display_order')
            ->select([
                'service_content_sections.id',
                'service_content_sections.title',
                'service_content_sections.body',
                'service_content_sections.display_order',
                'service_content_sections.is_active',
                'service_content_section_types.code as section_type_code',
                'service_content_section_types.name as section_type_name',
            ])
            ->get()
            ->map(fn (object $r) => [
                'uuid' => UuidBinary::toString($r->id),
                'section_type_code' => $r->section_type_code,
                'section_type_name' => $r->section_type_name,
                'title' => $r->title,
                'body' => $r->body,
                'display_order' => (int) $r->display_order,
                'is_active' => (bool) $r->is_active,
            ])
            ->values()
            ->all();
    }

    /**
     * BLUE V1 Phase B23-ext. Generic ordered workshop-checklist structure
     * for a Service - see App\Actions\Admin\Service\
     * AdminServiceCheckpointGroupAction / AdminServiceCheckpointAction's
     * docblocks. `checkpoint_count`/`active_checkpoint_count` are always
     * DERIVED here (never a manually-maintained counter column) so they
     * can never drift from the actual checkpoint rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function checkpointGroupsFor(string $serviceIdBinary): array
    {
        $groups = DB::table('service_checkpoint_groups')
            ->where('service_id', $serviceIdBinary)
            ->orderBy('display_order')
            ->get(['id', 'name', 'description', 'display_order', 'is_active']);

        if ($groups->isEmpty()) {
            return [];
        }

        $groupIds = $groups->pluck('id')->all();

        $checkpointsByGroupId = DB::table('service_checkpoints')
            ->join('service_checkpoint_action_types', 'service_checkpoint_action_types.id', '=', 'service_checkpoints.action_type_id')
            ->whereIn('service_checkpoints.group_id', $groupIds)
            ->orderBy('service_checkpoints.display_order')
            ->select([
                'service_checkpoints.id',
                'service_checkpoints.group_id',
                'service_checkpoints.name',
                'service_checkpoints.description',
                'service_checkpoints.display_order',
                'service_checkpoints.is_active',
                'service_checkpoint_action_types.code as action_type_code',
                'service_checkpoint_action_types.name as action_type_name',
            ])
            ->get()
            ->groupBy(fn (object $r) => bin2hex($r->group_id));

        return $groups->map(function (object $group) use ($checkpointsByGroupId) {
            $checkpoints = ($checkpointsByGroupId->get(bin2hex($group->id)) ?? collect())
                ->map(fn (object $c) => [
                    'uuid' => UuidBinary::toString($c->id),
                    'name' => $c->name,
                    'description' => $c->description,
                    'action_type_code' => $c->action_type_code,
                    'action_type_name' => $c->action_type_name,
                    'display_order' => (int) $c->display_order,
                    'is_active' => (bool) $c->is_active,
                ])
                ->values();

            return [
                'uuid' => UuidBinary::toString($group->id),
                'name' => $group->name,
                'description' => $group->description,
                'display_order' => (int) $group->display_order,
                'is_active' => (bool) $group->is_active,
                'checkpoint_count' => $checkpoints->count(),
                'active_checkpoint_count' => $checkpoints->where('is_active', true)->count(),
                'checkpoints' => $checkpoints->all(),
            ];
        })->values()->all();
    }

    /**
     * BLUE V1 Phase B24 - the Service's current allowed payment methods,
     * shown to Admin regardless of any read/write boundary the customer
     * side has (there is none here - `App\Actions\Admin\Service\
     * AdminSetServicePaymentMethodsAction` is this domain's canonical
     * writer). `requires_prepayment` is always the COMPUTED absence of
     * PAY_ON_SITE - see App\Support\Payment\ServicePaymentPolicy.
     *
     * @return array<string, mixed>
     */
    private static function paymentPolicyFor(string $serviceIdBinary): array
    {
        $methods = ServicePaymentPolicy::allowedMethodsFor($serviceIdBinary)->values()->all();

        return [
            'allowed_methods' => $methods,
            'requires_prepayment' => ServicePaymentPolicy::requiresPrepayment($methods),
        ];
    }
}
