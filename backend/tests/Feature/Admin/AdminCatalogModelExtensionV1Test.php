<?php

namespace Tests\Feature\Admin;

use App\Support\Pricing\PricingEngine;
use App\Support\Pricing\PricingStatus;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B23-ext - Catalog Model Extension: golden-master proof that
 * the EXISTING App\Support\Pricing\PricingEngine/PricingRuleEvaluator
 * (never redesigned this phase) already expresses every required BLUE
 * pricing model, plus the new additive catalog domains this phase adds
 * (quantity policy, featured/duration, structured package/choice
 * attributes, content sections, checkpoint groups/checkpoints) and the
 * Admin Pricing Preview tool. Every fixture here is clearly-labelled QA
 * data, never real BLUE catalog content (see section 35 of the phase
 * spec) - real catalog seeding is a later phase.
 */
class AdminCatalogModelExtensionV1Test extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    // -----------------------------------------------------------------
    // Golden pricing models - proving the UNCHANGED PricingEngine already
    // expresses every required BLUE pricing shape, via direct evaluate()
    // calls against hand-built scheme/rule/tier/condition fixtures.
    // -----------------------------------------------------------------

    public function test_model_a_first_unit_plus_additional_unit(): void
    {
        $service = $this->createCartService();
        $option = $this->createCartOption($service['uuid'], $this->numberTypeId, ['name' => 'Units']);
        $this->createCartNumericRule($option, ['minimum_value' => '1.000000', 'maximum_value' => '10.000000', 'step_value' => '1.000000', 'decimal_places' => 0]);

        $scheme = $this->createCartPricingScheme($service['uuid']);
        $rule = $this->createCartPricingRule($scheme, [
            'rule_code' => 'UNITS',
            'effect_type' => 'ADD_PER_UNIT',
            'effect_amount' => null,
            'effect_subject_type' => 'OPTION_NUMERIC_VALUE',
            'effect_subject_service_option_id' => $option,
            'tier_calculation_mode' => 'GRADUATED',
        ]);
        $this->insertTier($rule, 1, '0', '1', '1', '200.000000', 'PER_UNIT');
        $this->insertTier($rule, 2, '1', null, '1', '75.000000', 'PER_UNIT');

        $this->assertSame('200.000000', $this->evaluateUnitTotal($service['uuid'], [$option => ['numeric_value' => '1']]));
        $this->assertSame('275.000000', $this->evaluateUnitTotal($service['uuid'], [$option => ['numeric_value' => '2']]));
        $this->assertSame('350.000000', $this->evaluateUnitTotal($service['uuid'], [$option => ['numeric_value' => '3']]));
    }

    public function test_model_b_two_independent_counters(): void
    {
        $service = $this->createCartService();
        $ducts = $this->createCartOption($service['uuid'], $this->numberTypeId, ['name' => 'Ducts']);
        $coils = $this->createCartOption($service['uuid'], $this->numberTypeId, ['name' => 'Coils']);
        $this->createCartNumericRule($ducts, ['minimum_value' => '0.000000', 'maximum_value' => '20.000000', 'decimal_places' => 0]);
        $this->createCartNumericRule($coils, ['minimum_value' => '0.000000', 'maximum_value' => '20.000000', 'decimal_places' => 0]);

        $scheme = $this->createCartPricingScheme($service['uuid']);

        $ductRule = $this->createCartPricingRule($scheme, [
            'rule_code' => 'DUCTS', 'priority' => 100, 'effect_type' => 'ADD_PER_UNIT', 'effect_amount' => null,
            'effect_subject_type' => 'OPTION_NUMERIC_VALUE', 'effect_subject_service_option_id' => $ducts, 'tier_calculation_mode' => 'GRADUATED',
        ]);
        $this->insertTier($ductRule, 1, '0', null, '1', '250.000000', 'PER_UNIT');

        $coilRule = $this->createCartPricingRule($scheme, [
            'rule_code' => 'COILS', 'priority' => 101, 'effect_type' => 'ADD_PER_UNIT', 'effect_amount' => null,
            'effect_subject_type' => 'OPTION_NUMERIC_VALUE', 'effect_subject_service_option_id' => $coils, 'tier_calculation_mode' => 'GRADUATED',
        ]);
        $this->insertTier($coilRule, 1, '0', null, '1', '250.000000', 'PER_UNIT');

        $unitTotal = $this->evaluateUnitTotal($service['uuid'], [
            $ducts => ['numeric_value' => '2'],
            $coils => ['numeric_value' => '3'],
        ]);

        $this->assertSame('1250.000000', $unitTotal);
    }

    public function test_model_c_hourly_tiered_with_admin_configurable_discount(): void
    {
        $service = $this->createCartService();
        $hours = $this->createCartOption($service['uuid'], $this->numberTypeId, ['name' => 'Hours']);
        $this->createCartNumericRule($hours, ['minimum_value' => '1.000000', 'maximum_value' => '12.000000', 'decimal_places' => 0]);

        $scheme = $this->createCartPricingScheme($service['uuid']);
        $rule = $this->createCartPricingRule($scheme, [
            'rule_code' => 'HOURLY', 'effect_type' => 'ADD_PER_UNIT', 'effect_amount' => null,
            'effect_subject_type' => 'OPTION_NUMERIC_VALUE', 'effect_subject_service_option_id' => $hours, 'tier_calculation_mode' => 'GRADUATED',
        ]);
        $this->insertTier($rule, 1, '0', '3', '1', '150.000000', 'PER_UNIT');
        // The 4th+ hour is Admin-configured cheaper (100 instead of 150) -
        // never hardcoded in this test's assertion logic, only in the
        // fixture rate this test itself chose.
        $this->insertTier($rule, 2, '3', null, '1', '100.000000', 'PER_UNIT');

        $this->assertSame('150.000000', $this->evaluateUnitTotal($service['uuid'], [$hours => ['numeric_value' => '1']]));
        $this->assertSame('300.000000', $this->evaluateUnitTotal($service['uuid'], [$hours => ['numeric_value' => '2']]));
        $this->assertSame('450.000000', $this->evaluateUnitTotal($service['uuid'], [$hours => ['numeric_value' => '3']]));
        $this->assertSame('550.000000', $this->evaluateUnitTotal($service['uuid'], [$hours => ['numeric_value' => '4']]));
    }

    public function test_model_d_single_select_package(): void
    {
        $service = $this->createCartService();
        $bedrooms = $this->createCartOption($service['uuid'], $this->singleSelectTypeId, ['name' => 'Bedrooms']);
        $this->createCartSelectionRule($bedrooms, ['minimum_selections' => 1, 'maximum_selections' => 1]);

        $prices = ['1' => '750.000000', '2' => '1250.000000', '3' => '1550.000000', '4' => '1649.000000'];
        $choiceUuids = [];

        foreach ($prices as $label => $amount) {
            $choiceUuids[$label] = $this->createCartChoice($bedrooms, ['name' => $label.' Bedroom']);
        }

        $scheme = $this->createCartPricingScheme($service['uuid']);
        $priority = 100;

        foreach ($choiceUuids as $label => $choiceUuid) {
            $rule = $this->createCartPricingRule($scheme, [
                'rule_code' => 'PKG_'.$label, 'priority' => $priority++, 'effect_type' => 'SET_PRICE', 'effect_amount' => $prices[$label],
            ]);
            $this->insertChoiceCondition($rule, $bedrooms, $choiceUuid);
        }

        $unitTotal = $this->evaluateUnitTotal($service['uuid'], [$bedrooms => ['choice_ids' => [$choiceUuids['2']]]]);

        $this->assertSame('1250.000000', $unitTotal);
    }

    public function test_model_e_base_package_plus_addon_counters(): void
    {
        $service = $this->createCartService();
        $bedrooms = $this->createCartOption($service['uuid'], $this->singleSelectTypeId, ['name' => 'Bedrooms']);
        $this->createCartSelectionRule($bedrooms, ['minimum_selections' => 1, 'maximum_selections' => 1]);
        $bedroomChoice = $this->createCartChoice($bedrooms, ['name' => '1 Bedroom']);

        $ceilings = $this->createCartOption($service['uuid'], $this->numberTypeId, ['name' => 'Ceilings']);
        $bathrooms = $this->createCartOption($service['uuid'], $this->numberTypeId, ['name' => 'Bathrooms']);
        $this->createCartNumericRule($ceilings, ['minimum_value' => '0.000000', 'maximum_value' => '20.000000', 'decimal_places' => 0]);
        $this->createCartNumericRule($bathrooms, ['minimum_value' => '0.000000', 'maximum_value' => '20.000000', 'decimal_places' => 0]);

        $scheme = $this->createCartPricingScheme($service['uuid']);

        $baseRule = $this->createCartPricingRule($scheme, ['rule_code' => 'BASE', 'priority' => 100, 'effect_type' => 'SET_PRICE', 'effect_amount' => '750.000000', 'stop_processing' => 0]);
        $this->insertChoiceCondition($baseRule, $bedrooms, $bedroomChoice);

        $ceilingRule = $this->createCartPricingRule($scheme, [
            'rule_code' => 'CEILINGS', 'priority' => 101, 'effect_type' => 'ADD_PER_UNIT', 'effect_amount' => null,
            'effect_subject_type' => 'OPTION_NUMERIC_VALUE', 'effect_subject_service_option_id' => $ceilings, 'tier_calculation_mode' => 'GRADUATED',
        ]);
        $this->insertTier($ceilingRule, 1, '0', null, '1', '100.000000', 'PER_UNIT');

        $bathroomRule = $this->createCartPricingRule($scheme, [
            'rule_code' => 'BATHROOMS', 'priority' => 102, 'effect_type' => 'ADD_PER_UNIT', 'effect_amount' => null,
            'effect_subject_type' => 'OPTION_NUMERIC_VALUE', 'effect_subject_service_option_id' => $bathrooms, 'tier_calculation_mode' => 'GRADUATED',
        ]);
        $this->insertTier($bathroomRule, 1, '0', null, '1', '150.000000', 'PER_UNIT');

        $unitTotal = $this->evaluateUnitTotal($service['uuid'], [
            $bedrooms => ['choice_ids' => [$bedroomChoice]],
            $ceilings => ['numeric_value' => '1'],
            $bathrooms => ['numeric_value' => '2'],
        ]);

        $this->assertSame('1150.000000', $unitTotal);
    }

    public function test_model_f_room_count_tier(): void
    {
        $service = $this->createCartService();
        $rooms = $this->createCartOption($service['uuid'], $this->numberTypeId, ['name' => 'Rooms']);
        $this->createCartNumericRule($rooms, ['minimum_value' => '1.000000', 'maximum_value' => '20.000000', 'decimal_places' => 0]);

        $scheme = $this->createCartPricingScheme($service['uuid']);
        $rule = $this->createCartPricingRule($scheme, [
            'rule_code' => 'ROOM_TIER', 'effect_type' => 'ADD_PER_UNIT', 'effect_amount' => null,
            'effect_subject_type' => 'OPTION_NUMERIC_VALUE', 'effect_subject_service_option_id' => $rooms, 'tier_calculation_mode' => 'VOLUME',
        ]);
        $this->insertTier($rule, 1, '1', '3', '1', '200.000000', 'FLAT');
        $this->insertTier($rule, 2, '4', '6', '1', '350.000000', 'FLAT');
        $this->insertTier($rule, 3, '7', null, '1', '500.000000', 'FLAT');

        $this->assertSame('200.000000', $this->evaluateUnitTotal($service['uuid'], [$rooms => ['numeric_value' => '2']]));
        $this->assertSame('350.000000', $this->evaluateUnitTotal($service['uuid'], [$rooms => ['numeric_value' => '5']]));
        $this->assertSame('500.000000', $this->evaluateUnitTotal($service['uuid'], [$rooms => ['numeric_value' => '8']]));
    }

    public function test_model_j_fixed_single_quantity_package(): void
    {
        $service = $this->createCartService();
        DB::table('services')->where('id', UuidBinary::toBinary($service['uuid']))->update(['min_quantity' => 1, 'max_quantity' => 1]);

        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['rule_code' => 'FIXED', 'effect_type' => 'SET_PRICE', 'effect_amount' => '229.000000']);

        $this->assertSame('229.000000', $this->evaluateUnitTotal($service['uuid'], []));

        $customer = $this->createAuthenticatedCartCustomer();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid'], 'quantity' => 2])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Admin Pricing Preview tool - same PricingEngine, no Cart write.
    // -----------------------------------------------------------------

    public function test_admin_pricing_preview_matches_the_cart_calculation(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();
        $option = $this->createCartOption($service['uuid'], $this->numberTypeId, ['name' => 'Units']);
        $this->createCartNumericRule($option, ['minimum_value' => '1.000000', 'maximum_value' => '10.000000', 'decimal_places' => 0]);

        $scheme = $this->createCartPricingScheme($service['uuid']);
        $rule = $this->createCartPricingRule($scheme, [
            'rule_code' => 'UNITS', 'effect_type' => 'ADD_PER_UNIT', 'effect_amount' => null,
            'effect_subject_type' => 'OPTION_NUMERIC_VALUE', 'effect_subject_service_option_id' => $option, 'tier_calculation_mode' => 'GRADUATED',
        ]);
        $this->insertTier($rule, 1, '0', '1', '1', '200.000000', 'PER_UNIT');
        $this->insertTier($rule, 2, '1', null, '1', '75.000000', 'PER_UNIT');

        $response = $this->postJson(
            "/api/v1/admin/services/{$service['uuid']}/pricing-preview",
            ['quantity' => 1, 'options' => [['option_uuid' => $option, 'numeric_value' => 2]]],
            $this->bearer($admin['access_token']),
        );

        $response->assertStatus(200);
        $this->assertSame('275.000000', $response->json('data.pricing.unit_total'));
        $this->assertSame('PRICED', $response->json('data.pricing.pricing_status'));
    }

    public function test_pricing_preview_never_writes_a_cart(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['rule_code' => 'BASE', 'effect_type' => 'SET_PRICE', 'effect_amount' => '100.000000']);

        $before = DB::table('cart_items')->count();

        $this->postJson("/api/v1/admin/services/{$service['uuid']}/pricing-preview", ['quantity' => 1], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $this->assertSame($before, DB::table('cart_items')->count());
    }

    /**
     * BLUE V1 QA Phase - "Advanced pricing authoring -> publish -> preview
     * -> future canonical price" end to end, through the REAL Admin HTTP
     * endpoints this phase built a UI for (draft -> ADD_PER_UNIT rule with
     * tiers via the advanced rule-creation endpoint -> WebAuthn-step-up
     * -gated publish), never a direct DB insert. Proves: (1) a DRAFT rule
     * is invisible to the preview tool (which only ever evaluates the
     * currently-PUBLISHED version), (2) after publish the exact tiered
     * structure Admin authored is what the canonical PricingEngine (via
     * the preview tool) actually computes, and (3) the published version
     * is persisted with the right status.
     */
    public function test_advanced_pricing_authoring_publish_and_preview_reflect_the_same_canonical_price(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $service = $this->createCartService();
        $option = $this->createCartOption($service['uuid'], $this->numberTypeId, ['name' => 'Units']);
        $this->createCartNumericRule($option, ['minimum_value' => '1.000000', 'maximum_value' => '10.000000', 'decimal_places' => 0]);

        $draftResponse = $this->postJson('/api/v1/admin/pricing-schemes', [
            'service_uuid' => $service['uuid'],
            'currency_code' => 'AED',
        ], $this->bearer($admin['access_token']));
        $draftResponse->assertStatus(201);
        $schemeUuid = $draftResponse->json('data.pricing_scheme.uuid');

        $this->postJson("/api/v1/admin/pricing-schemes/{$schemeUuid}/rules", [
            'rule_code' => 'UNITS',
            'label' => 'Units (first + additional)',
            'priority' => 100,
            'effect_type' => 'ADD_PER_UNIT',
            'effect_subject_service_option_id' => $option,
            'tier_calculation_mode' => 'GRADUATED',
            'stop_processing' => false,
            'tiers' => [
                ['tier_order' => 0, 'from_unit' => '0', 'to_unit' => '1', 'charge_unit_size' => '1', 'rate_amount' => '200.000000', 'tier_pricing_mode' => 'PER_UNIT'],
                ['tier_order' => 1, 'from_unit' => '1', 'to_unit' => null, 'charge_unit_size' => '1', 'rate_amount' => '75.000000', 'tier_pricing_mode' => 'PER_UNIT'],
            ],
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        // Verify the persisted structure round-trips exactly what was submitted.
        $schemeDetail = $this->getJson("/api/v1/admin/pricing-schemes/{$schemeUuid}", $this->bearer($admin['access_token']));
        $persistedRule = collect($schemeDetail->json('data.pricing_scheme.rules'))->firstWhere('rule_code', 'UNITS');
        $this->assertSame('ADD_PER_UNIT', $persistedRule['effect_type']);
        $this->assertSame('GRADUATED', $persistedRule['tier_calculation_mode']);
        $this->assertCount(2, $persistedRule['tiers']);
        $this->assertSame('200.000000', $persistedRule['tiers'][0]['rate_amount']);
        $this->assertSame('75.000000', $persistedRule['tiers'][1]['rate_amount']);

        // Before publish: still a DRAFT, so the preview tool (which only
        // ever reads the currently-PUBLISHED version) sees no price yet.
        $prePublishPreview = $this->postJson("/api/v1/admin/services/{$service['uuid']}/pricing-preview", [
            'quantity' => 1,
            'options' => [['option_uuid' => $option, 'numeric_value' => 2]],
        ], $this->bearer($admin['access_token']));
        $this->assertSame('UNAVAILABLE', $prePublishPreview->json('data.pricing.pricing_status'));

        $this->postJson("/api/v1/admin/pricing-schemes/{$schemeUuid}/publish", [
            'effective_from' => now()->toIso8601String(),
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $publishedScheme = $this->getJson("/api/v1/admin/pricing-schemes/{$schemeUuid}", $this->bearer($admin['access_token']));
        $this->assertSame('PUBLISHED', $publishedScheme->json('data.pricing_scheme.status'));

        // After publish: the exact tiered structure Admin authored is now
        // the canonical price for 2 units (1 x 200 + 1 x 75 = 275) - the
        // SAME PricingEngine Cart/Checkout use, never a JS reimplementation.
        $postPublishPreview = $this->postJson("/api/v1/admin/services/{$service['uuid']}/pricing-preview", [
            'quantity' => 1,
            'options' => [['option_uuid' => $option, 'numeric_value' => 2]],
        ], $this->bearer($admin['access_token']));
        $this->assertSame('PRICED', $postPublishPreview->json('data.pricing.pricing_status'));
        $this->assertSame('275.000000', $postPublishPreview->json('data.pricing.unit_total'));
    }

    public function test_advanced_rule_creation_rejects_malformed_configuration(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();
        $otherService = $this->createCartService();
        $foreignOption = $this->createCartOption($otherService['uuid'], $this->numberTypeId, ['name' => 'Foreign']);

        $draftResponse = $this->postJson('/api/v1/admin/pricing-schemes', [
            'service_uuid' => $service['uuid'],
            'currency_code' => 'AED',
        ], $this->bearer($admin['access_token']));
        $schemeUuid = $draftResponse->json('data.pricing_scheme.uuid');

        // ADD_PER_UNIT with no tiers at all.
        $this->postJson("/api/v1/admin/pricing-schemes/{$schemeUuid}/rules", [
            'rule_code' => 'NO_TIERS', 'label' => 'No tiers', 'priority' => 1, 'effect_type' => 'ADD_PER_UNIT',
            'effect_subject_service_option_id' => $this->createCartOption($service['uuid'], $this->numberTypeId),
            'tier_calculation_mode' => 'GRADUATED', 'stop_processing' => false, 'tiers' => [],
        ], $this->bearer($admin['access_token']))->assertStatus(422);

        // ADD_PER_UNIT referencing an option that does not exist.
        $this->postJson("/api/v1/admin/pricing-schemes/{$schemeUuid}/rules", [
            'rule_code' => 'BAD_OPTION', 'label' => 'Bad option', 'priority' => 2, 'effect_type' => 'ADD_PER_UNIT',
            'effect_subject_service_option_id' => (string) Str::uuid(),
            'tier_calculation_mode' => 'GRADUATED', 'stop_processing' => false,
            'tiers' => [['tier_order' => 0, 'from_unit' => '0', 'to_unit' => null, 'charge_unit_size' => '1', 'rate_amount' => '10.000000', 'tier_pricing_mode' => 'PER_UNIT']],
        ], $this->bearer($admin['access_token']))->assertStatus(422);

        // A condition referencing an option that belongs to a DIFFERENT service.
        $this->postJson("/api/v1/admin/pricing-schemes/{$schemeUuid}/rules", [
            'rule_code' => 'CROSS_SERVICE', 'label' => 'Cross-service condition', 'priority' => 3, 'effect_type' => 'SET_PRICE',
            'effect_amount' => '100.000000', 'stop_processing' => false,
            'condition_groups' => [[
                'conditions' => [[
                    'subject_type' => 'OPTION_NUMERIC_VALUE', 'service_option_id' => $foreignOption, 'operator' => 'GT', 'value_number' => '1',
                ]],
            ]],
        ], $this->bearer($admin['access_token']))->assertStatus(422);

        // Malformed decimal amount.
        $this->postJson("/api/v1/admin/pricing-schemes/{$schemeUuid}/rules", [
            'rule_code' => 'BAD_AMOUNT', 'label' => 'Bad amount', 'priority' => 4, 'effect_type' => 'SET_PRICE',
            'effect_amount' => 'not-a-number', 'stop_processing' => false,
        ], $this->bearer($admin['access_token']))->assertStatus(422);

        $this->assertSame(0, DB::table('pricing_rules')->where('pricing_scheme_version_id', UuidBinary::toBinary($schemeUuid))->count());
    }

    // -----------------------------------------------------------------
    // Quantity policy
    // -----------------------------------------------------------------

    public function test_admin_can_set_quantity_policy_and_it_is_enforced_on_cart_add(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();

        $this->postJson("/api/v1/admin/services/{$service['uuid']}/catalog-policy", [
            'is_featured' => false,
            'estimated_duration_minutes' => null,
            'min_quantity' => 2,
            'max_quantity' => 5,
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['rule_code' => 'BASE', 'effect_type' => 'SET_PRICE', 'effect_amount' => '100.000000']);

        $customer = $this->createAuthenticatedCartCustomer();

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid'], 'quantity' => 1])
            ->assertStatus(422);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid'], 'quantity' => 6])
            ->assertStatus(422);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid'], 'quantity' => 3])
            ->assertStatus(201);
    }

    public function test_quantity_policy_rejects_min_greater_than_max(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();

        $this->postJson("/api/v1/admin/services/{$service['uuid']}/catalog-policy", [
            'is_featured' => false,
            'estimated_duration_minutes' => null,
            'min_quantity' => 5,
            'max_quantity' => 2,
        ], $this->bearer($admin['access_token']))->assertStatus(422);
    }

    public function test_customer_cannot_set_catalog_policy(): void
    {
        $service = $this->createCartService();
        $customer = $this->createAuthenticatedCartCustomer();

        $this->postJson("/api/v1/admin/services/{$service['uuid']}/catalog-policy", [
            'is_featured' => true, 'estimated_duration_minutes' => 30, 'min_quantity' => 1, 'max_quantity' => 1,
        ], ['Authorization' => 'Bearer '.$customer['access_token']])->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // Featured / duration - Admin sets, customer catalog exposes.
    // -----------------------------------------------------------------

    public function test_featured_and_duration_are_admin_settable_and_exposed_to_customers(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();

        $before = $this->auditLogsFor($service['uuid'])->count();

        $this->postJson("/api/v1/admin/services/{$service['uuid']}/catalog-policy", [
            'is_featured' => true,
            'estimated_duration_minutes' => 45,
            'min_quantity' => 1,
            'max_quantity' => 1000,
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $this->assertSame($before + 1, $this->auditLogsFor($service['uuid'])->count());

        $response = $this->getJson("/api/v1/services/{$service['slug']}");
        $response->assertStatus(200);
        $this->assertTrue($response->json('data.is_featured'));
        $this->assertSame(45, $response->json('data.estimated_duration_minutes'));
        $this->assertSame(['min' => 1, 'max' => 1000], $response->json('data.quantity'));

        $categoryResponse = $this->getJson('/api/v1/service-categories/'.$this->serviceCategoryIdFor($service['uuid']).'/services');
        $entry = collect($categoryResponse->json('data.services'))->firstWhere('uuid', $service['uuid']);
        $this->assertTrue($entry['is_featured']);
        $this->assertSame(45, $entry['estimated_duration_minutes']);
    }

    // -----------------------------------------------------------------
    // Structured package/choice attributes (Model G)
    // -----------------------------------------------------------------

    public function test_admin_can_manage_choice_attributes_and_customer_api_exposes_them(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();
        $package = $this->createCartOption($service['uuid'], $this->singleSelectTypeId, ['name' => 'Package']);
        $this->createCartSelectionRule($package);
        $premium = $this->createCartChoice($package, ['name' => 'Premium']);

        $this->postJson("/api/v1/admin/service-option-choices/{$premium}/attributes", [
            'attribute_type_code' => 'DURATION_MINUTES', 'value' => '60',
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        $this->postJson("/api/v1/admin/service-option-choices/{$premium}/attributes", [
            'attribute_type_code' => 'OIL_BRAND', 'value' => 'Castrol MAGNATEC',
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        $oilGradeResponse = $this->postJson("/api/v1/admin/service-option-choices/{$premium}/attributes", [
            'attribute_type_code' => 'OIL_GRADE', 'value' => '5W-40 / 5W-30',
        ], $this->bearer($admin['access_token']));
        $oilGradeResponse->assertStatus(201);
        $oilGradeAttributeUuid = collect($oilGradeResponse->json('data.service.options'))
            ->firstWhere('uuid', $package)['choices'][0]['attributes'];
        $oilGradeUuid = collect($oilGradeAttributeUuid)->firstWhere('attribute_type_code', 'OIL_GRADE')['uuid'];

        // Duplicate attribute type on the same choice is rejected.
        $this->postJson("/api/v1/admin/service-option-choices/{$premium}/attributes", [
            'attribute_type_code' => 'OIL_BRAND', 'value' => 'Shell',
        ], $this->bearer($admin['access_token']))->assertStatus(409);

        // A NUMBER attribute rejects a non-numeric value.
        $this->postJson("/api/v1/admin/service-option-choices/{$premium}/attributes", [
            'attribute_type_code' => 'RECOMMENDED_ODOMETER_KM', 'value' => 'not-a-number',
        ], $this->bearer($admin['access_token']))->assertStatus(422);

        $this->postJson("/api/v1/admin/service-option-choice-attributes/{$oilGradeUuid}/deactivate", [], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $scheme = $this->createCartPricingScheme($service['uuid']);
        $rule = $this->createCartPricingRule($scheme, ['rule_code' => 'PKG', 'effect_type' => 'SET_PRICE', 'effect_amount' => '379.000000']);
        $this->insertChoiceCondition($rule, $package, $premium);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");
        $choicePayload = collect($response->json('data.options'))->firstWhere('uuid', $package)['choices'][0];
        $attributeCodes = collect($choicePayload['attributes'])->pluck('attribute_type_code')->all();

        $this->assertContains('DURATION_MINUTES', $attributeCodes);
        $this->assertContains('OIL_BRAND', $attributeCodes);
        // The deactivated OIL_GRADE attribute must never reach the customer.
        $this->assertNotContains('OIL_GRADE', $attributeCodes);

        $durationAttribute = collect($choicePayload['attributes'])->firstWhere('attribute_type_code', 'DURATION_MINUTES');
        $this->assertSame('60.000000', $durationAttribute['value']);
    }

    // -----------------------------------------------------------------
    // Content sections (Model H)
    // -----------------------------------------------------------------

    public function test_admin_can_manage_content_sections_and_customer_api_returns_them_ordered(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();

        $whatsIncluded = $this->postJson("/api/v1/admin/services/{$service['uuid']}/content-sections", [
            'section_type_code' => 'WHATS_INCLUDED', 'title' => "What's included", 'body' => 'Full body wash and vacuum.', 'display_order' => 2,
        ], $this->bearer($admin['access_token']));
        $whatsIncluded->assertStatus(201);
        $whatsIncludedUuid = collect($whatsIncluded->json('data.service.content_sections'))->firstWhere('section_type_code', 'WHATS_INCLUDED')['uuid'];

        $overview = $this->postJson("/api/v1/admin/services/{$service['uuid']}/content-sections", [
            'section_type_code' => 'OVERVIEW', 'title' => 'Keep Your Car Running Like New', 'body' => 'A complete workshop service.', 'display_order' => 1,
        ], $this->bearer($admin['access_token']));
        $overview->assertStatus(201);

        $toDeactivate = $this->postJson("/api/v1/admin/services/{$service['uuid']}/content-sections", [
            'section_type_code' => 'OTHER', 'title' => 'Draft note', 'body' => 'Not ready yet.', 'display_order' => 0,
        ], $this->bearer($admin['access_token']));
        $draftUuid = collect($toDeactivate->json('data.service.content_sections'))->firstWhere('title', 'Draft note')['uuid'];
        $this->postJson("/api/v1/admin/service-content-sections/{$draftUuid}/deactivate", [], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $this->patchJson("/api/v1/admin/service-content-sections/{$whatsIncludedUuid}", [
            'title' => "What's included (updated)", 'body' => 'Full body wash, vacuum, and tyre shine.', 'display_order' => 2,
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");
        $sections = $response->json('data.content_sections');

        $this->assertCount(2, $sections);
        $this->assertSame('OVERVIEW', $sections[0]['section_type_code']);
        $this->assertSame('Keep Your Car Running Like New', $sections[0]['title']);
        $this->assertSame('WHATS_INCLUDED', $sections[1]['section_type_code']);
        $this->assertSame("What's included (updated)", $sections[1]['title']);
        $this->assertSame(['section_type_code', 'title', 'body'], array_keys($sections[0]));
    }

    // -----------------------------------------------------------------
    // Checkpoint groups/checkpoints (Model I)
    // -----------------------------------------------------------------

    public function test_admin_can_manage_checkpoints_with_derived_counts_and_customer_api_exposes_them_ordered(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();

        $engineGroup = $this->postJson("/api/v1/admin/services/{$service['uuid']}/checkpoint-groups", [
            'name' => 'Engine & Lubrication', 'display_order' => 1,
        ], $this->bearer($admin['access_token']));
        $engineGroup->assertStatus(201);
        $engineGroupUuid = collect($engineGroup->json('data.service.checkpoint_groups'))->firstWhere('name', 'Engine & Lubrication')['uuid'];

        $brakesGroup = $this->postJson("/api/v1/admin/services/{$service['uuid']}/checkpoint-groups", [
            'name' => 'Brakes', 'display_order' => 2,
        ], $this->bearer($admin['access_token']));
        $brakesGroupUuid = collect($brakesGroup->json('data.service.checkpoint_groups'))->firstWhere('name', 'Brakes')['uuid'];

        $replace = $this->postJson("/api/v1/admin/service-checkpoint-groups/{$engineGroupUuid}/checkpoints", [
            'name' => 'Replace engine oil', 'action_type_code' => 'REPLACE', 'display_order' => 1,
        ], $this->bearer($admin['access_token']));
        $replace->assertStatus(201);
        $replaceUuid = collect($replace->json('data.service.checkpoint_groups'))
            ->firstWhere('uuid', $engineGroupUuid)['checkpoints'][0]['uuid'];

        $inspect = $this->postJson("/api/v1/admin/service-checkpoint-groups/{$engineGroupUuid}/checkpoints", [
            'name' => 'Inspect oil filter', 'action_type_code' => 'INSPECT', 'display_order' => 2,
        ], $this->bearer($admin['access_token']));
        $inspect->assertStatus(201);
        $inspectUuid = collect($inspect->json('data.service.checkpoint_groups'))
            ->firstWhere('uuid', $engineGroupUuid)['checkpoints'][1]['uuid'];

        $this->postJson("/api/v1/admin/service-checkpoints/{$inspectUuid}/deactivate", [], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        // Move the (still active) replace checkpoint into the Brakes group.
        $this->patchJson("/api/v1/admin/service-checkpoints/{$replaceUuid}", [
            'name' => 'Replace brake pads', 'action_type_code' => 'REPLACE', 'display_order' => 1, 'group_uuid' => $brakesGroupUuid,
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $adminDetail = $this->getJson("/api/v1/admin/services/{$service['uuid']}", $this->bearer($admin['access_token']));
        $engineGroupDetail = collect($adminDetail->json('data.service.checkpoint_groups'))->firstWhere('uuid', $engineGroupUuid);
        $brakesGroupDetail = collect($adminDetail->json('data.service.checkpoint_groups'))->firstWhere('uuid', $brakesGroupUuid);

        $this->assertSame(1, $engineGroupDetail['checkpoint_count']);
        $this->assertSame(0, $engineGroupDetail['active_checkpoint_count']);
        $this->assertSame(1, $brakesGroupDetail['checkpoint_count']);
        $this->assertSame(1, $brakesGroupDetail['active_checkpoint_count']);

        $customerResponse = $this->getJson("/api/v1/services/{$service['slug']}");
        $checkpointGroups = $customerResponse->json('data.checkpoint_groups');

        // The Engine group has zero ACTIVE checkpoints (its only checkpoint
        // moved away) and must be omitted entirely from the customer view.
        $this->assertCount(1, $checkpointGroups);
        $this->assertSame('Brakes', $checkpointGroups[0]['name']);
        $this->assertSame(1, $checkpointGroups[0]['checkpoint_count']);
        $this->assertSame('Replace brake pads', $checkpointGroups[0]['checkpoints'][0]['name']);
        $this->assertSame('REPLACE', $checkpointGroups[0]['checkpoints'][0]['action_type_code']);
    }

    public function test_checkpoint_rejects_arbitrary_action_code(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $service = $this->createCartService();

        $group = $this->postJson("/api/v1/admin/services/{$service['uuid']}/checkpoint-groups", [
            'name' => 'Body', 'display_order' => 1,
        ], $this->bearer($admin['access_token']));
        $groupUuid = collect($group->json('data.service.checkpoint_groups'))->firstWhere('name', 'Body')['uuid'];

        $this->postJson("/api/v1/admin/service-checkpoint-groups/{$groupUuid}/checkpoints", [
            'name' => 'Buff dents out', 'action_type_code' => 'BUFF_OUT_DENTS', 'display_order' => 1,
        ], $this->bearer($admin['access_token']))->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Historical Booking/payment immutability - proving Phase B23-ext
    // catalog mutations never rewrite an existing Booking/payment fact.
    // -----------------------------------------------------------------

    public function test_catalog_policy_and_pricing_rule_changes_never_mutate_a_pre_existing_booking(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $fixture = $this->bookingWithAssignableItem();

        $itemBefore = DB::table('booking_items')->where('id', $fixture['item']->id)->first();
        $paymentBefore = DB::table('payment_attempts')->where('id', $fixture['payment']->id)->first();

        $this->postJson("/api/v1/admin/services/{$fixture['service']['uuid']}/catalog-policy", [
            'is_featured' => true, 'estimated_duration_minutes' => 90, 'min_quantity' => 1, 'max_quantity' => 3,
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $this->postJson("/api/v1/admin/services/{$fixture['service']['uuid']}/current-price", ['current_price' => '999.00'], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $itemAfter = DB::table('booking_items')->where('id', $fixture['item']->id)->first();
        $paymentAfter = DB::table('payment_attempts')->where('id', $fixture['payment']->id)->first();

        $this->assertSame((string) $itemBefore->line_total_amount, (string) $itemAfter->line_total_amount);
        $this->assertSame($itemBefore->pricing_breakdown, $itemAfter->pricing_breakdown);
        $this->assertSame(bin2hex($itemBefore->pricing_scheme_version_id), bin2hex($itemAfter->pricing_scheme_version_id));
        $this->assertSame((string) $paymentBefore->confirmed_amount, (string) $paymentAfter->confirmed_amount);
    }

    // -----------------------------------------------------------------
    // TEXT input safety - Unicode/Arabic round-trip, script injection is
    // never executed (stored as inert text, rendered via textContent/
    // Blade auto-escaping only - see resources/js/admin/**/*.js's
    // documented "never innerHTML" convention).
    // -----------------------------------------------------------------

    public function test_text_option_preserves_arabic_and_neutralizes_script_content(): void
    {
        $service = $this->createCartService();
        $issueDescription = $this->createCartOption($service['uuid'], $this->textTypeId, ['name' => 'Issue description']);

        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['rule_code' => 'BASE', 'effect_type' => 'SET_PRICE', 'effect_amount' => '100.000000']);

        $customer = $this->createAuthenticatedCartCustomer();
        $arabicWithMarkup = '  الوحدة الخارجية بها تسرب مياه <script>alert(1)</script> "quotes" & symbols  ';

        $response = $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [['option_uuid' => $issueDescription, 'text_value' => $arabicWithMarkup]],
        ]);
        $response->assertStatus(201);

        $itemUuid = $response->json('data.cart.items.0.uuid');
        $storedTextValue = DB::table('cart_item_option_selections')
            ->where('cart_item_id', UuidBinary::toBinary($itemUuid))
            ->value('text_value');

        // Trimmed, but the actual Arabic/markup/punctuation content is
        // preserved byte-for-byte as inert text - never executed, never
        // silently corrupted or mangled.
        $this->assertSame(trim($arabicWithMarkup), $storedTextValue);

        $cart = $this->getCart($customer['access_token']);
        $selection = collect($cart->json('data.cart.items.0.options'))->firstWhere('option_uuid', $issueDescription);
        $this->assertSame(trim($arabicWithMarkup), $selection['text_value']);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function evaluateUnitTotal(string $serviceUuid, array $selections): ?string
    {
        $result = (new PricingEngine)->evaluate(
            serviceIdUuid: $serviceUuid,
            selections: $selections,
            quantity: 1,
            currencyCode: 'AED',
        );

        $this->assertSame(PricingStatus::PRICED, $result->status, 'Expected the fixture to price successfully.');

        return $result->unitTotal;
    }

    private function insertTier(string $ruleUuid, int $order, string $from, ?string $to, string $chargeUnitSize, string $rate, string $mode): void
    {
        DB::table('pricing_rule_tiers')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'pricing_rule_id' => UuidBinary::toBinary($ruleUuid),
            'tier_order' => $order,
            'from_unit' => $from,
            'to_unit' => $to,
            'charge_unit_size' => $chargeUnitSize,
            'rate_amount' => $rate,
            'tier_pricing_mode' => $mode,
            'created_at' => now(),
        ]);
    }

    private function insertChoiceCondition(string $ruleUuid, string $optionUuid, string $choiceUuid): void
    {
        $groupUuid = UuidBinary::generate();

        DB::table('pricing_rule_condition_groups')->insert([
            'id' => UuidBinary::toBinary($groupUuid),
            'pricing_rule_id' => UuidBinary::toBinary($ruleUuid),
            'group_order' => 0,
            'created_at' => now(),
        ]);

        DB::table('pricing_rule_conditions')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'pricing_rule_condition_group_id' => UuidBinary::toBinary($groupUuid),
            'subject_type' => 'OPTION_CHOICE',
            'service_option_id' => UuidBinary::toBinary($optionUuid),
            'operator' => 'EQ',
            'value_choice_id' => UuidBinary::toBinary($choiceUuid),
            'created_at' => now(),
        ]);
    }

    private function serviceCategoryIdFor(string $serviceUuid): int
    {
        return (int) DB::table('services')->where('id', UuidBinary::toBinary($serviceUuid))->value('category_id');
    }
}
