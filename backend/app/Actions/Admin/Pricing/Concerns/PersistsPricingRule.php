<?php

namespace App\Actions\Admin\Pricing\Concerns;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * Field-level shape validation + the raw insert of one `pricing_rules` row
 * (with its condition groups/conditions/tiers) - extracted from the
 * original App\Actions\Admin\Pricing\AdminCreatePricingRuleAction so
 * App\Actions\Admin\Pricing\AdminUpdatePricingRuleAction can share the exact
 * same shape rules and insert path rather than duplicating them. Mirrors
 * only the literal `pricing_rules`/`pricing_rule_conditions` CHECK
 * constraints - never a cross-row publish-readiness check (that remains
 * App\Support\Pricing\SchemePublishValidator's exclusive job).
 */
trait PersistsPricingRule
{
    private const AMOUNT_REQUIRED_TYPES = ['SET_PRICE', 'ADD_FIXED', 'MULTIPLY', 'MIN_TOTAL', 'MAX_TOTAL'];

    private const AMOUNT_FORBIDDEN_TYPES = ['ADD_PER_UNIT', 'QUOTE_REQUIRED'];

    private const OPTION_SUBJECT_TYPES = ['OPTION_CHOICE', 'OPTION_NUMERIC_VALUE', 'OPTION_BOOLEAN_VALUE'];

    /**
     * Inserts one `pricing_rules` row plus its condition groups/conditions/
     * condition values/tiers under the given (already-generated) rule UUID.
     * Callers are responsible for locking/validating the parent scheme
     * version first and for calling validateRuleShape() before this.
     */
    private function persistRule(string $ruleUuid, string $versionIdBinary, array $payload, $now): void
    {
        $isPerUnit = $payload['effect_type'] === 'ADD_PER_UNIT';

        DB::table('pricing_rules')->insert([
            'id' => UuidBinary::toBinary($ruleUuid),
            'pricing_scheme_version_id' => $versionIdBinary,
            'rule_code' => $payload['rule_code'],
            'label' => $payload['label'],
            'priority' => $payload['priority'],
            'effect_type' => $payload['effect_type'],
            'effect_amount' => $payload['effect_amount'] ?? null,
            'effect_subject_type' => $isPerUnit ? 'OPTION_NUMERIC_VALUE' : null,
            'effect_subject_service_option_id' => $isPerUnit ? UuidBinary::toBinary($payload['effect_subject_service_option_id']) : null,
            'tier_calculation_mode' => $payload['tier_calculation_mode'] ?? null,
            'stop_processing' => $payload['stop_processing'] ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($payload['condition_groups'] ?? [] as $groupOrder => $group) {
            $this->insertConditionGroup($ruleUuid, $groupOrder, $group, $now);
        }

        foreach ($payload['tiers'] ?? [] as $tier) {
            DB::table('pricing_rule_tiers')->insert([
                'id' => UuidBinary::toBinary(UuidBinary::generate()),
                'pricing_rule_id' => UuidBinary::toBinary($ruleUuid),
                'tier_order' => $tier['tier_order'],
                'from_unit' => $tier['from_unit'],
                'to_unit' => $tier['to_unit'] ?? null,
                'charge_unit_size' => $tier['charge_unit_size'] ?? '1.000000',
                'rate_amount' => $tier['rate_amount'],
                'tier_pricing_mode' => $tier['tier_pricing_mode'],
                'created_at' => $now,
            ]);
        }
    }

    private function insertConditionGroup(string $ruleUuid, int $groupOrder, array $group, $now): void
    {
        $groupUuid = UuidBinary::generate();

        DB::table('pricing_rule_condition_groups')->insert([
            'id' => UuidBinary::toBinary($groupUuid),
            'pricing_rule_id' => UuidBinary::toBinary($ruleUuid),
            'group_order' => $groupOrder,
            'created_at' => $now,
        ]);

        foreach ($group['conditions'] as $condition) {
            $conditionUuid = UuidBinary::generate();
            $contextAttributeId = null;

            if ($condition['subject_type'] === 'CONTEXT_ATTRIBUTE') {
                $contextAttributeId = DB::table('pricing_context_attributes')->where('code', $condition['context_attribute_code'])->value('id');
            }

            DB::table('pricing_rule_conditions')->insert([
                'id' => UuidBinary::toBinary($conditionUuid),
                'pricing_rule_condition_group_id' => UuidBinary::toBinary($groupUuid),
                'subject_type' => $condition['subject_type'],
                'service_option_id' => isset($condition['service_option_id']) ? UuidBinary::toBinary($condition['service_option_id']) : null,
                'context_attribute_id' => $contextAttributeId,
                'operator' => $condition['operator'],
                'value_number' => $condition['value_number'] ?? null,
                'value_number_high' => $condition['value_number_high'] ?? null,
                'value_boolean' => array_key_exists('value_boolean', $condition) && $condition['value_boolean'] !== null ? ($condition['value_boolean'] ? 1 : 0) : null,
                'value_choice_id' => isset($condition['value_choice_id']) ? UuidBinary::toBinary($condition['value_choice_id']) : null,
                'created_at' => $now,
            ]);

            foreach ($condition['value_set'] ?? [] as $sortOrder => $value) {
                $isChoice = $condition['subject_type'] === 'OPTION_CHOICE';

                DB::table('pricing_rule_condition_values')->insert([
                    'pricing_rule_condition_id' => UuidBinary::toBinary($conditionUuid),
                    'sort_order' => $sortOrder,
                    'value_number' => $isChoice ? null : $value,
                    'value_choice_id' => $isChoice ? UuidBinary::toBinary($value) : null,
                ]);
            }
        }
    }

    /**
     * Mirrors, field-by-field, the exact `pricing_rules`/
     * `pricing_rule_conditions` CHECK constraints - never a cross-row check
     * (those are SchemePublishValidator's job).
     *
     * @return array<int, string>
     */
    private function validateRuleShape(array $payload, string $serviceIdBinary): array
    {
        $errors = [];
        $effectType = $payload['effect_type'];
        $isPerUnit = $effectType === 'ADD_PER_UNIT';

        if (in_array($effectType, self::AMOUNT_REQUIRED_TYPES, true) && ! isset($payload['effect_amount'])) {
            $errors[] = "effect_type {$effectType} requires effect_amount.";
        }

        if (in_array($effectType, self::AMOUNT_FORBIDDEN_TYPES, true) && isset($payload['effect_amount'])) {
            $errors[] = "effect_type {$effectType} must not have effect_amount.";
        }

        if ($isPerUnit) {
            if (! isset($payload['effect_subject_service_option_id'])) {
                $errors[] = 'ADD_PER_UNIT requires effect_subject_service_option_id.';
            } elseif (! DB::table('service_options')->where('id', UuidBinary::toBinary($payload['effect_subject_service_option_id']))->exists()) {
                $errors[] = 'effect_subject_service_option_id does not reference an existing service option.';
            }

            if (! isset($payload['tier_calculation_mode'])) {
                $errors[] = 'ADD_PER_UNIT requires tier_calculation_mode.';
            }

            if (($payload['tiers'] ?? []) === []) {
                $errors[] = 'ADD_PER_UNIT requires at least one tier.';
            }
        } else {
            if (isset($payload['effect_subject_service_option_id'])) {
                $errors[] = 'effect_subject_service_option_id is only valid for ADD_PER_UNIT.';
            }

            if (isset($payload['tier_calculation_mode'])) {
                $errors[] = 'tier_calculation_mode is only valid for ADD_PER_UNIT.';
            }
        }

        if ($effectType === 'QUOTE_REQUIRED' && ! $payload['stop_processing']) {
            $errors[] = 'QUOTE_REQUIRED requires stop_processing to be true.';
        }

        foreach ($payload['condition_groups'] ?? [] as $group) {
            foreach ($group['conditions'] as $condition) {
                $errors = array_merge($errors, $this->validateConditionShape($condition, $serviceIdBinary));
            }
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    private function validateConditionShape(array $condition, string $serviceIdBinary): array
    {
        $errors = [];
        $subjectType = $condition['subject_type'];
        $operator = $condition['operator'];
        $hasOption = isset($condition['service_option_id']);
        $hasContext = isset($condition['context_attribute_code']);

        if (in_array($subjectType, self::OPTION_SUBJECT_TYPES, true) !== $hasOption) {
            $errors[] = "subject_type {$subjectType} requires service_option_id exactly when it is an OPTION_* subject.";
        }

        if ($hasOption && ! DB::table('service_options')->where('id', UuidBinary::toBinary($condition['service_option_id']))->where('service_id', $serviceIdBinary)->exists()) {
            $errors[] = 'service_option_id does not belong to this scheme\'s service.';
        }

        if (($subjectType === 'CONTEXT_ATTRIBUTE') !== $hasContext) {
            $errors[] = 'context_attribute_code is required exactly when subject_type is CONTEXT_ATTRIBUTE.';
        }

        if ($hasContext && ! DB::table('pricing_context_attributes')->where('code', $condition['context_attribute_code'])->where('is_active', 1)->exists()) {
            $errors[] = "context_attribute_code [{$condition['context_attribute_code']}] does not reference an active pricing context attribute.";
        }

        if ($operator === 'BETWEEN' && (! isset($condition['value_number']) || ! isset($condition['value_number_high']) || bccomp((string) $condition['value_number_high'], (string) $condition['value_number'], 6) <= 0)) {
            $errors[] = 'BETWEEN requires value_number and a strictly greater value_number_high.';
        }

        if ($subjectType === 'OPTION_BOOLEAN_VALUE' && ! in_array($operator, ['EQ', 'NEQ'], true)) {
            $errors[] = 'OPTION_BOOLEAN_VALUE only supports the EQ/NEQ operators.';
        }

        if ($subjectType === 'OPTION_CHOICE' && ! in_array($operator, ['EQ', 'NEQ', 'IN', 'NOT_IN'], true)) {
            $errors[] = 'OPTION_CHOICE only supports the EQ/NEQ/IN/NOT_IN operators.';
        }

        if (isset($condition['value_choice_id']) && $subjectType !== 'OPTION_CHOICE') {
            $errors[] = 'value_choice_id is only valid for an OPTION_CHOICE condition.';
        }

        if (isset($condition['value_choice_id']) && ! DB::table('service_option_choices')->where('id', UuidBinary::toBinary($condition['value_choice_id']))->exists()) {
            $errors[] = 'value_choice_id does not reference an existing service option choice.';
        }

        return $errors;
    }
}
