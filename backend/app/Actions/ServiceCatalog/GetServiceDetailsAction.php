<?php

namespace App\Actions\ServiceCatalog;

use App\Support\Payment\ServicePaymentPolicy;
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
                'services.original_price',
                'services.is_featured',
                'services.estimated_duration_minutes',
                'services.min_quantity',
                'services.max_quantity',
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
            'is_featured' => (bool) $service->is_featured,
            'estimated_duration_minutes' => $service->estimated_duration_minutes === null ? null : (int) $service->estimated_duration_minutes,
            'quantity' => ['min' => (int) $service->min_quantity, 'max' => (int) $service->max_quantity],
            'category' => [
                'id' => $service->category_id,
                'code' => $service->category_code,
                'name' => $service->category_name,
                'description' => $service->category_description,
            ],
            'media' => $media,
            'pricing_preview' => $pricingPreview->toArray(),
            // BLUE V1 Phase B23 - additive two-price display block. Never
            // a second pricing calculation: `current_amount` is exactly
            // `pricing_preview.unit_total` from the SAME evaluate() call
            // above, restated under the discount-display-friendly names
            // the Admin catalog workspace also uses (App\Support\Admin\
            // AdminServicePresenter::pricingSummaryFor()) - one selling-
            // price authority, two response shapes for two different
            // clients to migrate to at their own pace.
            'pricing' => [
                'currency' => $currencyCode,
                'original_amount' => $service->original_price === null ? null : (string) $service->original_price,
                'current_amount' => $pricingPreview->unitTotal,
                'has_discount' => $service->original_price !== null
                    && $pricingPreview->unitTotal !== null
                    && bccomp((string) $service->original_price, $pricingPreview->unitTotal, 6) > 0,
            ],
            'options' => $this->loadOptions($service->id, $now),
            'content_sections' => $this->loadContentSections($service->id),
            'checkpoint_groups' => $this->loadCheckpointGroups($service->id),
            'payment_policy' => $this->paymentPolicyPayload($service->id),
        ];
    }

    /**
     * BLUE V1 Phase B23-ext - active, ordered content blocks only (Overview
     * / Recommended For / What's Included / a free-form heading). No
     * internal DB id is ever exposed to the customer client.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadContentSections(string $serviceIdBinary): array
    {
        return DB::table('service_content_sections')
            ->join('service_content_section_types', 'service_content_section_types.id', '=', 'service_content_sections.section_type_id')
            ->where('service_content_sections.service_id', $serviceIdBinary)
            ->where('service_content_sections.is_active', 1)
            ->orderBy('service_content_sections.display_order')
            ->select([
                'service_content_section_types.code as section_type_code',
                'service_content_sections.title',
                'service_content_sections.body',
            ])
            ->get()
            ->map(fn ($row) => [
                'section_type_code' => $row->section_type_code,
                'title' => $row->title,
                'body' => $row->body,
            ])
            ->values()
            ->all();
    }

    /**
     * BLUE V1 Phase B23-ext - active checkpoint groups, each with its
     * active checkpoints ordered, plus a DERIVED count (never a stored
     * counter - see App\Support\Admin\AdminServicePresenter::
     * checkpointGroupsFor()'s docblock for why). A group with zero active
     * checkpoints is omitted entirely rather than shown empty.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadCheckpointGroups(string $serviceIdBinary): array
    {
        $groups = DB::table('service_checkpoint_groups')
            ->where('service_id', $serviceIdBinary)
            ->where('is_active', 1)
            ->orderBy('display_order')
            ->get(['id', 'name', 'description']);

        if ($groups->isEmpty()) {
            return [];
        }

        $groupIds = $groups->pluck('id')->all();

        $checkpointsByGroupId = DB::table('service_checkpoints')
            ->join('service_checkpoint_action_types', 'service_checkpoint_action_types.id', '=', 'service_checkpoints.action_type_id')
            ->whereIn('service_checkpoints.group_id', $groupIds)
            ->where('service_checkpoints.is_active', 1)
            ->orderBy('service_checkpoints.display_order')
            ->select([
                'service_checkpoints.group_id',
                'service_checkpoints.name',
                'service_checkpoints.description',
                'service_checkpoint_action_types.code as action_type_code',
                'service_checkpoint_action_types.name as action_type_name',
            ])
            ->get()
            ->groupBy(fn ($row) => bin2hex($row->group_id));

        return $groups
            ->map(function ($group) use ($checkpointsByGroupId) {
                $checkpoints = ($checkpointsByGroupId->get(bin2hex($group->id)) ?? collect())
                    ->map(fn ($c) => [
                        'name' => $c->name,
                        'description' => $c->description,
                        'action_type_code' => $c->action_type_code,
                        'action_type_name' => $c->action_type_name,
                    ])
                    ->values();

                if ($checkpoints->isEmpty()) {
                    return null;
                }

                return [
                    'name' => $group->name,
                    'description' => $group->description,
                    'checkpoint_count' => $checkpoints->count(),
                    'checkpoints' => $checkpoints->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();
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
        $attributesByChoiceId = $this->loadChoiceAttributes($choiceIds);

        return $options
            ->map(fn ($option) => $this->mapOption($option, $numericRulesByOptionId, $selectionRulesByOptionId, $choicesByOptionId, $attributesByChoiceId))
            ->values()
            ->all();
    }

    /**
     * BLUE V1 Phase B23-ext - active-only, customer-safe structured
     * attributes for a set of choices (e.g. a car-service package choice's
     * oil brand/grade). Never leaks the internal attribute row id.
     *
     * @param  array<int, string>  $choiceIdsBinary
     * @return Collection<string, array<int, array<string, mixed>>> Keyed by hex choice id.
     */
    private function loadChoiceAttributes(array $choiceIdsBinary): Collection
    {
        if ($choiceIdsBinary === []) {
            return collect();
        }

        return DB::table('service_option_choice_attributes')
            ->join('service_option_choice_attribute_types', 'service_option_choice_attribute_types.id', '=', 'service_option_choice_attributes.attribute_type_id')
            ->whereIn('service_option_choice_attributes.choice_id', $choiceIdsBinary)
            ->where('service_option_choice_attributes.is_active', 1)
            ->orderBy('service_option_choice_attribute_types.display_order')
            ->select([
                'service_option_choice_attributes.choice_id',
                'service_option_choice_attributes.value_string',
                'service_option_choice_attributes.value_number',
                'service_option_choice_attribute_types.code as attribute_type_code',
                'service_option_choice_attribute_types.name as attribute_type_name',
                'service_option_choice_attribute_types.data_type',
            ])
            ->get()
            ->groupBy(fn ($row) => bin2hex($row->choice_id))
            ->map(fn (Collection $rows) => $rows->map(fn ($row) => [
                'attribute_type_code' => $row->attribute_type_code,
                'attribute_type_name' => $row->attribute_type_name,
                'value' => $row->data_type === 'NUMBER' ? $row->value_number : $row->value_string,
            ])->values()->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function mapOption(
        object $option,
        Collection $numericRulesByOptionId,
        Collection $selectionRulesByOptionId,
        Collection $choicesByOptionId,
        Collection $attributesByChoiceId,
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
                    'attributes' => $attributesByChoiceId->get(bin2hex($choice->id), []),
                ])
                ->values()
                ->all();
        }

        return $payload;
    }

    /**
     * BLUE V1 Phase B24 - the authoritative allowed-payment-methods block
     * for this single Service (never internal DB ids, never Stripe
     * configuration). A Cart mixing several Services must instead read the
     * INTERSECTION from Checkout (App\Support\Checkout\
     * CheckoutPaymentPolicy) - this per-Service block is what a shopper
     * sees before adding anything to a Cart.
     *
     * @return array<string, mixed>
     */
    private function paymentPolicyPayload(string $serviceIdBinary): array
    {
        $methods = ServicePaymentPolicy::allowedMethodsFor($serviceIdBinary)->values()->all();

        return [
            'requires_prepayment' => ServicePaymentPolicy::requiresPrepayment($methods),
            'allowed_methods' => array_map(fn ($m) => ['code' => $m['code'], 'label' => $m['name']], $methods),
        ];
    }
}
