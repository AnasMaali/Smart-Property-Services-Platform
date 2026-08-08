<?php

namespace App\Support\Pricing;

use InvalidArgumentException;

/**
 * The pure, deterministic calculation core of the flexible pricing engine.
 *
 * Deliberately has zero database/framework dependency: it accepts already
 * -loaded rule/condition/tier definitions as plain arrays (see the shapes
 * documented on {@see evaluate()}) and returns one authoritative
 * PricingResult. This is what makes the engine's arithmetic fully unit
 * -testable without a live schema, and what guarantees every caller (service
 * -details preview, cart add/update/get, checkout, booking snapshot) shares
 * the exact same calculation - never a second algorithm.
 *
 * Money is handled exclusively through bcmath (scale 6) to match the
 * database's decimal(19,6) columns; float arithmetic is never used.
 */
final class PricingRuleEvaluator
{
    private const SCALE = 6;

    /**
     * @param  array<int, array<string, mixed>>  $rules  Ordered/unordered rule definitions; sorted by priority here.
     * @param  array<string, array<string, mixed>>  $selections  Keyed by service_option_id.
     * @param  array<string, string>  $context  Resolved context attribute values, keyed by attribute code.
     * @param  array<int, string>  $knownContextAttributeCodes  Every CONTEXT_ATTRIBUTE code referenced anywhere in $rules; used to compute required_context deterministically without guessing.
     */
    public function evaluate(
        array $rules,
        array $selections,
        int $quantity,
        array $context,
        string $currencyCode,
        ?string $pricingSchemeVersion,
    ): PricingResult {
        $rules = $this->sortByPriority($rules);

        $missingContext = $this->missingContextCodes($rules, $context);

        if ($missingContext !== []) {
            return new PricingResult(
                status: PricingStatus::MISSING_CONTEXT,
                currencyCode: $currencyCode,
                pricingSchemeVersion: $pricingSchemeVersion,
                baseAmount: null,
                adjustments: [],
                unitTotal: null,
                quantity: $quantity,
                lineTotal: null,
                requiredContext: $missingContext,
            );
        }

        $runningTotal = '0';
        $baseAmount = null;
        $adjustments = [];
        $quoteRequired = false;

        foreach ($rules as $rule) {
            if (! $this->ruleFires($rule, $selections, $quantity, $context)) {
                continue;
            }

            $effectType = EffectType::from($rule['effect_type']);
            $amountOrFactor = null;

            switch ($effectType) {
                case EffectType::SET_PRICE:
                    $runningTotal = $this->normalize($rule['effect_amount']);
                    $baseAmount ??= $runningTotal;
                    $amountOrFactor = $runningTotal;
                    break;

                case EffectType::ADD_FIXED:
                    $amountOrFactor = $this->normalize($rule['effect_amount']);
                    $runningTotal = bcadd($runningTotal, $amountOrFactor, self::SCALE);
                    break;

                case EffectType::ADD_PER_UNIT:
                    $value = $this->numericOptionValue($rule['effect_subject_service_option_id'], $selections);

                    if ($value === null) {
                        // Measurable input not selected for this cart item - the rule simply does not apply.
                        continue 2;
                    }

                    $tierAmount = $this->computeTieredAmount($rule['tiers'] ?? [], $rule['tier_calculation_mode'], $value);

                    if ($tierAmount === null) {
                        // No configured tier covers this value - never guess a price.
                        return new PricingResult(
                            status: PricingStatus::UNAVAILABLE,
                            currencyCode: $currencyCode,
                            pricingSchemeVersion: $pricingSchemeVersion,
                            baseAmount: $baseAmount,
                            adjustments: $adjustments,
                            unitTotal: null,
                            quantity: $quantity,
                            lineTotal: null,
                        );
                    }

                    $amountOrFactor = $tierAmount;
                    $runningTotal = bcadd($runningTotal, $amountOrFactor, self::SCALE);
                    break;

                case EffectType::MULTIPLY:
                    $amountOrFactor = $this->normalize($rule['effect_amount']);
                    $runningTotal = bcmul($runningTotal, $amountOrFactor, self::SCALE);
                    break;

                case EffectType::MIN_TOTAL:
                    $amountOrFactor = $this->normalize($rule['effect_amount']);
                    if (bccomp($runningTotal, $amountOrFactor, self::SCALE) < 0) {
                        $runningTotal = $amountOrFactor;
                    }
                    break;

                case EffectType::MAX_TOTAL:
                    $amountOrFactor = $this->normalize($rule['effect_amount']);
                    if (bccomp($runningTotal, $amountOrFactor, self::SCALE) > 0) {
                        $runningTotal = $amountOrFactor;
                    }
                    break;

                case EffectType::QUOTE_REQUIRED:
                    $quoteRequired = true;
                    break;
            }

            $adjustments[] = new PricingAdjustment(
                ruleCode: $rule['rule_code'],
                label: $rule['label'],
                effectType: $effectType,
                amountOrFactor: $amountOrFactor,
                runningTotalAfter: $quoteRequired ? null : $runningTotal,
            );

            if ($rule['stop_processing']) {
                break;
            }
        }

        if ($quoteRequired) {
            return new PricingResult(
                status: PricingStatus::QUOTE_REQUIRED,
                currencyCode: $currencyCode,
                pricingSchemeVersion: $pricingSchemeVersion,
                baseAmount: $baseAmount,
                adjustments: $adjustments,
                unitTotal: null,
                quantity: $quantity,
                lineTotal: null,
            );
        }

        if ($adjustments === []) {
            return new PricingResult(
                status: PricingStatus::UNAVAILABLE,
                currencyCode: $currencyCode,
                pricingSchemeVersion: $pricingSchemeVersion,
                baseAmount: null,
                adjustments: [],
                unitTotal: null,
                quantity: $quantity,
                lineTotal: null,
            );
        }

        $lineTotal = bcmul($runningTotal, (string) $quantity, self::SCALE);

        return new PricingResult(
            status: PricingStatus::PRICED,
            currencyCode: $currencyCode,
            pricingSchemeVersion: $pricingSchemeVersion,
            baseAmount: $baseAmount,
            adjustments: $adjustments,
            unitTotal: $runningTotal,
            quantity: $quantity,
            lineTotal: $lineTotal,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<int, array<string, mixed>>
     */
    private function sortByPriority(array $rules): array
    {
        usort($rules, fn (array $a, array $b) => $a['priority'] <=> $b['priority']);

        return $rules;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @param  array<string, string>  $context
     * @return array<int, string>
     */
    private function missingContextCodes(array $rules, array $context): array
    {
        $required = [];

        foreach ($rules as $rule) {
            foreach ($rule['condition_groups'] ?? [] as $group) {
                foreach ($group['conditions'] ?? [] as $condition) {
                    if ($condition['subject_type'] === ConditionSubjectType::CONTEXT_ATTRIBUTE->value) {
                        $required[$condition['context_attribute_code']] = true;
                    }
                }
            }
        }

        $missing = array_keys(array_diff_key($required, $context));
        sort($missing);

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, array<string, mixed>>  $selections
     * @param  array<string, string>  $context
     */
    private function ruleFires(array $rule, array $selections, int $quantity, array $context): bool
    {
        $groups = $rule['condition_groups'] ?? [];

        if ($groups === []) {
            return true;
        }

        foreach ($groups as $group) {
            if ($this->groupMatches($group['conditions'] ?? [], $selections, $quantity, $context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $conditions
     * @param  array<string, array<string, mixed>>  $selections
     * @param  array<string, string>  $context
     */
    private function groupMatches(array $conditions, array $selections, int $quantity, array $context): bool
    {
        foreach ($conditions as $condition) {
            if (! $this->conditionMatches($condition, $selections, $quantity, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, array<string, mixed>>  $selections
     * @param  array<string, string>  $context
     */
    private function conditionMatches(array $condition, array $selections, int $quantity, array $context): bool
    {
        $subjectType = ConditionSubjectType::from($condition['subject_type']);
        $operator = ConditionOperator::from($condition['operator']);

        return match ($subjectType) {
            ConditionSubjectType::OPTION_CHOICE => $this->matchChoice($condition, $operator, $selections),
            ConditionSubjectType::OPTION_NUMERIC_VALUE => $this->matchNumeric($condition, $operator, $this->optionNumericValue($condition['service_option_id'], $selections)),
            ConditionSubjectType::OPTION_BOOLEAN_VALUE => $this->matchBoolean($condition, $operator, $this->optionBooleanValue($condition['service_option_id'], $selections)),
            ConditionSubjectType::ITEM_QUANTITY => $this->matchNumeric($condition, $operator, (string) $quantity),
            ConditionSubjectType::CONTEXT_ATTRIBUTE => $this->matchNumeric($condition, $operator, $context[$condition['context_attribute_code']] ?? null),
        };
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, array<string, mixed>>  $selections
     */
    private function matchChoice(array $condition, ConditionOperator $operator, array $selections): bool
    {
        $selectedIds = $selections[$condition['service_option_id']]['choice_ids'] ?? [];

        return match ($operator) {
            ConditionOperator::EQ => in_array($condition['value_choice_id'], $selectedIds, true),
            ConditionOperator::NEQ => ! in_array($condition['value_choice_id'], $selectedIds, true),
            ConditionOperator::IN => count(array_intersect($condition['value_set'] ?? [], $selectedIds)) > 0,
            ConditionOperator::NOT_IN => count(array_intersect($condition['value_set'] ?? [], $selectedIds)) === 0,
            default => throw new InvalidArgumentException("Operator {$operator->value} is not valid for an OPTION_CHOICE condition."),
        };
    }

    private function matchNumeric(array $condition, ConditionOperator $operator, ?string $value): bool
    {
        if ($value === null) {
            // Never guess: an absent measurement never satisfies a numeric condition.
            return false;
        }

        return match ($operator) {
            ConditionOperator::EQ => bccomp($value, $condition['value_number'], self::SCALE) === 0,
            ConditionOperator::NEQ => bccomp($value, $condition['value_number'], self::SCALE) !== 0,
            ConditionOperator::GT => bccomp($value, $condition['value_number'], self::SCALE) > 0,
            ConditionOperator::GTE => bccomp($value, $condition['value_number'], self::SCALE) >= 0,
            ConditionOperator::LT => bccomp($value, $condition['value_number'], self::SCALE) < 0,
            ConditionOperator::LTE => bccomp($value, $condition['value_number'], self::SCALE) <= 0,
            ConditionOperator::BETWEEN => bccomp($value, $condition['value_number'], self::SCALE) >= 0
                && bccomp($value, $condition['value_number_high'], self::SCALE) <= 0,
            ConditionOperator::IN => in_array(0, array_map(
                fn (string $candidate) => bccomp($value, $candidate, self::SCALE),
                $condition['value_set'] ?? [],
            ), true),
            ConditionOperator::NOT_IN => ! in_array(0, array_map(
                fn (string $candidate) => bccomp($value, $candidate, self::SCALE),
                $condition['value_set'] ?? [],
            ), true),
        };
    }

    private function matchBoolean(array $condition, ConditionOperator $operator, ?bool $value): bool
    {
        if ($value === null) {
            return false;
        }

        return match ($operator) {
            ConditionOperator::EQ => $value === $condition['value_boolean'],
            ConditionOperator::NEQ => $value !== $condition['value_boolean'],
            default => throw new InvalidArgumentException("Operator {$operator->value} is not valid for an OPTION_BOOLEAN_VALUE condition."),
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $selections
     */
    private function optionNumericValue(?string $serviceOptionId, array $selections): ?string
    {
        if ($serviceOptionId === null) {
            return null;
        }

        $value = $selections[$serviceOptionId]['numeric_value'] ?? null;

        return $value === null ? null : $this->normalize($value);
    }

    /**
     * @param  array<string, array<string, mixed>>  $selections
     */
    private function optionBooleanValue(?string $serviceOptionId, array $selections): ?bool
    {
        if ($serviceOptionId === null) {
            return null;
        }

        return $selections[$serviceOptionId]['boolean_value'] ?? null;
    }

    /**
     * Alias used by the ADD_PER_UNIT effect path (always OPTION_NUMERIC_VALUE by construction).
     *
     * @param  array<string, array<string, mixed>>  $selections
     */
    private function numericOptionValue(?string $serviceOptionId, array $selections): ?string
    {
        return $this->optionNumericValue($serviceOptionId, $selections);
    }

    /**
     * Deterministic CEILING-based tier resolution. See TierCalculationMode/TierPricingMode
     * for the four supported combinations; GRADUATED+FLAT is rejected at publish time and
     * is treated defensively as "no tier resolves" here.
     *
     * @param  array<int, array<string, mixed>>  $tiers
     */
    private function computeTieredAmount(array $tiers, ?string $calculationMode, string $value): ?string
    {
        if ($tiers === [] || $calculationMode === null) {
            return null;
        }

        usort($tiers, fn (array $a, array $b) => $a['tier_order'] <=> $b['tier_order']);

        $mode = TierCalculationMode::from($calculationMode);

        return match ($mode) {
            TierCalculationMode::VOLUME => $this->computeVolumeAmount($tiers, $value),
            TierCalculationMode::GRADUATED => $this->computeGraduatedAmount($tiers, $value),
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $tiers
     */
    private function computeVolumeAmount(array $tiers, string $value): ?string
    {
        foreach ($tiers as $tier) {
            $withinLowerBound = bccomp($value, $tier['from_unit'], self::SCALE) >= 0;
            $withinUpperBound = $tier['to_unit'] === null || bccomp($value, $tier['to_unit'], self::SCALE) <= 0;

            if (! ($withinLowerBound && $withinUpperBound)) {
                continue;
            }

            $pricingMode = TierPricingMode::from($tier['tier_pricing_mode']);

            if ($pricingMode === TierPricingMode::FLAT) {
                return $this->normalize($tier['rate_amount']);
            }

            $billableUnits = $this->ceilDivide($value, $tier['charge_unit_size']);

            return bcmul($billableUnits, $tier['rate_amount'], self::SCALE);
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tiers
     */
    private function computeGraduatedAmount(array $tiers, string $value): ?string
    {
        $total = '0';

        foreach ($tiers as $tier) {
            if (TierPricingMode::from($tier['tier_pricing_mode']) !== TierPricingMode::PER_UNIT) {
                // GRADUATED + FLAT has no defined semantic; publish validation must reject it.
                return null;
            }

            $bandTop = $tier['to_unit'] === null
                ? $value
                : (bccomp($value, $tier['to_unit'], self::SCALE) < 0 ? $value : $tier['to_unit']);

            if (bccomp($bandTop, $tier['from_unit'], self::SCALE) <= 0) {
                continue;
            }

            $span = bcsub($bandTop, $tier['from_unit'], self::SCALE);
            $billableUnits = $this->ceilDivide($span, $tier['charge_unit_size']);
            $total = bcadd($total, bcmul($billableUnits, $tier['rate_amount'], self::SCALE), self::SCALE);
        }

        return $total;
    }

    /**
     * CEIL(numerator / denominator), computed via bcmath to stay exact for decimal(19,6) values.
     */
    private function ceilDivide(string $numerator, string $denominator): string
    {
        if (bccomp($denominator, '0', self::SCALE) === 0) {
            throw new InvalidArgumentException('charge_unit_size must be greater than zero.');
        }

        $quotient = bcdiv($numerator, $denominator, self::SCALE + 6);

        $truncated = bcadd($quotient, '0', 0);

        if (bccomp($truncated, $quotient, self::SCALE + 6) < 0) {
            $truncated = bcadd($truncated, '1', 0);
        }

        return bcadd($truncated, '0', self::SCALE);
    }

    private function normalize(string $amount): string
    {
        return bcadd($amount, '0', self::SCALE);
    }
}
