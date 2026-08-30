<?php

namespace App\Support\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B9. Presents the exact canonical `pricing_scheme_versions`/
 * `pricing_rules`/`pricing_rule_condition_groups`/`pricing_rule_conditions`/
 * `pricing_rule_condition_values`/`pricing_rule_tiers` rows the real
 * App\Support\Pricing\PricingEngine reads - never a second interpretation of
 * them. Human-readable references (option/choice/context-attribute name)
 * are joined in purely for Admin display; the underlying codes/operators
 * are always included too, since those are what PricingRuleEvaluator
 * actually acts on.
 */
final class AdminPricingSchemePresenter
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function presentList(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $versionIds = $rows->pluck('id')->all();

        $rulesCounts = DB::table('pricing_rules')
            ->whereIn('pricing_scheme_version_id', $versionIds)
            ->selectRaw('pricing_scheme_version_id, COUNT(*) as rules_count')
            ->groupBy('pricing_scheme_version_id')
            ->get()
            ->keyBy(fn (object $r) => bin2hex($r->pricing_scheme_version_id));

        return $rows->map(fn (object $row) => [
            'uuid' => UuidBinary::toString($row->id),
            'service' => ['uuid' => UuidBinary::toString($row->service_id), 'name' => $row->service_name],
            'currency' => ['code' => $row->currency_code, 'symbol' => $row->currency_symbol],
            'status' => $row->status,
            'effective_from' => $row->effective_from === null ? null : Carbon::parse($row->effective_from)->toIso8601String(),
            'effective_to' => $row->effective_to === null ? null : Carbon::parse($row->effective_to)->toIso8601String(),
            'rules_count' => (int) ($rulesCounts->get(bin2hex($row->id))->rules_count ?? 0),
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse($row->updated_at)->toIso8601String(),
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(object $version): array
    {
        $versionIdBinary = $version->id;

        $rules = DB::table('pricing_rules')
            ->where('pricing_scheme_version_id', $versionIdBinary)
            ->orderBy('priority')
            ->get();

        return [
            'uuid' => UuidBinary::toString($version->id),
            'service' => ['uuid' => UuidBinary::toString($version->service_id), 'name' => $version->service_name],
            'currency' => ['code' => $version->currency_code, 'symbol' => $version->currency_symbol],
            'status' => $version->status,
            'effective_from' => $version->effective_from === null ? null : Carbon::parse($version->effective_from)->toIso8601String(),
            'effective_to' => $version->effective_to === null ? null : Carbon::parse($version->effective_to)->toIso8601String(),
            'published_at' => $version->published_at === null ? null : Carbon::parse($version->published_at)->toIso8601String(),
            'created_at' => Carbon::parse($version->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse($version->updated_at)->toIso8601String(),
            'rules' => self::presentRules($rules),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function presentRules(Collection $rules): array
    {
        if ($rules->isEmpty()) {
            return [];
        }

        $ruleIds = $rules->pluck('id')->all();

        $optionIds = $rules->pluck('effect_subject_service_option_id')->filter()->all();
        $optionsById = self::optionsById($optionIds);

        $groups = DB::table('pricing_rule_condition_groups')
            ->whereIn('pricing_rule_id', $ruleIds)
            ->orderBy('group_order')
            ->get()
            ->groupBy(fn (object $g) => bin2hex($g->pricing_rule_id));

        $allGroupIds = $groups->flatten(1)->pluck('id')->all();

        $conditions = $allGroupIds === [] ? collect() : DB::table('pricing_rule_conditions')
            ->leftJoin('pricing_context_attributes', 'pricing_context_attributes.id', '=', 'pricing_rule_conditions.context_attribute_id')
            ->whereIn('pricing_rule_conditions.pricing_rule_condition_group_id', $allGroupIds)
            ->select([
                'pricing_rule_conditions.*',
                'pricing_context_attributes.code as context_attribute_code',
                'pricing_context_attributes.name as context_attribute_name',
            ])
            ->get()
            ->groupBy(fn (object $c) => bin2hex($c->pricing_rule_condition_group_id));

        $allConditionIds = $conditions->flatten(1)->pluck('id')->all();
        $allChoiceIds = $conditions->flatten(1)->pluck('value_choice_id')->filter()->all();

        $conditionValues = $allConditionIds === [] ? collect() : DB::table('pricing_rule_condition_values')
            ->whereIn('pricing_rule_condition_id', $allConditionIds)
            ->orderBy('sort_order')
            ->get()
            ->groupBy(fn (object $v) => bin2hex($v->pricing_rule_condition_id));

        $allChoiceIds = array_merge($allChoiceIds, $conditionValues->flatten(1)->pluck('value_choice_id')->filter()->all());
        $choicesById = self::choicesById(array_unique($allChoiceIds, SORT_REGULAR));

        $tiers = DB::table('pricing_rule_tiers')
            ->whereIn('pricing_rule_id', $ruleIds)
            ->orderBy('tier_order')
            ->get()
            ->groupBy(fn (object $t) => bin2hex($t->pricing_rule_id));

        return $rules->map(function (object $rule) use ($optionsById, $groups, $conditions, $conditionValues, $choicesById, $tiers) {
            $ruleKey = bin2hex($rule->id);
            $option = $rule->effect_subject_service_option_id === null ? null : $optionsById->get(bin2hex($rule->effect_subject_service_option_id));

            return [
                'uuid' => UuidBinary::toString($rule->id),
                'rule_code' => $rule->rule_code,
                'label' => $rule->label,
                'priority' => (int) $rule->priority,
                'effect_type' => $rule->effect_type,
                'effect_amount' => $rule->effect_amount,
                'effect_subject_option' => $option === null ? null : ['uuid' => UuidBinary::toString($rule->effect_subject_service_option_id), 'name' => $option->name],
                'tier_calculation_mode' => $rule->tier_calculation_mode,
                'stop_processing' => (bool) $rule->stop_processing,
                'condition_groups' => (($groups->get($ruleKey)) ?? collect())
                    ->map(fn (object $group) => [
                        'conditions' => (($conditions->get(bin2hex($group->id))) ?? collect())
                            ->map(fn (object $condition) => self::presentCondition($condition, $optionsById, $choicesById, $conditionValues->get(bin2hex($condition->id)) ?? collect()))
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all(),
                'tiers' => (($tiers->get($ruleKey)) ?? collect())
                    ->map(fn (object $tier) => [
                        'tier_order' => (int) $tier->tier_order,
                        'from_unit' => $tier->from_unit,
                        'to_unit' => $tier->to_unit,
                        'charge_unit_size' => $tier->charge_unit_size,
                        'rate_amount' => $tier->rate_amount,
                        'tier_pricing_mode' => $tier->tier_pricing_mode,
                    ])
                    ->values()
                    ->all(),
            ];
        })->values()->all();
    }

    private static function presentCondition(object $condition, Collection $optionsById, Collection $choicesById, Collection $values): array
    {
        $option = $condition->service_option_id === null ? null : $optionsById->get(bin2hex($condition->service_option_id));
        $choice = $condition->value_choice_id === null ? null : $choicesById->get(bin2hex($condition->value_choice_id));

        return [
            'subject_type' => $condition->subject_type,
            'option' => $option === null ? null : ['uuid' => UuidBinary::toString($condition->service_option_id), 'name' => $option->name],
            'context_attribute' => $condition->context_attribute_code === null ? null : ['code' => $condition->context_attribute_code, 'name' => $condition->context_attribute_name],
            'operator' => $condition->operator,
            'value_number' => $condition->value_number,
            'value_number_high' => $condition->value_number_high,
            'value_boolean' => $condition->value_boolean === null ? null : (bool) $condition->value_boolean,
            'value_choice' => $choice === null ? null : ['uuid' => UuidBinary::toString($condition->value_choice_id), 'name' => $choice->name],
            'value_set' => $values->map(fn (object $value) => $value->value_choice_id !== null
                ? ['uuid' => UuidBinary::toString($value->value_choice_id), 'name' => $choicesById->get(bin2hex($value->value_choice_id))?->name]
                : ['value_number' => $value->value_number])->values()->all(),
        ];
    }

    /**
     * @param  array<int, string>  $optionIdsBinary
     */
    private static function optionsById(array $optionIdsBinary): Collection
    {
        if ($optionIdsBinary === []) {
            return collect();
        }

        return DB::table('service_options')
            ->whereIn('id', $optionIdsBinary)
            ->get(['id', 'name'])
            ->keyBy(fn (object $o) => bin2hex($o->id));
    }

    /**
     * @param  array<int, string>  $choiceIdsBinary
     */
    private static function choicesById(array $choiceIdsBinary): Collection
    {
        if ($choiceIdsBinary === []) {
            return collect();
        }

        return DB::table('service_option_choices')
            ->whereIn('id', $choiceIdsBinary)
            ->get(['id', 'name'])
            ->keyBy(fn (object $c) => bin2hex($c->id));
    }
}
