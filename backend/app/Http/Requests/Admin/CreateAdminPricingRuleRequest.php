<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Support\Pricing\ConditionOperator;
use App\Support\Pricing\ConditionSubjectType;
use App\Support\Pricing\EffectType;
use App\Support\Pricing\TierCalculationMode;
use App\Support\Pricing\TierPricingMode;
use Illuminate\Validation\Rule;

/**
 * Field-level shape validation only - mirrors the literal
 * `pricing_rules`/`pricing_rule_condition_groups`/`pricing_rule_conditions`/
 * `pricing_rule_tiers` CHECK constraints (database/blue_v1_schema.sql) so a
 * malformed request gets a clean 422 instead of a raw DB constraint
 * violation. Cross-row publish-readiness checks (duplicate priorities,
 * cross-service option references, tier sequence coverage) are
 * deliberately NOT duplicated here - those remain App\Support\Pricing\
 * SchemePublishValidator's exclusive job at publish time.
 */
class CreateAdminPricingRuleRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rule_code' => ['required', 'string', 'min:2', 'max:80'],
            'label' => ['required', 'string', 'min:2', 'max:160'],
            'priority' => ['required', 'integer', 'min:0', 'max:65535'],
            'effect_type' => ['required', Rule::in(array_column(EffectType::cases(), 'value'))],
            'effect_amount' => ['nullable', 'numeric'],
            'effect_subject_service_option_id' => ['nullable', 'uuid'],
            'tier_calculation_mode' => ['nullable', Rule::in(array_column(TierCalculationMode::cases(), 'value'))],
            'stop_processing' => ['required', 'boolean'],

            'condition_groups' => ['nullable', 'array'],
            'condition_groups.*.conditions' => ['required', 'array', 'min:1'],
            'condition_groups.*.conditions.*.subject_type' => ['required', Rule::in(array_column(ConditionSubjectType::cases(), 'value'))],
            'condition_groups.*.conditions.*.service_option_id' => ['nullable', 'uuid'],
            'condition_groups.*.conditions.*.context_attribute_code' => ['nullable', 'string'],
            'condition_groups.*.conditions.*.operator' => ['required', Rule::in(array_column(ConditionOperator::cases(), 'value'))],
            'condition_groups.*.conditions.*.value_number' => ['nullable', 'numeric'],
            'condition_groups.*.conditions.*.value_number_high' => ['nullable', 'numeric'],
            'condition_groups.*.conditions.*.value_boolean' => ['nullable', 'boolean'],
            'condition_groups.*.conditions.*.value_choice_id' => ['nullable', 'uuid'],
            'condition_groups.*.conditions.*.value_set' => ['nullable', 'array', 'min:1'],

            'tiers' => ['nullable', 'array'],
            'tiers.*.tier_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'tiers.*.from_unit' => ['required', 'numeric', 'min:0'],
            'tiers.*.to_unit' => ['nullable', 'numeric'],
            'tiers.*.charge_unit_size' => ['nullable', 'numeric', 'gt:0'],
            'tiers.*.rate_amount' => ['required', 'numeric', 'min:0'],
            'tiers.*.tier_pricing_mode' => ['required', Rule::in(array_column(TierPricingMode::cases(), 'value'))],
        ];
    }
}
