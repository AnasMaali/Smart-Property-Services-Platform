<?php

namespace Tests\Feature\ServiceCatalog;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PreviewServicePricingTest extends TestCase
{
    use DatabaseTransactions;

    private static int $sequence = 0;

    private int $aedCurrencyId;

    private int $booleanTypeId;

    private int $singleSelectTypeId;

    private int $numberTypeId;

    private int $roomUnitId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aedCurrencyId = (int) DB::table('currencies')->where('code', 'AED')->value('id');
        $this->booleanTypeId = (int) DB::table('service_option_types')->where('code', 'BOOLEAN')->value('id');
        $this->singleSelectTypeId = (int) DB::table('service_option_types')->where('code', 'SINGLE_SELECT')->value('id');
        $this->numberTypeId = (int) DB::table('service_option_types')->where('code', 'NUMBER')->value('id');
        $this->roomUnitId = (int) DB::table('measurement_units')->where('code', 'ROOM')->value('id');
    }

    public function test_unknown_slug_returns_404(): void
    {
        $response = $this->postJson('/api/v1/services/does-not-exist-qa-preview/pricing-preview');

        $response->assertStatus(404);
        $this->assertFalse($response->json('success'));
        $this->assertNull($response->json('data'));
    }

    public function test_inactive_service_returns_404(): void
    {
        $service = $this->createService($this->createCategory(), ['is_active' => 0]);

        $response = $this->postJson("/api/v1/services/{$service['slug']}/pricing-preview");

        $response->assertStatus(404);
        $this->assertFalse($response->json('success'));
    }

    public function test_valid_service_with_published_scheme_returns_fallback_set_price(): void
    {
        $service = $this->createService($this->createCategory());
        $scheme = $this->createPricingScheme($service['uuid']);
        $this->createPricingRule($scheme, [
            'rule_code' => 'BASE_FALLBACK',
            'label' => 'Base price',
            'effect_amount' => '150.000000',
        ]);

        $response = $this->postJson("/api/v1/services/{$service['slug']}/pricing-preview");

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'message' => 'Pricing preview retrieved successfully.',
        ]);

        $preview = $response->json('data.pricing_preview');

        $this->assertSame('PRICED', $preview['pricing_status']);
        $this->assertSame('AED', $preview['currency']);
        $this->assertSame($scheme, $preview['pricing_scheme_version']);
        $this->assertSame('150.000000', $preview['base_amount']);
        $this->assertSame('150.000000', $preview['unit_total']);
        $this->assertSame('150.000000', $preview['line_total']);
        $this->assertSame(1, $preview['quantity']);
        $this->assertSame('SET_PRICE', $preview['adjustments'][0]['effect_type']);
        $this->assertSame('BASE_FALLBACK', $preview['adjustments'][0]['rule_code']);
    }

    public function test_service_without_pricing_scheme_is_unavailable(): void
    {
        $service = $this->createService($this->createCategory());

        $response = $this->postJson("/api/v1/services/{$service['slug']}/pricing-preview");

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        $this->assertSame('UNAVAILABLE', $response->json('data.pricing_preview.pricing_status'));
        $this->assertNull($response->json('data.pricing_preview.unit_total'));
        $this->assertNull($response->json('data.pricing_preview.line_total'));
        $this->assertNull($response->json('data.pricing_preview.pricing_scheme_version'));
    }

    public function test_draft_scheme_is_unavailable(): void
    {
        $service = $this->createService($this->createCategory());
        $scheme = $this->createPricingScheme($service['uuid'], ['status' => 'DRAFT']);
        $this->createPricingRule($scheme, ['effect_amount' => '99.000000']);

        $response = $this->postJson("/api/v1/services/{$service['slug']}/pricing-preview");

        $this->assertSame('UNAVAILABLE', $response->json('data.pricing_preview.pricing_status'));
        $this->assertNull($response->json('data.pricing_preview.unit_total'));
    }

    public function test_valid_boolean_option_changes_the_preview_price(): void
    {
        $service = $this->createService($this->createCategory());
        $scheme = $this->createPricingScheme($service['uuid']);
        $addonOption = $this->createOption($service['uuid'], $this->booleanTypeId, ['code' => 'ADDON']);

        $this->createPricingRule($scheme, [
            'rule_code' => 'BASE_FALLBACK',
            'priority' => 100,
            'effect_amount' => '100.000000',
        ]);
        $addonRule = $this->createPricingRule($scheme, [
            'rule_code' => 'ADDON_FEE',
            'label' => 'Addon fee',
            'priority' => 200,
            'effect_type' => 'ADD_FIXED',
            'effect_amount' => '30.000000',
        ]);
        $this->attachBooleanCondition($addonRule, $addonOption, true);

        $withoutAddon = $this->postJson("/api/v1/services/{$service['slug']}/pricing-preview");
        $this->assertSame('100.000000', $withoutAddon->json('data.pricing_preview.unit_total'));

        $withAddon = $this->postJson("/api/v1/services/{$service['slug']}/pricing-preview", [
            'options' => [
                ['option_uuid' => $addonOption, 'boolean_value' => true],
            ],
        ]);

        $withAddon->assertStatus(200);
        $this->assertSame('PRICED', $withAddon->json('data.pricing_preview.pricing_status'));
        $this->assertSame('130.000000', $withAddon->json('data.pricing_preview.unit_total'));
        $this->assertSame('130.000000', $withAddon->json('data.pricing_preview.line_total'));
        $this->assertSame('100.000000', $withAddon->json('data.pricing_preview.base_amount'));
    }

    public function test_valid_choice_option_changes_the_preview_price(): void
    {
        $service = $this->createService($this->createCategory());
        $scheme = $this->createPricingScheme($service['uuid']);
        $selectOption = $this->createOption($service['uuid'], $this->singleSelectTypeId, ['code' => 'PACKAGE']);
        $this->createSelectionRule($selectOption);
        $standardChoice = $this->createChoice($selectOption, ['code' => 'STANDARD', 'name' => 'Standard package']);
        $premiumChoice = $this->createChoice($selectOption, ['code' => 'PREMIUM', 'name' => 'Premium package']);

        $this->createPricingRule($scheme, [
            'rule_code' => 'BASE_FALLBACK',
            'priority' => 100,
            'effect_amount' => '80.000000',
        ]);
        $premiumRule = $this->createPricingRule($scheme, [
            'rule_code' => 'PREMIUM_FEE',
            'label' => 'Premium package',
            'priority' => 200,
            'effect_type' => 'ADD_FIXED',
            'effect_amount' => '40.000000',
        ]);
        $this->attachChoiceCondition($premiumRule, $selectOption, $premiumChoice);

        $standard = $this->postJson("/api/v1/services/{$service['slug']}/pricing-preview", [
            'options' => [
                ['option_uuid' => $selectOption, 'choice_uuids' => [$standardChoice]],
            ],
        ]);
        $this->assertSame('80.000000', $standard->json('data.pricing_preview.unit_total'));

        $premium = $this->postJson("/api/v1/services/{$service['slug']}/pricing-preview", [
            'options' => [
                ['option_uuid' => $selectOption, 'choice_uuids' => [$premiumChoice]],
            ],
        ]);
        $premium->assertStatus(200);
        $this->assertSame('120.000000', $premium->json('data.pricing_preview.unit_total'));
    }

    public function test_unknown_option_uuid_is_rejected(): void
    {
        $service = $this->createService($this->createCategory());
        $this->createPricingRule($this->createPricingScheme($service['uuid']));

        $response = $this->postJson("/api/v1/services/{$service['slug']}/pricing-preview", [
            'options' => [
                ['option_uuid' => UuidBinary::generate(), 'boolean_value' => true],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertFalse($response->json('success'));
        $this->assertNotEmpty($response->json('errors'));
    }

    public function test_option_from_another_service_is_rejected(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);
        $other = $this->createService($categoryId);
        $this->createPricingRule($this->createPricingScheme($service['uuid']));
        $foreignOption = $this->createOption($other['uuid'], $this->booleanTypeId);

        $response = $this->postJson("/api/v1/services/{$service['slug']}/pricing-preview", [
            'options' => [
                ['option_uuid' => $foreignOption, 'boolean_value' => true],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertFalse($response->json('success'));
    }

    public function test_required_option_missing_is_rejected(): void
    {
        $service = $this->createService($this->createCategory());
        $this->createPricingRule($this->createPricingScheme($service['uuid']));
        $this->createOption($service['uuid'], $this->booleanTypeId, [
            'code' => 'REQUIRED_FLAG',
            'name' => 'Required Flag',
            'is_required' => 1,
        ]);

        $response = $this->postJson("/api/v1/services/{$service['slug']}/pricing-preview");

        $response->assertStatus(422);
        $this->assertFalse($response->json('success'));
        $this->assertNotEmpty($response->json('errors'));
    }

    public function test_numeric_value_outside_rule_is_rejected(): void
    {
        $service = $this->createService($this->createCategory());
        $this->createPricingRule($this->createPricingScheme($service['uuid']));
        $rooms = $this->createOption($service['uuid'], $this->numberTypeId, ['code' => 'ROOMS']);
        $this->createNumericRule($rooms, [
            'minimum_value' => '1.000000',
            'maximum_value' => '5.000000',
            'step_value' => '1.000000',
            'decimal_places' => 0,
        ]);

        $response = $this->postJson("/api/v1/services/{$service['slug']}/pricing-preview", [
            'options' => [
                ['option_uuid' => $rooms, 'numeric_value' => '9'],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertFalse($response->json('success'));
    }

    public function test_client_price_fields_cannot_control_preview_price(): void
    {
        $service = $this->createService($this->createCategory());
        $scheme = $this->createPricingScheme($service['uuid']);
        $this->createPricingRule($scheme, ['effect_amount' => '220.000000']);

        $response = $this->postJson("/api/v1/services/{$service['slug']}/pricing-preview", [
            'price' => '1.000000',
            'base_amount' => '1.000000',
            'subtotal' => '1.000000',
            'total' => '1.000000',
            'unit_total' => '1.000000',
            'line_total' => '1.000000',
            'currency' => 'USD',
            'pricing_status' => 'PRICED',
            'pricing_rule_id' => 'not-a-real-id',
            'pricing_scheme_id' => 'not-a-real-id',
            'pricing_scheme_version' => 'not-a-real-id',
        ]);

        $response->assertStatus(200);
        $this->assertSame('220.000000', $response->json('data.pricing_preview.unit_total'));
        $this->assertSame('220.000000', $response->json('data.pricing_preview.line_total'));
        $this->assertSame('AED', $response->json('data.pricing_preview.currency'));
        $this->assertSame($scheme, $response->json('data.pricing_preview.pricing_scheme_version'));
    }

    public function test_preview_payload_exposes_only_the_pricing_result_field_set(): void
    {
        $service = $this->createService($this->createCategory());
        $this->createPricingRule($this->createPricingScheme($service['uuid']), ['effect_amount' => '50.000000']);

        $response = $this->postJson("/api/v1/services/{$service['slug']}/pricing-preview");
        $response->assertStatus(200);

        $this->assertSame(['pricing_preview'], array_keys($response->json('data')));
        $this->assertSame(
            [
                'pricing_status',
                'currency',
                'pricing_scheme_version',
                'base_amount',
                'adjustments',
                'unit_total',
                'quantity',
                'line_total',
                'required_context',
            ],
            array_keys($response->json('data.pricing_preview'))
        );

        $raw = $response->getContent();
        foreach ([
            'pricing_rule_id',
            'condition_groups',
            'effect_subject_service_option_id',
            'stop_processing',
        ] as $forbiddenString) {
            $this->assertStringNotContainsString(
                $forbiddenString,
                $raw,
                "Pricing preview JSON leaked forbidden field name: {$forbiddenString}"
            );
        }
    }

    private function createCategory(): int
    {
        self::$sequence++;
        $now = now();

        return DB::table('service_categories')->insertGetId([
            'code' => 'QA_PREVIEW_CAT_'.self::$sequence,
            'name' => 'QA Preview Category '.self::$sequence,
            'description' => 'QA test fixture category, not real catalog content.',
            'display_order' => 900 + self::$sequence,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array{uuid: string, slug: string}
     */
    private function createService(int $categoryId, array $overrides = []): array
    {
        self::$sequence++;
        $uuid = UuidBinary::generate();
        $slug = $overrides['slug'] ?? 'qa-preview-service-'.self::$sequence;
        $now = now();

        DB::table('services')->insert([
            'id' => UuidBinary::toBinary($uuid),
            'category_id' => $categoryId,
            'code' => 'QA_PREVIEW_SERVICE_'.self::$sequence,
            'slug' => $slug,
            'name' => 'QA Preview Service '.self::$sequence,
            'short_description' => 'QA fixture short description.',
            'description' => 'QA fixture full description, not real catalog content.',
            'display_order' => self::$sequence,
            'is_active' => $overrides['is_active'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['uuid' => $uuid, 'slug' => $slug];
    }

    private function createPricingScheme(string $serviceUuid, array $overrides = []): string
    {
        $uuid = UuidBinary::generate();
        $now = now();
        $status = $overrides['status'] ?? 'PUBLISHED';

        DB::table('pricing_scheme_versions')->insert([
            'id' => UuidBinary::toBinary($uuid),
            'service_id' => UuidBinary::toBinary($serviceUuid),
            'currency_id' => $this->aedCurrencyId,
            'status' => $status,
            'effective_from' => array_key_exists('effective_from', $overrides)
                ? $overrides['effective_from']
                : ($status === 'DRAFT' ? null : $now->copy()->subDay()),
            'effective_to' => array_key_exists('effective_to', $overrides) ? $overrides['effective_to'] : null,
            'published_at' => $status === 'PUBLISHED' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }

    private function createPricingRule(string $schemeVersionUuid, array $overrides = []): string
    {
        $uuid = UuidBinary::generate();
        $now = now();

        DB::table('pricing_rules')->insert([
            'id' => UuidBinary::toBinary($uuid),
            'pricing_scheme_version_id' => UuidBinary::toBinary($schemeVersionUuid),
            'rule_code' => $overrides['rule_code'] ?? 'BASE_'.$uuid,
            'label' => $overrides['label'] ?? 'Base price',
            'priority' => $overrides['priority'] ?? 100,
            'effect_type' => $overrides['effect_type'] ?? 'SET_PRICE',
            'effect_amount' => array_key_exists('effect_amount', $overrides) ? $overrides['effect_amount'] : '100.000000',
            'effect_subject_type' => $overrides['effect_subject_type'] ?? null,
            'effect_subject_service_option_id' => isset($overrides['effect_subject_service_option_id'])
                ? UuidBinary::toBinary($overrides['effect_subject_service_option_id'])
                : null,
            'tier_calculation_mode' => $overrides['tier_calculation_mode'] ?? null,
            'stop_processing' => $overrides['stop_processing'] ?? 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }

    private function createOption(string $serviceUuid, int $typeId, array $overrides = []): string
    {
        $uuid = UuidBinary::generate();
        $now = now();

        DB::table('service_options')->insert([
            'id' => UuidBinary::toBinary($uuid),
            'service_id' => UuidBinary::toBinary($serviceUuid),
            'option_type_id' => $typeId,
            'code' => $overrides['code'] ?? 'QA_PREVIEW_OPTION_'.$uuid,
            'name' => $overrides['name'] ?? 'QA Preview Option',
            'description' => 'QA fixture option, not real catalog content.',
            'is_required' => $overrides['is_required'] ?? 0,
            'display_order' => $overrides['display_order'] ?? 0,
            'is_active' => $overrides['is_active'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }

    private function createNumericRule(string $optionUuid, array $overrides = []): void
    {
        $now = now();

        DB::table('service_option_numeric_rules')->insert([
            'service_option_id' => UuidBinary::toBinary($optionUuid),
            'measurement_unit_id' => $overrides['measurement_unit_id'] ?? $this->roomUnitId,
            'minimum_value' => $overrides['minimum_value'] ?? '1.000000',
            'maximum_value' => $overrides['maximum_value'] ?? '10.000000',
            'step_value' => $overrides['step_value'] ?? '1.000000',
            'default_value' => $overrides['default_value'] ?? '2.000000',
            'decimal_places' => $overrides['decimal_places'] ?? 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createSelectionRule(string $optionUuid, array $overrides = []): void
    {
        $now = now();

        DB::table('service_option_selection_rules')->insert([
            'service_option_id' => UuidBinary::toBinary($optionUuid),
            'minimum_selections' => $overrides['minimum_selections'] ?? 1,
            'maximum_selections' => $overrides['maximum_selections'] ?? 1,
            'created_at' => $now,
        ]);
    }

    private function createChoice(string $optionUuid, array $overrides = []): string
    {
        $uuid = UuidBinary::generate();
        $now = now();

        DB::table('service_option_choices')->insert([
            'id' => UuidBinary::toBinary($uuid),
            'service_option_id' => UuidBinary::toBinary($optionUuid),
            'code' => $overrides['code'] ?? 'QA_CHOICE_'.$uuid,
            'name' => $overrides['name'] ?? 'QA Test Choice '.$uuid,
            'description' => 'QA fixture choice, not real catalog content.',
            'display_order' => $overrides['display_order'] ?? 0,
            'is_active' => $overrides['is_active'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }

    private function attachBooleanCondition(string $ruleUuid, string $optionUuid, bool $value): void
    {
        $now = now();
        $groupUuid = UuidBinary::generate();

        DB::table('pricing_rule_condition_groups')->insert([
            'id' => UuidBinary::toBinary($groupUuid),
            'pricing_rule_id' => UuidBinary::toBinary($ruleUuid),
            'group_order' => 1,
            'created_at' => $now,
        ]);

        DB::table('pricing_rule_conditions')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'pricing_rule_condition_group_id' => UuidBinary::toBinary($groupUuid),
            'subject_type' => 'OPTION_BOOLEAN_VALUE',
            'service_option_id' => UuidBinary::toBinary($optionUuid),
            'operator' => 'EQ',
            'value_boolean' => $value ? 1 : 0,
            'created_at' => $now,
        ]);
    }

    private function attachChoiceCondition(string $ruleUuid, string $optionUuid, string $choiceUuid): void
    {
        $now = now();
        $groupUuid = UuidBinary::generate();

        DB::table('pricing_rule_condition_groups')->insert([
            'id' => UuidBinary::toBinary($groupUuid),
            'pricing_rule_id' => UuidBinary::toBinary($ruleUuid),
            'group_order' => 1,
            'created_at' => $now,
        ]);

        DB::table('pricing_rule_conditions')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'pricing_rule_condition_group_id' => UuidBinary::toBinary($groupUuid),
            'subject_type' => 'OPTION_CHOICE',
            'service_option_id' => UuidBinary::toBinary($optionUuid),
            'operator' => 'EQ',
            'value_choice_id' => UuidBinary::toBinary($choiceUuid),
            'created_at' => $now,
        ]);
    }
}
