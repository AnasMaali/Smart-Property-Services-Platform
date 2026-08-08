<?php

namespace App\Support\Pricing;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reusable publish-time validation + the atomic publish transaction itself,
 * built now even though no Admin publishing endpoint exists yet (item 9 of
 * the approved architecture). Every check here is either impossible to
 * express as a plain CHECK/UNIQUE constraint (cross-row tier overlap,
 * cross-table same-service references, cross-version effective-period
 * overlap) or is re-verified here for a clear, aggregated error list rather
 * than a single opaque MySQL constraint-violation message.
 */
final class SchemePublishValidator
{
    /**
     * @return array<int, string> Validation errors; empty array means the draft is publishable.
     */
    public function validate(string $pricingSchemeVersionIdBinary): array
    {
        $version = DB::table('pricing_scheme_versions')->where('id', $pricingSchemeVersionIdBinary)->first();

        if ($version === null) {
            return ['Pricing scheme version not found.'];
        }

        $errors = [];

        $rules = DB::table('pricing_rules')
            ->where('pricing_scheme_version_id', $pricingSchemeVersionIdBinary)
            ->get();

        if ($rules->isEmpty()) {
            return ['A pricing scheme version must have at least one rule.'];
        }

        $errors = array_merge($errors, $this->checkDuplicatePriorities($rules));
        $errors = array_merge($errors, $this->checkCrossServiceReferences($version->service_id, $rules));
        $errors = array_merge($errors, $this->checkConditionShapes($rules));
        $errors = array_merge($errors, $this->checkTierConfigurations($rules));
        $errors = array_merge($errors, $this->checkQuoteRequiredStopsProcessing($rules));

        return $errors;
    }

    /**
     * Atomically publishes a validated DRAFT scheme version: locks every
     * PUBLISHED version for the same service+currency, rejects any overlap
     * with the requested [effectiveFrom, effectiveTo) interval (including
     * finite-date overlaps, not only duplicate open-ended versions), then
     * flips this version to PUBLISHED. Throws on any validation failure or
     * overlap - never publishes a partially-valid or overlapping scheme.
     */
    public function publish(string $pricingSchemeVersionIdBinary, Carbon $effectiveFrom, ?Carbon $effectiveTo = null): void
    {
        $errors = $this->validate($pricingSchemeVersionIdBinary);

        if ($errors !== []) {
            throw new RuntimeException('Cannot publish pricing scheme version: '.implode(' | ', $errors));
        }

        DB::transaction(function () use ($pricingSchemeVersionIdBinary, $effectiveFrom, $effectiveTo) {
            $version = DB::table('pricing_scheme_versions')
                ->where('id', $pricingSchemeVersionIdBinary)
                ->lockForUpdate()
                ->first();

            $published = DB::table('pricing_scheme_versions')
                ->where('service_id', $version->service_id)
                ->where('currency_id', $version->currency_id)
                ->where('status', 'PUBLISHED')
                ->lockForUpdate()
                ->get(['id', 'effective_from', 'effective_to']);

            foreach ($published as $existing) {
                if ($this->periodsOverlap(
                    $effectiveFrom, $effectiveTo,
                    Carbon::parse($existing->effective_from),
                    $existing->effective_to === null ? null : Carbon::parse($existing->effective_to),
                )) {
                    throw new RuntimeException('Cannot publish: effective period overlaps an existing PUBLISHED scheme version for this service and currency.');
                }
            }

            DB::table('pricing_scheme_versions')
                ->where('id', $pricingSchemeVersionIdBinary)
                ->update([
                    'status' => 'PUBLISHED',
                    'effective_from' => $effectiveFrom,
                    'effective_to' => $effectiveTo,
                    'published_at' => now(),
                    'updated_at' => now(),
                ]);
        });
    }

    private function periodsOverlap(Carbon $aFrom, ?Carbon $aTo, Carbon $bFrom, ?Carbon $bTo): bool
    {
        $aStartsBeforeBEnds = $bTo === null || $aFrom->lessThan($bTo);
        $bStartsBeforeAEnds = $aTo === null || $bFrom->lessThan($aTo);

        return $aStartsBeforeBEnds && $bStartsBeforeAEnds;
    }

    /**
     * @return array<int, string>
     */
    private function checkDuplicatePriorities(Collection $rules): array
    {
        $duplicates = $rules->countBy('priority')->filter(fn ($count) => $count > 1);

        return $duplicates->keys()->map(fn ($priority) => "Duplicate priority [{$priority}] used by more than one rule.")->all();
    }

    /**
     * @return array<int, string>
     */
    private function checkCrossServiceReferences(string $serviceIdBinary, Collection $rules): array
    {
        $referencedOptionIds = $rules->pluck('effect_subject_service_option_id')->filter()->all();

        $conditionOptionIds = DB::table('pricing_rule_conditions')
            ->whereIn('pricing_rule_condition_group_id', DB::table('pricing_rule_condition_groups')
                ->whereIn('pricing_rule_id', $rules->pluck('id'))
                ->pluck('id'))
            ->whereNotNull('service_option_id')
            ->pluck('service_option_id')
            ->all();

        $allOptionIds = array_unique(array_merge($referencedOptionIds, $conditionOptionIds), SORT_REGULAR);

        if ($allOptionIds === []) {
            return [];
        }

        $invalidCount = DB::table('service_options')
            ->whereIn('id', $allOptionIds)
            ->where('service_id', '<>', $serviceIdBinary)
            ->count();

        return $invalidCount > 0
            ? ["{$invalidCount} rule condition(s)/effect(s) reference a service_option that does not belong to this scheme's service."]
            : [];
    }

    /**
     * @return array<int, string>
     */
    private function checkConditionShapes(Collection $rules): array
    {
        $errors = [];

        $groups = DB::table('pricing_rule_condition_groups')->whereIn('pricing_rule_id', $rules->pluck('id'))->get();
        $conditions = DB::table('pricing_rule_conditions')->whereIn('pricing_rule_condition_group_id', $groups->pluck('id'))->get();

        foreach ($conditions as $condition) {
            $operator = $condition->operator;
            $needsValueSet = in_array($operator, ['IN', 'NOT_IN'], true);

            if ($needsValueSet) {
                $count = DB::table('pricing_rule_condition_values')->where('pricing_rule_condition_id', $condition->id)->count();

                if ($count === 0) {
                    $errors[] = "Condition [{$condition->id}] uses {$operator} but has no condition values.";
                }
            }

            $isOrderedNumeric = in_array($operator, ['GT', 'GTE', 'LT', 'LTE', 'EQ', 'NEQ'], true);
            $isNumericSubject = in_array($condition->subject_type, ['OPTION_NUMERIC_VALUE', 'ITEM_QUANTITY', 'CONTEXT_ATTRIBUTE'], true);

            if ($isOrderedNumeric && $isNumericSubject && $condition->value_number === null) {
                $errors[] = "Condition [{$condition->id}] requires value_number for operator {$operator}.";
            }
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    private function checkTierConfigurations(Collection $rules): array
    {
        $errors = [];

        $perUnitRules = $rules->where('effect_type', 'ADD_PER_UNIT');

        foreach ($perUnitRules as $rule) {
            $tiers = DB::table('pricing_rule_tiers')
                ->where('pricing_rule_id', $rule->id)
                ->orderBy('tier_order')
                ->get();

            if ($tiers->isEmpty()) {
                $errors[] = "Rule [{$rule->rule_code}] is ADD_PER_UNIT but has no tiers.";

                continue;
            }

            if ($rule->tier_calculation_mode === 'GRADUATED' && $tiers->contains('tier_pricing_mode', 'FLAT')) {
                $errors[] = "Rule [{$rule->rule_code}] combines GRADUATED with a FLAT tier, which has no defined semantic.";
            }

            $expectedFrom = '0.000000';
            $previous = null;

            foreach ($tiers as $tier) {
                if (bccomp((string) $tier->from_unit, $expectedFrom, 6) !== 0) {
                    $errors[] = "Rule [{$rule->rule_code}] has a tier gap or overlap starting at [{$tier->from_unit}].";
                }

                if ($previous !== null && $previous->to_unit === null) {
                    $errors[] = "Rule [{$rule->rule_code}] has a tier after an open-ended final tier.";
                }

                $expectedFrom = (string) ($tier->to_unit ?? $tier->from_unit);
                $previous = $tier;
            }
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    private function checkQuoteRequiredStopsProcessing(Collection $rules): array
    {
        return $rules
            ->where('effect_type', 'QUOTE_REQUIRED')
            ->where('stop_processing', 0)
            ->map(fn ($rule) => "Rule [{$rule->rule_code}] is QUOTE_REQUIRED and must have stop_processing enabled.")
            ->values()
            ->all();
    }
}
