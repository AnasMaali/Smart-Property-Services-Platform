<?php

namespace Tests\Feature\ServiceCatalog;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GetServiceDetailsTest extends TestCase
{
    use DatabaseTransactions;

    private static int $sequence = 0;

    private int $aedCurrencyId;

    private int $roomUnitId;

    private int $textTypeId;

    private int $numberTypeId;

    private int $singleSelectTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aedCurrencyId = (int) DB::table('currencies')->where('code', 'AED')->value('id');
        $this->roomUnitId = (int) DB::table('measurement_units')->where('code', 'ROOM')->value('id');
        $this->textTypeId = (int) DB::table('service_option_types')->where('code', 'TEXT')->value('id');
        $this->numberTypeId = (int) DB::table('service_option_types')->where('code', 'NUMBER')->value('id');
        $this->singleSelectTypeId = (int) DB::table('service_option_types')->where('code', 'SINGLE_SELECT')->value('id');
    }

    private function createCategory(array $overrides = []): int
    {
        self::$sequence++;
        $now = now();

        return DB::table('service_categories')->insertGetId([
            'code' => 'QA_TEST_CAT_'.self::$sequence,
            'name' => 'QA Test Category '.self::$sequence,
            'description' => 'QA test fixture category, not real catalog content.',
            'display_order' => 900 + self::$sequence,
            'is_active' => $overrides['is_active'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createService(int $categoryId, array $overrides = []): array
    {
        self::$sequence++;
        $uuid = UuidBinary::generate();
        $slug = $overrides['slug'] ?? 'qa-test-service-'.self::$sequence;
        $now = now();

        DB::table('services')->insert([
            'id' => UuidBinary::toBinary($uuid),
            'category_id' => $categoryId,
            'code' => 'QA_TEST_SERVICE_'.self::$sequence,
            'slug' => $slug,
            'name' => 'QA Test Service '.self::$sequence,
            'short_description' => 'QA fixture short description.',
            'description' => 'QA fixture full description, not real catalog content.',
            'display_order' => self::$sequence,
            'is_active' => $overrides['is_active'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['uuid' => $uuid, 'slug' => $slug];
    }

    private function createMedia(string $serviceUuid, array $overrides = []): string
    {
        $uuid = UuidBinary::generate();
        $now = now();

        DB::table('service_media')->insert([
            'id' => UuidBinary::toBinary($uuid),
            'service_id' => UuidBinary::toBinary($serviceUuid),
            'storage_key' => $overrides['storage_key'] ?? 'qa/fixtures/'.$uuid.'.jpg',
            'mime_type' => 'image/jpeg',
            'alt_text' => 'QA fixture image',
            'caption' => null,
            'is_primary' => $overrides['is_primary'] ?? 0,
            'display_order' => $overrides['display_order'] ?? 0,
            'is_active' => $overrides['is_active'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }

    /**
     * Inserts one PUBLISHED pricing_scheme_versions row directly (bypassing
     * SchemePublishValidator, matching the direct-fixture-insert convention
     * already used throughout this file for every other reference row).
     */
    private function createPricingScheme(string $serviceUuid, array $overrides = []): string
    {
        $uuid = UuidBinary::generate();
        $now = now();

        DB::table('pricing_scheme_versions')->insert([
            'id' => UuidBinary::toBinary($uuid),
            'service_id' => UuidBinary::toBinary($serviceUuid),
            'currency_id' => $this->aedCurrencyId,
            'status' => $overrides['status'] ?? 'PUBLISHED',
            'effective_from' => array_key_exists('effective_from', $overrides) ? $overrides['effective_from'] : $now->copy()->subDay(),
            'effective_to' => array_key_exists('effective_to', $overrides) ? $overrides['effective_to'] : null,
            'published_at' => $now,
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
            'code' => $overrides['code'] ?? 'QA_OPTION_'.$uuid,
            'name' => $overrides['name'] ?? 'QA Test Option',
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
            'measurement_unit_id' => array_key_exists('measurement_unit_id', $overrides) ? $overrides['measurement_unit_id'] : $this->roomUnitId,
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
            'updated_at' => $now,
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

    public function test_active_service_by_slug_succeeds(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
    }

    public function test_unknown_slug_returns_404(): void
    {
        $response = $this->getJson('/api/v1/services/does-not-exist-qa-slug');

        $response->assertStatus(404);
        $this->assertFalse($response->json('success'));
    }

    public function test_inactive_service_returns_404(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId, ['is_active' => 0]);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $response->assertStatus(404);
    }

    public function test_full_safe_detail_shape(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);
        $this->createMedia($service['uuid'], ['is_primary' => 1]);

        $scheme = $this->createPricingScheme($service['uuid']);
        $this->createPricingRule($scheme, ['effect_amount' => '100.000000']);

        $textOption = $this->createOption($service['uuid'], $this->textTypeId, ['display_order' => 1]);

        $numberOption = $this->createOption($service['uuid'], $this->numberTypeId, ['display_order' => 2]);
        $this->createNumericRule($numberOption);

        $selectOption = $this->createOption($service['uuid'], $this->singleSelectTypeId, ['display_order' => 3]);
        $this->createSelectionRule($selectOption);
        $this->createChoice($selectOption, ['display_order' => 1]);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $response->assertStatus(200)->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'uuid', 'code', 'slug', 'name', 'short_description', 'description',
                'category' => ['id', 'code', 'name', 'description'],
                'media' => [['uuid', 'storage_key', 'mime_type', 'alt_text', 'caption', 'width_pixels', 'height_pixels', 'is_primary']],
                'pricing_preview' => [
                    'pricing_status', 'currency', 'pricing_scheme_version', 'base_amount',
                    'adjustments', 'unit_total', 'quantity', 'line_total', 'required_context',
                ],
                'options' => [
                    '*' => ['uuid', 'code', 'name', 'description', 'type', 'is_required'],
                ],
            ],
        ]);

        $this->assertSame('PRICED', $response->json('data.pricing_preview.pricing_status'));
        $this->assertSame('100.000000', $response->json('data.pricing_preview.unit_total'));

        $options = $response->json('data.options');
        $numberPayload = collect($options)->firstWhere('uuid', $numberOption);
        $selectPayload = collect($options)->firstWhere('uuid', $selectOption);

        $this->assertArrayHasKey('numeric_rule', $numberPayload);
        $this->assertArrayHasKey('selection_rule', $selectPayload);
        $this->assertArrayHasKey('choices', $selectPayload);
    }

    // assertJsonStructure (test_full_safe_detail_shape) only proves the
    // documented keys are present, never that nothing else is - this locks
    // the exact key set at every level (top level, category, media,
    // numeric_rule/measurement_unit, selection_rule, choices) so a future
    // field added to GetServiceDetailsAction for an internal reason
    // (category_id, option_type_id, is_active, a raw pricing_rule_id, ...)
    // is caught here even if nobody thinks to forbid it by name first.
    public function test_service_detail_response_exposes_only_the_documented_public_field_set(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);
        $this->createMedia($service['uuid'], ['is_primary' => 1]);

        $scheme = $this->createPricingScheme($service['uuid']);
        $this->createPricingRule($scheme, ['effect_amount' => '100.000000']);

        $numberOption = $this->createOption($service['uuid'], $this->numberTypeId);
        $this->createNumericRule($numberOption, ['measurement_unit_id' => $this->roomUnitId]);

        $selectOption = $this->createOption($service['uuid'], $this->singleSelectTypeId);
        $this->createSelectionRule($selectOption);
        $this->createChoice($selectOption);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertSame(
            ['uuid', 'code', 'slug', 'name', 'short_description', 'description', 'is_featured', 'estimated_duration_minutes', 'quantity', 'category', 'media', 'pricing_preview', 'pricing', 'options', 'content_sections', 'checkpoint_groups', 'payment_policy'],
            array_keys($data)
        );
        $this->assertSame(['min', 'max'], array_keys($data['quantity']));
        $this->assertSame(['id', 'code', 'name', 'description'], array_keys($data['category']));
        $this->assertSame(
            ['uuid', 'storage_key', 'mime_type', 'alt_text', 'caption', 'width_pixels', 'height_pixels', 'is_primary'],
            array_keys($data['media'][0])
        );
        $this->assertSame(
            ['pricing_status', 'currency', 'pricing_scheme_version', 'base_amount', 'adjustments', 'unit_total', 'quantity', 'line_total', 'required_context'],
            array_keys($data['pricing_preview'])
        );
        $this->assertSame(['currency', 'original_amount', 'current_amount', 'has_discount'], array_keys($data['pricing']));

        $numberPayload = collect($data['options'])->firstWhere('uuid', $numberOption);
        $this->assertSame(['uuid', 'code', 'name', 'description', 'type', 'is_required', 'numeric_rule'], array_keys($numberPayload));
        $this->assertSame(['min_value', 'max_value', 'step_value', 'default_value', 'decimal_places', 'measurement_unit'], array_keys($numberPayload['numeric_rule']));
        $this->assertSame(['id', 'code', 'name', 'symbol'], array_keys($numberPayload['numeric_rule']['measurement_unit']));

        $selectPayload = collect($data['options'])->firstWhere('uuid', $selectOption);
        $this->assertSame(['uuid', 'code', 'name', 'description', 'type', 'is_required', 'selection_rule', 'choices'], array_keys($selectPayload));
        $this->assertSame(['minimum_selections', 'maximum_selections'], array_keys($selectPayload['selection_rule']));
        $this->assertSame(['uuid', 'code', 'name', 'description', 'attributes'], array_keys($selectPayload['choices'][0]));

        $raw = $response->getContent();
        foreach ([
            'category_id',
            'option_type_id',
            'service_id',
            'is_active',
            'pricing_rule_id',
            'pricing_scheme_id',
            'condition_groups',
            'effect_subject_service_option_id',
            'stop_processing',
            'internal_note',
            'admin_note',
            'created_by_user_id',
            'updated_by_user_id',
            'attribute_type_id',
            'section_type_id',
            'group_id',
            'action_type_id',
            'value_string',
            'value_number',
        ] as $forbiddenString) {
            $this->assertStringNotContainsString($forbiddenString, $raw, "Service detail JSON leaked forbidden field name: {$forbiddenString}");
        }
    }

    public function test_text_option_has_no_invented_rules(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);
        $textOption = $this->createOption($service['uuid'], $this->textTypeId);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $payload = collect($response->json('data.options'))->firstWhere('uuid', $textOption);

        $this->assertArrayNotHasKey('numeric_rule', $payload);
        $this->assertArrayNotHasKey('selection_rule', $payload);
        $this->assertArrayNotHasKey('choices', $payload);
    }

    public function test_active_media_is_ordered_correctly(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);

        $second = $this->createMedia($service['uuid'], ['display_order' => 2, 'storage_key' => 'qa/second.jpg']);
        $first = $this->createMedia($service['uuid'], ['display_order' => 1, 'storage_key' => 'qa/first.jpg']);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $media = collect($response->json('data.media'))->pluck('uuid')->values();

        $this->assertSame($first, $media[0]);
        $this->assertSame($second, $media[1]);
    }

    public function test_options_are_ordered_correctly(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);

        $second = $this->createOption($service['uuid'], $this->textTypeId, ['display_order' => 2]);
        $first = $this->createOption($service['uuid'], $this->textTypeId, ['display_order' => 1]);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $options = collect($response->json('data.options'))->pluck('uuid')->values();

        $this->assertSame($first, $options[0]);
        $this->assertSame($second, $options[1]);
    }

    public function test_inactive_options_are_excluded(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);

        $activeOption = $this->createOption($service['uuid'], $this->textTypeId, ['is_active' => 1]);
        $inactiveOption = $this->createOption($service['uuid'], $this->textTypeId, ['is_active' => 0]);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $uuids = collect($response->json('data.options'))->pluck('uuid')->all();

        $this->assertContains($activeOption, $uuids);
        $this->assertNotContains($inactiveOption, $uuids);
    }

    public function test_active_choices_are_ordered_correctly(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);
        $option = $this->createOption($service['uuid'], $this->singleSelectTypeId);
        $this->createSelectionRule($option);

        $second = $this->createChoice($option, ['display_order' => 2]);
        $first = $this->createChoice($option, ['display_order' => 1]);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $payload = collect($response->json('data.options'))->firstWhere('uuid', $option);
        $choiceUuids = collect($payload['choices'])->pluck('uuid')->values();

        $this->assertSame($first, $choiceUuids[0]);
        $this->assertSame($second, $choiceUuids[1]);
    }

    public function test_inactive_choices_are_excluded(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);
        $option = $this->createOption($service['uuid'], $this->singleSelectTypeId);
        $this->createSelectionRule($option);

        $activeChoice = $this->createChoice($option, ['is_active' => 1]);
        $inactiveChoice = $this->createChoice($option, ['is_active' => 0]);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $payload = collect($response->json('data.options'))->firstWhere('uuid', $option);
        $choiceUuids = collect($payload['choices'])->pluck('uuid')->all();

        $this->assertContains($activeChoice, $choiceUuids);
        $this->assertNotContains($inactiveChoice, $choiceUuids);
    }

    public function test_numeric_rule_values_are_correct(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);
        $option = $this->createOption($service['uuid'], $this->numberTypeId);
        $this->createNumericRule($option, [
            'minimum_value' => '0.000000',
            'maximum_value' => '5.000000',
            'step_value' => '1.000000',
            'default_value' => '1.000000',
            'decimal_places' => 0,
        ]);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $payload = collect($response->json('data.options'))->firstWhere('uuid', $option);

        $this->assertSame('0.000000', $payload['numeric_rule']['min_value']);
        $this->assertSame('5.000000', $payload['numeric_rule']['max_value']);
        $this->assertSame('1.000000', $payload['numeric_rule']['step_value']);
        $this->assertSame('1.000000', $payload['numeric_rule']['default_value']);
        $this->assertSame(0, $payload['numeric_rule']['decimal_places']);
    }

    public function test_numeric_rule_measurement_unit_is_correct(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);
        $option = $this->createOption($service['uuid'], $this->numberTypeId);
        $this->createNumericRule($option, ['measurement_unit_id' => $this->roomUnitId]);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $payload = collect($response->json('data.options'))->firstWhere('uuid', $option);

        $this->assertSame('ROOM', $payload['numeric_rule']['measurement_unit']['code']);
    }

    public function test_selection_rule_min_max_are_correct(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);
        $option = $this->createOption($service['uuid'], $this->singleSelectTypeId);
        $this->createSelectionRule($option, ['minimum_selections' => 1, 'maximum_selections' => 3]);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $payload = collect($response->json('data.options'))->firstWhere('uuid', $option);

        $this->assertSame(1, $payload['selection_rule']['minimum_selections']);
        $this->assertSame(3, $payload['selection_rule']['maximum_selections']);
    }

    public function test_pricing_preview_is_unavailable_when_no_scheme_exists(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $this->assertSame('UNAVAILABLE', $response->json('data.pricing_preview.pricing_status'));
        $this->assertNull($response->json('data.pricing_preview.unit_total'));
    }

    public function test_pricing_preview_excludes_future_scheme_version(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);

        $scheme = $this->createPricingScheme($service['uuid'], ['effective_from' => now()->copy()->addDays(5)]);
        $this->createPricingRule($scheme);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $this->assertSame('UNAVAILABLE', $response->json('data.pricing_preview.pricing_status'));
    }

    public function test_pricing_preview_excludes_expired_scheme_version(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);

        $scheme = $this->createPricingScheme($service['uuid'], [
            'effective_from' => now()->copy()->subDays(10),
            'effective_to' => now()->copy()->subDays(5),
        ]);
        $this->createPricingRule($scheme);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $this->assertSame('UNAVAILABLE', $response->json('data.pricing_preview.pricing_status'));
    }

    public function test_pricing_preview_reports_quote_required(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);

        $scheme = $this->createPricingScheme($service['uuid']);
        $this->createPricingRule($scheme, ['effect_type' => 'QUOTE_REQUIRED', 'effect_amount' => null, 'stop_processing' => 1]);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $this->assertSame('QUOTE_REQUIRED', $response->json('data.pricing_preview.pricing_status'));
        $this->assertNull($response->json('data.pricing_preview.unit_total'));
    }

    // -----------------------------------------------------------------
    // BLUE V1 Phase B23 - additive two-price `pricing` block
    // -----------------------------------------------------------------

    public function test_pricing_block_reports_discount_when_original_exceeds_current(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);
        DB::table('services')->where('id', UuidBinary::toBinary($service['uuid']))->update(['original_price' => '150.000000']);

        $scheme = $this->createPricingScheme($service['uuid']);
        $this->createPricingRule($scheme, ['effect_amount' => '120.000000']);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $response->assertJsonPath('data.pricing.currency', 'AED');
        $response->assertJsonPath('data.pricing.original_amount', '150.000000');
        $response->assertJsonPath('data.pricing.current_amount', '120.000000');
        $this->assertTrue($response->json('data.pricing.has_discount'));
    }

    public function test_pricing_block_reports_no_discount_when_no_original_price_set(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);

        $scheme = $this->createPricingScheme($service['uuid']);
        $this->createPricingRule($scheme, ['effect_amount' => '120.000000']);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $this->assertNull($response->json('data.pricing.original_amount'));
        $response->assertJsonPath('data.pricing.current_amount', '120.000000');
        $this->assertFalse($response->json('data.pricing.has_discount'));
    }

    public function test_pricing_block_current_amount_is_null_when_pricing_unavailable(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);
        DB::table('services')->where('id', UuidBinary::toBinary($service['uuid']))->update(['original_price' => '150.000000']);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $this->assertNull($response->json('data.pricing.current_amount'));
        $this->assertFalse($response->json('data.pricing.has_discount'));
    }

    public function test_pricing_amounts_are_json_strings_never_floats(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);
        DB::table('services')->where('id', UuidBinary::toBinary($service['uuid']))->update(['original_price' => '150.000000']);

        $scheme = $this->createPricingScheme($service['uuid']);
        $this->createPricingRule($scheme, ['effect_amount' => '120.000000']);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");
        $raw = $response->getContent();

        $this->assertStringContainsString('"original_amount":"150.000000"', $raw);
        $this->assertStringContainsString('"current_amount":"120.000000"', $raw);
    }

    public function test_all_identifiers_are_returned_as_uuid_strings_with_no_binary_leak(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);
        $mediaUuid = $this->createMedia($service['uuid'], ['is_primary' => 1]);

        $option = $this->createOption($service['uuid'], $this->singleSelectTypeId);
        $this->createSelectionRule($option);
        $choice = $this->createChoice($option);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");
        $rawBody = $response->getContent();

        $this->assertTrue(mb_check_encoding($rawBody, 'UTF-8'), 'Response body must be valid UTF-8 (no raw binary(16) leaked).');

        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

        $this->assertMatchesRegularExpression($uuidPattern, $response->json('data.uuid'));
        $this->assertSame($service['uuid'], $response->json('data.uuid'));

        $mediaEntry = collect($response->json('data.media'))->firstWhere('uuid', $mediaUuid);
        $this->assertMatchesRegularExpression($uuidPattern, $mediaEntry['uuid']);

        $optionPayload = collect($response->json('data.options'))->firstWhere('uuid', $option);
        $this->assertMatchesRegularExpression($uuidPattern, $optionPayload['uuid']);

        $choicePayload = collect($optionPayload['choices'])->firstWhere('uuid', $choice);
        $this->assertMatchesRegularExpression($uuidPattern, $choicePayload['uuid']);
    }
}
