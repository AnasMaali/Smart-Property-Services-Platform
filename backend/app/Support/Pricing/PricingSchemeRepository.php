<?php

namespace App\Support\Pricing;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Loads pricing_scheme_versions + pricing_rules (with their condition
 * groups/conditions/condition values and tiers) for one service+currency,
 * converting every binary(16) id to a UUID string so the pure
 * PricingRuleEvaluator never has to know about MySQL's storage format.
 *
 * Batches every child table with a single whereIn(), mirroring the
 * N+1-avoidance pattern already used by GetServiceDetailsAction.
 */
final class PricingSchemeRepository
{
    /**
     * @return array<int, array{id: string, status: string, effective_from: ?string, effective_to: ?string}>
     */
    public function schemeVersionsFor(string $serviceIdBinary, int $currencyId): array
    {
        return $this->schemeVersionsForMany([$serviceIdBinary], $currencyId)->get(bin2hex($serviceIdBinary), []);
    }

    /**
     * Batched variant for list screens (e.g. category → services) so pricing
     * a whole page of services never degrades into one query per service.
     *
     * @param  array<int, string>  $serviceIdsBinary
     * @return Collection<string, array<int, array{id: string, status: string, effective_from: ?string, effective_to: ?string}>> Keyed by hex service id.
     */
    public function schemeVersionsForMany(array $serviceIdsBinary, int $currencyId): Collection
    {
        if ($serviceIdsBinary === []) {
            return collect();
        }

        return DB::table('pricing_scheme_versions')
            ->whereIn('service_id', $serviceIdsBinary)
            ->where('currency_id', $currencyId)
            ->get(['service_id', 'id', 'status', 'effective_from', 'effective_to'])
            ->groupBy(fn ($row) => bin2hex($row->service_id))
            ->map(fn ($rows) => $rows->map(fn ($row) => [
                'id' => UuidBinary::toString($row->id),
                'status' => $row->status,
                'effective_from' => $row->effective_from,
                'effective_to' => $row->effective_to,
            ])->all());
    }

    /**
     * @return array<int, array<string, mixed>> Rule definitions shaped exactly as PricingRuleEvaluator expects.
     */
    public function rulesForSchemeVersion(string $pricingSchemeVersionIdBinary): array
    {
        return $this->rulesForSchemeVersions([$pricingSchemeVersionIdBinary])->get(bin2hex($pricingSchemeVersionIdBinary), []);
    }

    /**
     * Batched variant: loads rules (+ conditions/tiers) for several scheme
     * versions in one fixed-size set of queries, regardless of how many
     * versions are requested.
     *
     * @param  array<int, string>  $pricingSchemeVersionIdsBinary
     * @return Collection<string, array<int, array<string, mixed>>> Keyed by hex scheme_version id.
     */
    public function rulesForSchemeVersions(array $pricingSchemeVersionIdsBinary): Collection
    {
        if ($pricingSchemeVersionIdsBinary === []) {
            return collect();
        }

        $rules = DB::table('pricing_rules')
            ->whereIn('pricing_scheme_version_id', $pricingSchemeVersionIdsBinary)
            ->get([
                'id', 'pricing_scheme_version_id', 'rule_code', 'label', 'priority', 'effect_type', 'effect_amount',
                'effect_subject_type', 'effect_subject_service_option_id',
                'tier_calculation_mode', 'stop_processing',
            ]);

        if ($rules->isEmpty()) {
            return collect();
        }

        $ruleIds = $rules->pluck('id')->all();

        $groupsByRuleId = DB::table('pricing_rule_condition_groups')
            ->whereIn('pricing_rule_id', $ruleIds)
            ->orderBy('group_order')
            ->get(['id', 'pricing_rule_id'])
            ->groupBy(fn ($row) => bin2hex($row->pricing_rule_id));

        $groupIds = $groupsByRuleId->flatten(1)->pluck('id')->all();

        $conditionsByGroupId = $this->loadConditions($groupIds);

        $tiersByRuleId = DB::table('pricing_rule_tiers')
            ->whereIn('pricing_rule_id', $ruleIds)
            ->orderBy('tier_order')
            ->get(['pricing_rule_id', 'tier_order', 'from_unit', 'to_unit', 'charge_unit_size', 'rate_amount', 'tier_pricing_mode'])
            ->groupBy(fn ($row) => bin2hex($row->pricing_rule_id));

        return $rules->map(function ($rule) use ($groupsByRuleId, $conditionsByGroupId, $tiersByRuleId) {
            $ruleKey = bin2hex($rule->id);

            $groups = ($groupsByRuleId->get($ruleKey) ?? collect())
                ->map(fn ($group) => [
                    'conditions' => $conditionsByGroupId->get(bin2hex($group->id)) ?? [],
                ])
                ->values()
                ->all();

            $tiers = ($tiersByRuleId->get($ruleKey) ?? collect())
                ->map(fn ($tier) => [
                    'tier_order' => (int) $tier->tier_order,
                    'from_unit' => (string) $tier->from_unit,
                    'to_unit' => $tier->to_unit === null ? null : (string) $tier->to_unit,
                    'charge_unit_size' => (string) $tier->charge_unit_size,
                    'rate_amount' => (string) $tier->rate_amount,
                    'tier_pricing_mode' => $tier->tier_pricing_mode,
                ])
                ->values()
                ->all();

            return [
                'scheme_version_key' => bin2hex($rule->pricing_scheme_version_id),
                'rule' => [
                    'id' => UuidBinary::toString($rule->id),
                    'rule_code' => $rule->rule_code,
                    'label' => $rule->label,
                    'priority' => (int) $rule->priority,
                    'effect_type' => $rule->effect_type,
                    'effect_amount' => $rule->effect_amount === null ? null : (string) $rule->effect_amount,
                    'effect_subject_type' => $rule->effect_subject_type,
                    'effect_subject_service_option_id' => $rule->effect_subject_service_option_id === null
                        ? null
                        : UuidBinary::toString($rule->effect_subject_service_option_id),
                    'tier_calculation_mode' => $rule->tier_calculation_mode,
                    'stop_processing' => (bool) $rule->stop_processing,
                    'condition_groups' => $groups,
                    'tiers' => $tiers,
                ],
            ];
        })
            ->groupBy('scheme_version_key')
            ->map(fn ($rows) => $rows->pluck('rule')->all());
    }

    /**
     * @param  array<int, string>  $groupIds  Binary group ids.
     * @return Collection<string, array<int, array<string, mixed>>> Keyed by hex group id.
     */
    private function loadConditions(array $groupIds): Collection
    {
        if ($groupIds === []) {
            return collect();
        }

        $conditions = DB::table('pricing_rule_conditions')
            ->leftJoin('pricing_context_attributes', 'pricing_context_attributes.id', '=', 'pricing_rule_conditions.context_attribute_id')
            ->whereIn('pricing_rule_conditions.pricing_rule_condition_group_id', $groupIds)
            ->get([
                'pricing_rule_conditions.id',
                'pricing_rule_conditions.pricing_rule_condition_group_id',
                'pricing_rule_conditions.subject_type',
                'pricing_rule_conditions.service_option_id',
                'pricing_rule_conditions.operator',
                'pricing_rule_conditions.value_number',
                'pricing_rule_conditions.value_number_high',
                'pricing_rule_conditions.value_boolean',
                'pricing_rule_conditions.value_choice_id',
                'pricing_context_attributes.code as context_attribute_code',
            ]);

        $conditionIds = $conditions->pluck('id')->all();

        $valuesByConditionId = $this->loadConditionValues($conditionIds);

        return $conditions
            ->map(fn ($condition) => [
                'group_key' => bin2hex($condition->pricing_rule_condition_group_id),
                'condition' => [
                    'subject_type' => $condition->subject_type,
                    'service_option_id' => $condition->service_option_id === null ? null : UuidBinary::toString($condition->service_option_id),
                    'context_attribute_code' => $condition->context_attribute_code,
                    'operator' => $condition->operator,
                    'value_number' => $condition->value_number === null ? null : (string) $condition->value_number,
                    'value_number_high' => $condition->value_number_high === null ? null : (string) $condition->value_number_high,
                    'value_boolean' => $condition->value_boolean === null ? null : (bool) $condition->value_boolean,
                    'value_choice_id' => $condition->value_choice_id === null ? null : UuidBinary::toString($condition->value_choice_id),
                    'value_set' => $valuesByConditionId->get(bin2hex($condition->id)) ?? [],
                ],
            ])
            ->groupBy('group_key')
            ->map(fn ($rows) => $rows->pluck('condition')->all());
    }

    /**
     * @param  array<int, string>  $conditionIds  Binary condition ids.
     * @return Collection<string, array<int, string>> Keyed by hex condition id.
     */
    private function loadConditionValues(array $conditionIds): Collection
    {
        if ($conditionIds === []) {
            return collect();
        }

        return DB::table('pricing_rule_condition_values')
            ->whereIn('pricing_rule_condition_id', $conditionIds)
            ->orderBy('sort_order')
            ->get(['pricing_rule_condition_id', 'value_number', 'value_choice_id'])
            ->groupBy(fn ($row) => bin2hex($row->pricing_rule_condition_id))
            ->map(fn ($rows) => $rows
                ->map(fn ($row) => $row->value_choice_id !== null
                    ? UuidBinary::toString($row->value_choice_id)
                    : (string) $row->value_number)
                ->all());
    }
}
