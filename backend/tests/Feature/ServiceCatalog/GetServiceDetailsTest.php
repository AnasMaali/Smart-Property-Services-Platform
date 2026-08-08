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

    private function createBasePrice(string $serviceUuid, array $overrides = []): void
    {
        $now = now();

        DB::table('service_prices')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'service_id' => UuidBinary::toBinary($serviceUuid),
            'currency_id' => $this->aedCurrencyId,
            'base_amount' => $overrides['base_amount'] ?? '100.000000',
            'effective_from' => $overrides['effective_from'] ?? $now->copy()->subDay(),
            'effective_to' => array_key_exists('effective_to', $overrides) ? $overrides['effective_to'] : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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

    private function createNumericPricingRule(string $optionUuid, array $overrides = []): void
    {
        $now = now();

        DB::table('service_option_numeric_pricing_rules')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'service_option_id' => UuidBinary::toBinary($optionUuid),
            'currency_id' => $this->aedCurrencyId,
            'included_value' => $overrides['included_value'] ?? '1.000000',
            'charge_unit_size' => $overrides['charge_unit_size'] ?? '1.000000',
            'amount_per_unit' => $overrides['amount_per_unit'] ?? '20.000000',
            'minimum_additional_amount' => $overrides['minimum_additional_amount'] ?? '0.000000',
            'maximum_additional_amount' => array_key_exists('maximum_additional_amount', $overrides) ? $overrides['maximum_additional_amount'] : null,
            'effective_from' => $overrides['effective_from'] ?? $now->copy()->subDay(),
            'effective_to' => array_key_exists('effective_to', $overrides) ? $overrides['effective_to'] : null,
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

    private function createChoicePrice(string $choiceUuid, array $overrides = []): void
    {
        $now = now();

        DB::table('service_option_choice_prices')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'service_option_choice_id' => UuidBinary::toBinary($choiceUuid),
            'currency_id' => $this->aedCurrencyId,
            'additional_amount' => $overrides['additional_amount'] ?? '15.000000',
            'effective_from' => $overrides['effective_from'] ?? $now->copy()->subDay(),
            'effective_to' => array_key_exists('effective_to', $overrides) ? $overrides['effective_to'] : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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
        $this->createBasePrice($service['uuid']);

        $textOption = $this->createOption($service['uuid'], $this->textTypeId, ['display_order' => 1]);

        $numberOption = $this->createOption($service['uuid'], $this->numberTypeId, ['display_order' => 2]);
        $this->createNumericRule($numberOption);
        $this->createNumericPricingRule($numberOption);

        $selectOption = $this->createOption($service['uuid'], $this->singleSelectTypeId, ['display_order' => 3]);
        $this->createSelectionRule($selectOption);
        $choice = $this->createChoice($selectOption, ['display_order' => 1]);
        $this->createChoicePrice($choice);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $response->assertStatus(200)->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'uuid', 'code', 'slug', 'name', 'short_description', 'description',
                'category' => ['id', 'code', 'name', 'description'],
                'media' => [['uuid', 'storage_key', 'mime_type', 'alt_text', 'caption', 'width_pixels', 'height_pixels', 'is_primary']],
                'base_price' => ['amount', 'currency' => ['code', 'symbol', 'minor_unit']],
                'options' => [
                    '*' => ['uuid', 'code', 'name', 'description', 'type', 'is_required'],
                ],
            ],
        ]);

        $options = $response->json('data.options');
        $numberPayload = collect($options)->firstWhere('uuid', $numberOption);
        $selectPayload = collect($options)->firstWhere('uuid', $selectOption);

        $this->assertArrayHasKey('numeric_rule', $numberPayload);
        $this->assertArrayHasKey('numeric_pricing_rule', $numberPayload);
        $this->assertArrayHasKey('selection_rule', $selectPayload);
        $this->assertArrayHasKey('choices', $selectPayload);
    }

    public function test_text_option_has_no_invented_rules(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);
        $textOption = $this->createOption($service['uuid'], $this->textTypeId);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $payload = collect($response->json('data.options'))->firstWhere('uuid', $textOption);

        $this->assertArrayNotHasKey('numeric_rule', $payload);
        $this->assertArrayNotHasKey('numeric_pricing_rule', $payload);
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

    public function test_current_choice_additional_price_is_correct(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);
        $option = $this->createOption($service['uuid'], $this->singleSelectTypeId);
        $this->createSelectionRule($option);
        $choice = $this->createChoice($option);
        $this->createChoicePrice($choice, ['additional_amount' => '42.500000']);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $payload = collect($response->json('data.options'))->firstWhere('uuid', $option);
        $choicePayload = collect($payload['choices'])->firstWhere('uuid', $choice);

        $this->assertSame('42.500000', $choicePayload['current_additional_price']['amount']);
        $this->assertSame('AED', $choicePayload['current_additional_price']['currency']['code']);
    }

    public function test_choice_without_current_price_returns_null(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);
        $option = $this->createOption($service['uuid'], $this->singleSelectTypeId);
        $this->createSelectionRule($option);
        $choice = $this->createChoice($option);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $payload = collect($response->json('data.options'))->firstWhere('uuid', $option);
        $choicePayload = collect($payload['choices'])->firstWhere('uuid', $choice);

        $this->assertNull($choicePayload['current_additional_price']);
    }

    public function test_current_numeric_pricing_rule_is_correct(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);
        $option = $this->createOption($service['uuid'], $this->numberTypeId);
        $this->createNumericPricingRule($option, [
            'included_value' => '2.000000',
            'charge_unit_size' => '1.000000',
            'amount_per_unit' => '30.000000',
            'minimum_additional_amount' => '0.000000',
            'maximum_additional_amount' => '300.000000',
        ]);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $payload = collect($response->json('data.options'))->firstWhere('uuid', $option);
        $rule = $payload['numeric_pricing_rule'];

        $this->assertSame('2.000000', $rule['included_value']);
        $this->assertSame('30.000000', $rule['amount_per_unit']);
        $this->assertSame('300.000000', $rule['maximum_additional_amount']);
        $this->assertSame('AED', $rule['currency']['code']);
    }

    public function test_historical_and_future_pricing_are_not_exposed(): void
    {
        $categoryId = $this->createCategory();
        $service = $this->createService($categoryId);

        $this->createBasePrice($service['uuid'], [
            'effective_from' => now()->copy()->subDays(10),
            'effective_to' => now()->copy()->subDays(5),
        ]);

        $numericOption = $this->createOption($service['uuid'], $this->numberTypeId);
        $this->createNumericPricingRule($numericOption, [
            'effective_from' => now()->copy()->addDays(5),
            'effective_to' => null,
        ]);

        $response = $this->getJson("/api/v1/services/{$service['slug']}");

        $this->assertNull($response->json('data.base_price'));

        $payload = collect($response->json('data.options'))->firstWhere('uuid', $numericOption);
        $this->assertNull($payload['numeric_pricing_rule']);
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
