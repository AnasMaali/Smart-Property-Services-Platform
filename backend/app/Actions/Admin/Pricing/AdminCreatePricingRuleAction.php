<?php

namespace App\Actions\Admin\Pricing;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminPricingSchemePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates one DRAFT `pricing_rules` row (with its condition groups/
 * conditions/tiers) atomically (BLUE V1 Phase B9). Only ever operates on a
 * DRAFT `pricing_scheme_versions` row - PUBLISHED/RETIRED versions are
 * immutable, matching the "Published v1 -> create new DRAFT v2 -> edit v2"
 * workflow the schema itself implies (a PUBLISHED version's rules feed live
 * customer price calculations; nothing may rewrite them in place).
 *
 * Validates only the field-level shape/CHECK-constraint-mirroring rules
 * (via CreateAdminPricingRuleRequest) plus lightweight FK-existence checks
 * here. Deliberately does NOT reproduce App\Support\Pricing\
 * SchemePublishValidator's cross-row publish-readiness checks (duplicate
 * priorities within the version, cross-service option references, tier
 * sequence coverage, condition-value completeness) - a DRAFT rule may be
 * saved before it is fully publish-ready; SchemePublishValidator remains
 * the single, authoritative gate for "is this scheme safe to go live",
 * enforced again (never skipped) when AdminPublishPricingSchemeAction runs.
 *
 * There is no update-rule endpoint: editing a DRAFT rule is delete +
 * recreate (AdminDeletePricingRuleAction then this Action again) - this
 * avoids inventing partial-update semantics for a rule's nested condition/
 * tier structure that no existing code establishes.
 */
final class AdminCreatePricingRuleAction
{
    use BuildsCartResult;

    private const AMOUNT_REQUIRED_TYPES = ['SET_PRICE', 'ADD_FIXED', 'MULTIPLY', 'MIN_TOTAL', 'MAX_TOTAL'];

    private const AMOUNT_FORBIDDEN_TYPES = ['ADD_PER_UNIT', 'QUOTE_REQUIRED'];

    private const OPTION_SUBJECT_TYPES = ['OPTION_CHOICE', 'OPTION_NUMERIC_VALUE', 'OPTION_BOOLEAN_VALUE'];

    public function handle(Request $request, User $actor, string $schemeUuid, array $payload): array
    {
        try {
            $versionIdBinary = UuidBinary::toBinary($schemeUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Pricing scheme version not found.');
        }

        return DB::transaction(function () use ($request, $actor, $schemeUuid, $versionIdBinary, $payload): array {
            $version = DB::table('pricing_scheme_versions')->where('id', $versionIdBinary)->lockForUpdate()->first();

            if ($version === null) {
                return $this->notFound('Pricing scheme version not found.');
            }

            if ($version->status !== 'DRAFT') {
                return $this->conflict('Only a DRAFT pricing scheme version may have rules added.');
            }

            $errors = $this->validateRuleShape($payload, $version->service_id);

            if ($errors !== []) {
                return $this->unprocessable('This rule cannot be saved.', $errors);
            }

            if (DB::table('pricing_rules')->where('pricing_scheme_version_id', $versionIdBinary)->where('rule_code', $payload['rule_code'])->exists()) {
                return $this->conflict('A rule with this rule_code already exists on this scheme version.');
            }

            if (DB::table('pricing_rules')->where('pricing_scheme_version_id', $versionIdBinary)->where('priority', $payload['priority'])->exists()) {
                return $this->conflict('A rule with this priority already exists on this scheme version.');
            }

            $ruleUuid = UuidBinary::generate();
            $now = now();
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

            AdminAuditLogger::record(
                $request,
                $actor,
                'PRICING_RULE_CREATED',
                'PRICING_SCHEME_VERSION',
                $schemeUuid,
                ['rule_uuid' => $ruleUuid, 'rule_code' => $payload['rule_code'], 'effect_type' => $payload['effect_type']],
            );

            $updated = AdminGetPricingSchemeAction::loadForDetail($schemeUuid);

            return $this->ok(201, 'Pricing rule created successfully.', ['pricing_scheme' => AdminPricingSchemePresenter::detail($updated)]);
        });
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
