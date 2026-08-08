<?php

namespace Tests\Unit\Pricing;

use App\Support\Pricing\EffectType;
use App\Support\Pricing\PricingResult;
use App\Support\Pricing\PricingRuleEvaluator;
use App\Support\Pricing\PricingStatus;
use Tests\TestCase;

class PricingRuleEvaluatorTest extends TestCase
{
    private PricingRuleEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new PricingRuleEvaluator;
    }

    // ---- helpers -----------------------------------------------------

    private function rule(array $overrides = []): array
    {
        return array_merge([
            'rule_code' => 'RULE',
            'label' => 'Rule',
            'priority' => 100,
            'effect_type' => EffectType::ADD_FIXED->value,
            'effect_amount' => '0',
            'effect_subject_type' => null,
            'effect_subject_service_option_id' => null,
            'tier_calculation_mode' => null,
            'stop_processing' => false,
            'condition_groups' => [],
            'tiers' => [],
        ], $overrides);
    }

    private function group(array $conditions): array
    {
        return ['conditions' => $conditions];
    }

    private function condition(array $overrides = []): array
    {
        return array_merge([
            'subject_type' => 'OPTION_NUMERIC_VALUE',
            'service_option_id' => null,
            'context_attribute_code' => null,
            'operator' => 'EQ',
            'value_number' => null,
            'value_number_high' => null,
            'value_boolean' => null,
            'value_choice_id' => null,
            'value_set' => [],
        ], $overrides);
    }

    private function tier(array $overrides = []): array
    {
        return array_merge([
            'tier_order' => 1,
            'from_unit' => '0',
            'to_unit' => null,
            'charge_unit_size' => '1',
            'rate_amount' => '0',
            'tier_pricing_mode' => 'PER_UNIT',
        ], $overrides);
    }

    private function evaluate(array $rules, array $selections = [], int $quantity = 1, array $context = []): PricingResult
    {
        return $this->evaluator->evaluate($rules, $selections, $quantity, $context, 'AED', 'scheme-v1');
    }

    // ---- 1. fixed price ------------------------------------------------

    public function test_fixed_price(): void
    {
        $rules = [$this->rule(['rule_code' => 'BASE', 'effect_type' => EffectType::SET_PRICE->value, 'effect_amount' => '150'])];

        $result = $this->evaluate($rules);

        $this->assertSame(PricingStatus::PRICED, $result->status);
        $this->assertSame('150.000000', $result->unitTotal);
        $this->assertSame('150.000000', $result->baseAmount);
        $this->assertSame('150.000000', $result->lineTotal);
    }

    // ---- 2. conditional SET_PRICE --------------------------------------

    public function test_conditional_set_price_package(): void
    {
        $propertyOption = 'opt-property-type';
        $villaChoice = 'choice-villa';

        $rules = [
            $this->rule([
                'rule_code' => 'VILLA_PACKAGE',
                'effect_type' => EffectType::SET_PRICE->value,
                'effect_amount' => '2500',
                'condition_groups' => [
                    $this->group([
                        $this->condition(['subject_type' => 'OPTION_CHOICE', 'service_option_id' => $propertyOption, 'operator' => 'EQ', 'value_choice_id' => $villaChoice]),
                    ]),
                ],
            ]),
        ];

        $selections = [$propertyOption => ['choice_ids' => [$villaChoice]]];

        $result = $this->evaluate($rules, $selections);

        $this->assertSame(PricingStatus::PRICED, $result->status);
        $this->assertSame('2500.000000', $result->unitTotal);
    }

    public function test_conditional_set_price_does_not_fire_when_condition_false(): void
    {
        $propertyOption = 'opt-property-type';

        $rules = [
            $this->rule([
                'effect_type' => EffectType::SET_PRICE->value,
                'effect_amount' => '2500',
                'condition_groups' => [
                    $this->group([$this->condition(['subject_type' => 'OPTION_CHOICE', 'service_option_id' => $propertyOption, 'operator' => 'EQ', 'value_choice_id' => 'choice-villa'])]),
                ],
            ]),
        ];

        $selections = [$propertyOption => ['choice_ids' => ['choice-apartment']]];

        $result = $this->evaluate($rules, $selections);

        $this->assertSame(PricingStatus::UNAVAILABLE, $result->status);
    }

    // ---- 3. package + later add-ons (SET_PRICE does NOT imply stop) ----

    public function test_package_combines_with_later_addons(): void
    {
        $rules = [
            $this->rule(['priority' => 10, 'rule_code' => 'PACKAGE', 'effect_type' => EffectType::SET_PRICE->value, 'effect_amount' => '2500', 'stop_processing' => false]),
            $this->rule(['priority' => 20, 'rule_code' => 'PREMIUM_PAINT', 'effect_type' => EffectType::ADD_FIXED->value, 'effect_amount' => '300']),
            $this->rule(['priority' => 30, 'rule_code' => 'CEILING', 'effect_type' => EffectType::ADD_FIXED->value, 'effect_amount' => '200']),
        ];

        $result = $this->evaluate($rules);

        $this->assertSame('3000.000000', $result->unitTotal);
        $this->assertSame('2500.000000', $result->baseAmount);
        $this->assertCount(3, $result->adjustments);
    }

    // ---- 4. explicit stop_processing ------------------------------------

    public function test_stop_processing_halts_later_rules(): void
    {
        $rules = [
            $this->rule(['priority' => 10, 'rule_code' => 'FINAL_PACKAGE', 'effect_type' => EffectType::SET_PRICE->value, 'effect_amount' => '4000', 'stop_processing' => true]),
            $this->rule(['priority' => 20, 'rule_code' => 'SHOULD_NOT_FIRE', 'effect_type' => EffectType::ADD_FIXED->value, 'effect_amount' => '999']),
        ];

        $result = $this->evaluate($rules);

        $this->assertSame('4000.000000', $result->unitTotal);
        $this->assertCount(1, $result->adjustments);
    }

    // ---- 5. choice add-on -------------------------------------------------

    public function test_choice_addon(): void
    {
        $cleaningOption = 'opt-cleaning-type';
        $premiumChoice = 'choice-premium';

        $rules = [
            $this->rule(['priority' => 10, 'effect_type' => EffectType::SET_PRICE->value, 'effect_amount' => '120']),
            $this->rule([
                'priority' => 20,
                'rule_code' => 'PREMIUM',
                'effect_type' => EffectType::ADD_FIXED->value,
                'effect_amount' => '50',
                'condition_groups' => [$this->group([$this->condition(['subject_type' => 'OPTION_CHOICE', 'service_option_id' => $cleaningOption, 'operator' => 'EQ', 'value_choice_id' => $premiumChoice])])],
            ]),
        ];

        $selections = [$cleaningOption => ['choice_ids' => [$premiumChoice]]];

        $result = $this->evaluate($rules, $selections);

        $this->assertSame('170.000000', $result->unitTotal);
    }

    // ---- 6. boolean add-on ------------------------------------------------

    public function test_boolean_addon(): void
    {
        $ceilingOption = 'opt-has-ceiling-work';

        $rules = [
            $this->rule(['priority' => 10, 'effect_type' => EffectType::SET_PRICE->value, 'effect_amount' => '100']),
            $this->rule([
                'priority' => 20,
                'effect_type' => EffectType::ADD_FIXED->value,
                'effect_amount' => '200',
                'condition_groups' => [$this->group([$this->condition(['subject_type' => 'OPTION_BOOLEAN_VALUE', 'service_option_id' => $ceilingOption, 'operator' => 'EQ', 'value_boolean' => true])])],
            ]),
        ];

        $result = $this->evaluate($rules, [$ceilingOption => ['boolean_value' => true]]);
        $this->assertSame('300.000000', $result->unitTotal);

        $resultFalse = $this->evaluate($rules, [$ceilingOption => ['boolean_value' => false]]);
        $this->assertSame('100.000000', $resultFalse->unitTotal);
    }

    // ---- 7. numeric per-unit (included + extra, GRADUATED) ---------------

    public function test_numeric_per_unit_with_included_value(): void
    {
        $acOption = 'opt-ac-units';

        $rules = [
            $this->rule([
                'effect_type' => EffectType::ADD_PER_UNIT->value,
                'effect_subject_type' => 'OPTION_NUMERIC_VALUE',
                'effect_subject_service_option_id' => $acOption,
                'tier_calculation_mode' => 'GRADUATED',
                'tiers' => [
                    $this->tier(['tier_order' => 1, 'from_unit' => '0', 'to_unit' => '1', 'rate_amount' => '0']),
                    $this->tier(['tier_order' => 2, 'from_unit' => '1', 'to_unit' => null, 'rate_amount' => '75']),
                ],
            ]),
        ];

        $result = $this->evaluate($rules, [$acOption => ['numeric_value' => '3']]);

        $this->assertSame('150.000000', $result->unitTotal); // 2 extra units * 75
    }

    // ---- 8. square-meter style pricing ------------------------------------

    public function test_square_meter_pricing(): void
    {
        $areaOption = 'opt-area-sqm';

        $rules = [
            $this->rule([
                'effect_type' => EffectType::ADD_PER_UNIT->value,
                'effect_subject_type' => 'OPTION_NUMERIC_VALUE',
                'effect_subject_service_option_id' => $areaOption,
                'tier_calculation_mode' => 'GRADUATED',
                'tiers' => [$this->tier(['from_unit' => '0', 'to_unit' => null, 'charge_unit_size' => '10', 'rate_amount' => '25'])],
            ]),
        ];

        $result = $this->evaluate($rules, [$areaOption => ['numeric_value' => '35']]);

        // ceil(35/10) = 4 billable units * 25 = 100
        $this->assertSame('100.000000', $result->unitTotal);
    }

    // ---- 9. VOLUME + FLAT ---------------------------------------------------

    public function test_volume_flat_tiers(): void
    {
        $roomsOption = 'opt-rooms';

        $rules = [
            $this->rule([
                'effect_type' => EffectType::ADD_PER_UNIT->value,
                'effect_subject_type' => 'OPTION_NUMERIC_VALUE',
                'effect_subject_service_option_id' => $roomsOption,
                'tier_calculation_mode' => 'VOLUME',
                'tiers' => [
                    $this->tier(['tier_order' => 1, 'from_unit' => '1', 'to_unit' => '3', 'rate_amount' => '900', 'tier_pricing_mode' => 'FLAT']),
                    $this->tier(['tier_order' => 2, 'from_unit' => '4', 'to_unit' => '6', 'rate_amount' => '1500', 'tier_pricing_mode' => 'FLAT']),
                    $this->tier(['tier_order' => 3, 'from_unit' => '7', 'to_unit' => '10', 'rate_amount' => '2200', 'tier_pricing_mode' => 'FLAT']),
                ],
            ]),
        ];

        $this->assertSame('900.000000', $this->evaluate($rules, [$roomsOption => ['numeric_value' => '2']])->unitTotal);
        $this->assertSame('1500.000000', $this->evaluate($rules, [$roomsOption => ['numeric_value' => '4']])->unitTotal);
        $this->assertSame('2200.000000', $this->evaluate($rules, [$roomsOption => ['numeric_value' => '10']])->unitTotal);
    }

    // ---- 10. VOLUME + PER_UNIT ----------------------------------------------

    public function test_volume_per_unit_tiers(): void
    {
        $unitsOption = 'opt-units';

        $rules = [
            $this->rule([
                'effect_type' => EffectType::ADD_PER_UNIT->value,
                'effect_subject_type' => 'OPTION_NUMERIC_VALUE',
                'effect_subject_service_option_id' => $unitsOption,
                'tier_calculation_mode' => 'VOLUME',
                'tiers' => [
                    $this->tier(['tier_order' => 1, 'from_unit' => '0', 'to_unit' => '10', 'rate_amount' => '5', 'tier_pricing_mode' => 'PER_UNIT']),
                ],
            ]),
        ];

        $result = $this->evaluate($rules, [$unitsOption => ['numeric_value' => '8']]);

        $this->assertSame('40.000000', $result->unitTotal); // ceil(8/1) * 5
    }

    // ---- 11. GRADUATED + PER_UNIT -------------------------------------------

    public function test_graduated_per_unit_tiers(): void
    {
        $unitsOption = 'opt-units';

        $rules = [
            $this->rule([
                'effect_type' => EffectType::ADD_PER_UNIT->value,
                'effect_subject_type' => 'OPTION_NUMERIC_VALUE',
                'effect_subject_service_option_id' => $unitsOption,
                'tier_calculation_mode' => 'GRADUATED',
                'tiers' => [
                    $this->tier(['tier_order' => 1, 'from_unit' => '0', 'to_unit' => '3', 'rate_amount' => '50']),
                    $this->tier(['tier_order' => 2, 'from_unit' => '3', 'to_unit' => '6', 'rate_amount' => '40']),
                    $this->tier(['tier_order' => 3, 'from_unit' => '6', 'to_unit' => null, 'rate_amount' => '30']),
                ],
            ]),
        ];

        $result = $this->evaluate($rules, [$unitsOption => ['numeric_value' => '8']]);

        // 3*50 + 3*40 + 2*30 = 150 + 120 + 60 = 330
        $this->assertSame('330.000000', $result->unitTotal);
    }

    // ---- 12. CEILING partial unit behavior ----------------------------------

    public function test_ceiling_partial_charge_unit(): void
    {
        $unitsOption = 'opt-units';

        $rules = [
            $this->rule([
                'effect_type' => EffectType::ADD_PER_UNIT->value,
                'effect_subject_type' => 'OPTION_NUMERIC_VALUE',
                'effect_subject_service_option_id' => $unitsOption,
                'tier_calculation_mode' => 'GRADUATED',
                'tiers' => [$this->tier(['from_unit' => '0', 'to_unit' => null, 'charge_unit_size' => '5', 'rate_amount' => '20'])],
            ]),
        ];

        // span of 12 with charge_unit_size 5 => ceil(12/5) = 3 billable units
        $result = $this->evaluate($rules, [$unitsOption => ['numeric_value' => '12']]);

        $this->assertSame('60.000000', $result->unitTotal);
    }

    // ---- 13. multiplier -----------------------------------------------------

    public function test_multiplier(): void
    {
        $rules = [
            $this->rule(['priority' => 10, 'effect_type' => EffectType::SET_PRICE->value, 'effect_amount' => '100']),
            $this->rule(['priority' => 20, 'rule_code' => 'AFTER_HOURS', 'effect_type' => EffectType::MULTIPLY->value, 'effect_amount' => '1.5']),
        ];

        $result = $this->evaluate($rules);

        $this->assertSame('150.000000', $result->unitTotal);
        $this->assertSame('1.500000', $result->adjustments[1]->amountOrFactor);
    }

    // ---- 14. min total --------------------------------------------------

    public function test_min_total(): void
    {
        $rules = [
            $this->rule(['priority' => 10, 'effect_type' => EffectType::SET_PRICE->value, 'effect_amount' => '20']),
            $this->rule(['priority' => 20, 'effect_type' => EffectType::MIN_TOTAL->value, 'effect_amount' => '50']),
        ];

        $result = $this->evaluate($rules);

        $this->assertSame('50.000000', $result->unitTotal);
    }

    // ---- 15. max total --------------------------------------------------

    public function test_max_total(): void
    {
        $rules = [
            $this->rule(['priority' => 10, 'effect_type' => EffectType::SET_PRICE->value, 'effect_amount' => '900']),
            $this->rule(['priority' => 20, 'effect_type' => EffectType::MAX_TOTAL->value, 'effect_amount' => '500']),
        ];

        $result = $this->evaluate($rules);

        $this->assertSame('500.000000', $result->unitTotal);
    }

    // ---- 16. MULTI_SELECT independent addons -----------------------------

    public function test_multi_select_addons_are_independent(): void
    {
        $addonOption = 'opt-addons';

        $rules = [
            $this->rule(['priority' => 10, 'effect_type' => EffectType::SET_PRICE->value, 'effect_amount' => '100']),
            $this->rule([
                'priority' => 20,
                'rule_code' => 'ADDON_A',
                'effect_type' => EffectType::ADD_FIXED->value,
                'effect_amount' => '30',
                'condition_groups' => [$this->group([$this->condition(['subject_type' => 'OPTION_CHOICE', 'service_option_id' => $addonOption, 'operator' => 'EQ', 'value_choice_id' => 'choice-a'])])],
            ]),
            $this->rule([
                'priority' => 30,
                'rule_code' => 'ADDON_B',
                'effect_type' => EffectType::ADD_FIXED->value,
                'effect_amount' => '45',
                'condition_groups' => [$this->group([$this->condition(['subject_type' => 'OPTION_CHOICE', 'service_option_id' => $addonOption, 'operator' => 'EQ', 'value_choice_id' => 'choice-b'])])],
            ]),
        ];

        $selections = [$addonOption => ['choice_ids' => ['choice-a', 'choice-b']]];

        $result = $this->evaluate($rules, $selections);

        $this->assertSame('175.000000', $result->unitTotal);
        $this->assertCount(3, $result->adjustments);
    }

    // ---- 17. compound AND conditions --------------------------------------

    public function test_compound_and_conditions(): void
    {
        $propertyOption = 'opt-property-type';
        $roomsOption = 'opt-rooms';

        $rules = [
            $this->rule([
                'effect_type' => EffectType::SET_PRICE->value,
                'effect_amount' => '2500',
                'condition_groups' => [
                    $this->group([
                        $this->condition(['subject_type' => 'OPTION_CHOICE', 'service_option_id' => $propertyOption, 'operator' => 'EQ', 'value_choice_id' => 'choice-villa']),
                        $this->condition(['subject_type' => 'OPTION_NUMERIC_VALUE', 'service_option_id' => $roomsOption, 'operator' => 'GTE', 'value_number' => '4']),
                    ]),
                ],
            ]),
        ];

        $matches = [$propertyOption => ['choice_ids' => ['choice-villa']], $roomsOption => ['numeric_value' => '5']];
        $this->assertSame(PricingStatus::PRICED, $this->evaluate($rules, $matches)->status);

        $onlyOneTrue = [$propertyOption => ['choice_ids' => ['choice-villa']], $roomsOption => ['numeric_value' => '2']];
        $this->assertSame(PricingStatus::UNAVAILABLE, $this->evaluate($rules, $onlyOneTrue)->status);
    }

    // ---- 18. OR condition groups --------------------------------------------

    public function test_or_condition_groups(): void
    {
        $tierOption = 'opt-tier';

        $rules = [
            $this->rule([
                'effect_type' => EffectType::SET_PRICE->value,
                'effect_amount' => '500',
                'condition_groups' => [
                    $this->group([$this->condition(['subject_type' => 'OPTION_CHOICE', 'service_option_id' => $tierOption, 'operator' => 'EQ', 'value_choice_id' => 'choice-gold'])]),
                    $this->group([$this->condition(['subject_type' => 'OPTION_CHOICE', 'service_option_id' => $tierOption, 'operator' => 'EQ', 'value_choice_id' => 'choice-platinum'])]),
                ],
            ]),
        ];

        $this->assertSame(PricingStatus::PRICED, $this->evaluate($rules, [$tierOption => ['choice_ids' => ['choice-platinum']]])->status);
        $this->assertSame(PricingStatus::UNAVAILABLE, $this->evaluate($rules, [$tierOption => ['choice_ids' => ['choice-silver']]])->status);
    }

    // ---- 19. BETWEEN --------------------------------------------------------

    public function test_between_operator(): void
    {
        $roomsOption = 'opt-rooms';

        $rules = [
            $this->rule([
                'effect_type' => EffectType::SET_PRICE->value,
                'effect_amount' => '1500',
                'condition_groups' => [$this->group([$this->condition(['subject_type' => 'OPTION_NUMERIC_VALUE', 'service_option_id' => $roomsOption, 'operator' => 'BETWEEN', 'value_number' => '4', 'value_number_high' => '6'])])],
            ]),
        ];

        $this->assertSame(PricingStatus::PRICED, $this->evaluate($rules, [$roomsOption => ['numeric_value' => '5']])->status);
        $this->assertSame(PricingStatus::UNAVAILABLE, $this->evaluate($rules, [$roomsOption => ['numeric_value' => '7']])->status);
    }

    // ---- 20. IN / NOT_IN -----------------------------------------------------

    public function test_in_and_not_in_operators(): void
    {
        $zoneOption = 'opt-zone';

        $inRule = $this->rule([
            'effect_type' => EffectType::SET_PRICE->value,
            'effect_amount' => '100',
            'condition_groups' => [$this->group([$this->condition(['subject_type' => 'OPTION_CHOICE', 'service_option_id' => $zoneOption, 'operator' => 'IN', 'value_set' => ['choice-a', 'choice-b']])])],
        ]);

        $this->assertSame(PricingStatus::PRICED, $this->evaluate([$inRule], [$zoneOption => ['choice_ids' => ['choice-b']]])->status);
        $this->assertSame(PricingStatus::UNAVAILABLE, $this->evaluate([$inRule], [$zoneOption => ['choice_ids' => ['choice-c']]])->status);

        $notInRule = $this->rule([
            'effect_type' => EffectType::SET_PRICE->value,
            'effect_amount' => '100',
            'condition_groups' => [$this->group([$this->condition(['subject_type' => 'OPTION_CHOICE', 'service_option_id' => $zoneOption, 'operator' => 'NOT_IN', 'value_set' => ['choice-a', 'choice-b']])])],
        ]);

        $this->assertSame(PricingStatus::PRICED, $this->evaluate([$notInRule], [$zoneOption => ['choice_ids' => ['choice-c']]])->status);
        $this->assertSame(PricingStatus::UNAVAILABLE, $this->evaluate([$notInRule], [$zoneOption => ['choice_ids' => ['choice-a']]])->status);
    }

    // ---- 21. item quantity used only for final line_total -------------------

    public function test_quantity_only_multiplies_final_line_total(): void
    {
        $hoursOption = 'opt-hours';

        $rules = [
            $this->rule([
                'effect_type' => EffectType::ADD_PER_UNIT->value,
                'effect_subject_type' => 'OPTION_NUMERIC_VALUE',
                'effect_subject_service_option_id' => $hoursOption,
                'tier_calculation_mode' => 'GRADUATED',
                'tiers' => [$this->tier(['from_unit' => '0', 'to_unit' => null, 'rate_amount' => '40'])],
            ]),
        ];

        $result = $this->evaluate($rules, [$hoursOption => ['numeric_value' => '3']], quantity: 2);

        $this->assertSame('120.000000', $result->unitTotal); // unaffected by quantity
        $this->assertSame(2, $result->quantity);
        $this->assertSame('240.000000', $result->lineTotal); // unit_total * quantity, exactly once
    }

    public function test_item_quantity_as_condition_subject_for_bulk_discount(): void
    {
        $rules = [
            $this->rule(['priority' => 10, 'effect_type' => EffectType::SET_PRICE->value, 'effect_amount' => '50']),
            $this->rule([
                'priority' => 20,
                'rule_code' => 'BULK_DISCOUNT',
                'effect_type' => EffectType::MULTIPLY->value,
                'effect_amount' => '0.9',
                'condition_groups' => [$this->group([$this->condition(['subject_type' => 'ITEM_QUANTITY', 'operator' => 'GTE', 'value_number' => '3'])])],
            ]),
        ];

        $this->assertSame('45.000000', $this->evaluate($rules, [], quantity: 3)->unitTotal);
        $this->assertSame('50.000000', $this->evaluate($rules, [], quantity: 2)->unitTotal);
    }

    // ---- 22. QUOTE_REQUIRED --------------------------------------------------

    public function test_quote_required_stops_and_returns_no_amount(): void
    {
        $rules = [
            $this->rule(['effect_type' => EffectType::QUOTE_REQUIRED->value, 'effect_amount' => null, 'stop_processing' => true]),
        ];

        $result = $this->evaluate($rules);

        $this->assertSame(PricingStatus::QUOTE_REQUIRED, $result->status);
        $this->assertNull($result->unitTotal);
        $this->assertNull($result->lineTotal);
    }

    // ---- 23. MISSING_CONTEXT --------------------------------------------------

    public function test_missing_context_never_guesses(): void
    {
        $rules = [
            $this->rule([
                'effect_type' => EffectType::MULTIPLY->value,
                'effect_amount' => '1.5',
                'condition_groups' => [$this->group([$this->condition(['subject_type' => 'CONTEXT_ATTRIBUTE', 'context_attribute_code' => 'TIME_WINDOW', 'operator' => 'EQ', 'value_number' => '1'])])],
            ]),
        ];

        $result = $this->evaluate($rules, [], context: []);

        $this->assertSame(PricingStatus::MISSING_CONTEXT, $result->status);
        $this->assertSame(['TIME_WINDOW'], $result->requiredContext);
    }

    public function test_context_present_resolves_normally(): void
    {
        $rules = [
            $this->rule(['priority' => 10, 'effect_type' => EffectType::SET_PRICE->value, 'effect_amount' => '100']),
            $this->rule([
                'priority' => 20,
                'effect_type' => EffectType::MULTIPLY->value,
                'effect_amount' => '1.5',
                'condition_groups' => [$this->group([$this->condition(['subject_type' => 'CONTEXT_ATTRIBUTE', 'context_attribute_code' => 'TIME_WINDOW', 'operator' => 'EQ', 'value_number' => '1'])])],
            ]),
        ];

        $result = $this->evaluate($rules, [], context: ['TIME_WINDOW' => '1']);

        $this->assertSame(PricingStatus::PRICED, $result->status);
        $this->assertSame('150.000000', $result->unitTotal);
    }

    // ---- 24. UNAVAILABLE -------------------------------------------------

    public function test_unavailable_when_no_rule_fires(): void
    {
        $result = $this->evaluate([]);

        $this->assertSame(PricingStatus::UNAVAILABLE, $result->status);
    }

    public function test_unavailable_when_numeric_value_outside_all_tiers(): void
    {
        $roomsOption = 'opt-rooms';

        $rules = [
            $this->rule([
                'effect_type' => EffectType::ADD_PER_UNIT->value,
                'effect_subject_type' => 'OPTION_NUMERIC_VALUE',
                'effect_subject_service_option_id' => $roomsOption,
                'tier_calculation_mode' => 'VOLUME',
                'tiers' => [$this->tier(['from_unit' => '1', 'to_unit' => '3', 'rate_amount' => '900', 'tier_pricing_mode' => 'FLAT'])],
            ]),
        ];

        $result = $this->evaluate($rules, [$roomsOption => ['numeric_value' => '20']]);

        $this->assertSame(PricingStatus::UNAVAILABLE, $result->status);
    }

    // ---- 28. deterministic rule ordering -------------------------------------

    public function test_rules_are_evaluated_in_priority_order_regardless_of_input_order(): void
    {
        $rulesInReverseOrder = [
            $this->rule(['priority' => 30, 'rule_code' => 'THIRD', 'effect_type' => EffectType::MULTIPLY->value, 'effect_amount' => '2']),
            $this->rule(['priority' => 10, 'rule_code' => 'FIRST', 'effect_type' => EffectType::SET_PRICE->value, 'effect_amount' => '10']),
            $this->rule(['priority' => 20, 'rule_code' => 'SECOND', 'effect_type' => EffectType::ADD_FIXED->value, 'effect_amount' => '5']),
        ];

        $result = $this->evaluate($rulesInReverseOrder);

        // (10 + 5) * 2 = 30, only correct if evaluated by priority, not input order
        $this->assertSame('30.000000', $result->unitTotal);
        $this->assertSame('FIRST', $result->adjustments[0]->ruleCode);
        $this->assertSame('SECOND', $result->adjustments[1]->ruleCode);
        $this->assertSame('THIRD', $result->adjustments[2]->ruleCode);
    }
}
