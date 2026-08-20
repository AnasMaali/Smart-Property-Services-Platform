<?php

namespace Tests\Feature\ServiceCatalog;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListCategoryServicesTest extends TestCase
{
    use DatabaseTransactions;

    private static int $sequence = 0;

    private int $aedCurrencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aedCurrencyId = (int) DB::table('currencies')->where('code', 'AED')->value('id');
    }

    private function createCategory(array $overrides = []): int
    {
        self::$sequence++;
        $now = now();

        return DB::table('service_categories')->insertGetId([
            'code' => $overrides['code'] ?? 'QA_TEST_CAT_'.self::$sequence,
            'name' => $overrides['name'] ?? 'QA Test Category '.self::$sequence,
            'description' => 'QA test fixture category, not real catalog content.',
            'display_order' => 900 + self::$sequence,
            'is_active' => $overrides['is_active'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createService(int $categoryId, array $overrides = []): string
    {
        self::$sequence++;
        $uuid = UuidBinary::generate();
        $now = now();

        DB::table('services')->insert([
            'id' => UuidBinary::toBinary($uuid),
            'category_id' => $categoryId,
            'code' => $overrides['code'] ?? 'QA_TEST_SERVICE_'.self::$sequence,
            'slug' => $overrides['slug'] ?? 'qa-test-service-'.self::$sequence,
            'name' => $overrides['name'] ?? 'QA Test Service '.self::$sequence,
            'short_description' => $overrides['short_description'] ?? 'QA fixture short description.',
            'description' => 'QA fixture description, not real catalog content.',
            'display_order' => $overrides['display_order'] ?? self::$sequence,
            'is_active' => $overrides['is_active'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }

    private function createMedia(string $serviceUuid, array $overrides = []): void
    {
        $now = now();

        DB::table('service_media')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'service_id' => UuidBinary::toBinary($serviceUuid),
            'storage_key' => $overrides['storage_key'] ?? 'qa/fixtures/'.UuidBinary::generate().'.jpg',
            'mime_type' => 'image/jpeg',
            'alt_text' => $overrides['alt_text'] ?? 'QA fixture image',
            'caption' => null,
            'is_primary' => $overrides['is_primary'] ?? 1,
            'display_order' => $overrides['display_order'] ?? 0,
            'is_active' => $overrides['is_active'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Inserts a PUBLISHED pricing_scheme_versions row plus one unconditional
     * SET_PRICE rule, so the service's pricing_preview resolves to PRICED.
     */
    private function createPrice(string $serviceUuid, array $overrides = []): void
    {
        $now = now();
        $schemeUuid = UuidBinary::generate();

        DB::table('pricing_scheme_versions')->insert([
            'id' => UuidBinary::toBinary($schemeUuid),
            'service_id' => UuidBinary::toBinary($serviceUuid),
            'currency_id' => $this->aedCurrencyId,
            'status' => 'PUBLISHED',
            'effective_from' => $overrides['effective_from'] ?? $now->copy()->subDay(),
            'effective_to' => array_key_exists('effective_to', $overrides) ? $overrides['effective_to'] : null,
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('pricing_rules')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'pricing_scheme_version_id' => UuidBinary::toBinary($schemeUuid),
            'rule_code' => 'BASE_'.$schemeUuid,
            'label' => 'Base price',
            'priority' => 100,
            'effect_type' => 'SET_PRICE',
            'effect_amount' => $overrides['base_amount'] ?? '100.000000',
            'stop_processing' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_active_category_returns_only_its_active_services(): void
    {
        $categoryId = $this->createCategory();
        $activeService = $this->createService($categoryId, ['display_order' => 1]);
        $inactiveService = $this->createService($categoryId, ['display_order' => 2, 'is_active' => 0]);

        $response = $this->getJson("/api/v1/service-categories/{$categoryId}/services");

        $response->assertStatus(200);
        $uuids = collect($response->json('data.services'))->pluck('uuid')->all();

        $this->assertContains($activeService, $uuids);
        $this->assertNotContains($inactiveService, $uuids);
    }

    public function test_services_from_another_category_never_leak(): void
    {
        $categoryA = $this->createCategory();
        $categoryB = $this->createCategory();

        $serviceA = $this->createService($categoryA);
        $serviceB = $this->createService($categoryB);

        $response = $this->getJson("/api/v1/service-categories/{$categoryA}/services");

        $uuids = collect($response->json('data.services'))->pluck('uuid')->all();

        $this->assertContains($serviceA, $uuids);
        $this->assertNotContains($serviceB, $uuids);
    }

    public function test_inactive_category_returns_404(): void
    {
        $categoryId = $this->createCategory(['is_active' => 0]);

        $response = $this->getJson("/api/v1/service-categories/{$categoryId}/services");

        $response->assertStatus(404);
        $this->assertFalse($response->json('success'));
    }

    public function test_unknown_category_returns_404(): void
    {
        $response = $this->getJson('/api/v1/service-categories/999999999/services');

        $response->assertStatus(404);
        $this->assertFalse($response->json('success'));
    }

    public function test_non_numeric_category_returns_404(): void
    {
        $response = $this->getJson('/api/v1/service-categories/not-a-number/services');

        $response->assertStatus(404);
    }

    public function test_services_are_ordered_by_display_order(): void
    {
        $categoryId = $this->createCategory();

        $second = $this->createService($categoryId, ['display_order' => 2]);
        $first = $this->createService($categoryId, ['display_order' => 1]);

        $response = $this->getJson("/api/v1/service-categories/{$categoryId}/services");

        $uuids = collect($response->json('data.services'))->pluck('uuid')->values();

        $this->assertSame($first, $uuids[0]);
        $this->assertSame($second, $uuids[1]);
    }

    public function test_primary_active_media_is_returned(): void
    {
        $categoryId = $this->createCategory();
        $serviceUuid = $this->createService($categoryId);

        $this->createMedia($serviceUuid, ['storage_key' => 'qa/fixtures/primary.jpg', 'is_primary' => 1, 'is_active' => 1]);

        $response = $this->getJson("/api/v1/service-categories/{$categoryId}/services");

        $entry = collect($response->json('data.services'))->firstWhere('uuid', $serviceUuid);

        $this->assertNotNull($entry['primary_image']);
        $this->assertSame('qa/fixtures/primary.jpg', $entry['primary_image']['storage_key']);
    }

    public function test_inactive_media_is_not_exposed_as_primary_image(): void
    {
        $categoryId = $this->createCategory();
        $serviceUuid = $this->createService($categoryId);

        $this->createMedia($serviceUuid, ['storage_key' => 'qa/fixtures/inactive.jpg', 'is_primary' => 1, 'is_active' => 0]);

        $response = $this->getJson("/api/v1/service-categories/{$categoryId}/services");

        $entry = collect($response->json('data.services'))->firstWhere('uuid', $serviceUuid);

        $this->assertNull($entry['primary_image']);
    }

    public function test_currently_effective_price_is_selected(): void
    {
        $categoryId = $this->createCategory();
        $serviceUuid = $this->createService($categoryId);

        $this->createPrice($serviceUuid, ['base_amount' => '123.456000', 'effective_to' => null]);

        $response = $this->getJson("/api/v1/service-categories/{$categoryId}/services");

        $entry = collect($response->json('data.services'))->firstWhere('uuid', $serviceUuid);

        $this->assertSame('PRICED', $entry['pricing_preview']['pricing_status']);
        $this->assertSame('123.456000', $entry['pricing_preview']['unit_total']);
        $this->assertSame('AED', $entry['pricing_preview']['currency']['code']);
    }

    public function test_expired_price_is_excluded(): void
    {
        $categoryId = $this->createCategory();
        $serviceUuid = $this->createService($categoryId);

        $this->createPrice($serviceUuid, [
            'base_amount' => '50.000000',
            'effective_from' => now()->copy()->subDays(10),
            'effective_to' => now()->copy()->subDays(5),
        ]);

        $response = $this->getJson("/api/v1/service-categories/{$categoryId}/services");

        $entry = collect($response->json('data.services'))->firstWhere('uuid', $serviceUuid);

        $this->assertSame('UNAVAILABLE', $entry['pricing_preview']['pricing_status']);
        $this->assertNull($entry['pricing_preview']['unit_total']);
    }

    public function test_future_price_is_excluded(): void
    {
        $categoryId = $this->createCategory();
        $serviceUuid = $this->createService($categoryId);

        $this->createPrice($serviceUuid, [
            'base_amount' => '75.000000',
            'effective_from' => now()->copy()->addDays(5),
            'effective_to' => null,
        ]);

        $response = $this->getJson("/api/v1/service-categories/{$categoryId}/services");

        $entry = collect($response->json('data.services'))->firstWhere('uuid', $serviceUuid);

        $this->assertSame('UNAVAILABLE', $entry['pricing_preview']['pricing_status']);
        $this->assertNull($entry['pricing_preview']['unit_total']);
    }

    public function test_no_current_price_is_handled_safely(): void
    {
        $categoryId = $this->createCategory();
        $serviceUuid = $this->createService($categoryId);

        $response = $this->getJson("/api/v1/service-categories/{$categoryId}/services");

        $response->assertStatus(200);
        $entry = collect($response->json('data.services'))->firstWhere('uuid', $serviceUuid);

        $this->assertArrayHasKey('pricing_preview', $entry);
        $this->assertSame('UNAVAILABLE', $entry['pricing_preview']['pricing_status']);
    }

    // Locks the exact ListCategoryServicesAction shape - category summary,
    // service summary, and nested primary_image/pricing_preview - so any
    // future field added for an internal reason (category_id, is_active,
    // a raw pricing_scheme_id, ...) is caught here.
    public function test_response_exposes_only_the_documented_public_field_set(): void
    {
        $categoryId = $this->createCategory();
        $serviceUuid = $this->createService($categoryId);
        $this->createMedia($serviceUuid, ['is_primary' => 1]);
        $this->createPrice($serviceUuid, ['base_amount' => '50.000000', 'effective_to' => null]);

        $response = $this->getJson("/api/v1/service-categories/{$categoryId}/services");
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertSame(['category', 'services'], array_keys($data));
        $this->assertSame(['id', 'code', 'name', 'description'], array_keys($data['category']));

        $entry = collect($data['services'])->firstWhere('uuid', $serviceUuid);
        $this->assertSame(['uuid', 'code', 'slug', 'name', 'short_description', 'primary_image', 'pricing_preview'], array_keys($entry));
        $this->assertSame(
            ['storage_key', 'mime_type', 'alt_text', 'caption', 'width_pixels', 'height_pixels'],
            array_keys($entry['primary_image'])
        );
        $this->assertSame(['pricing_status', 'unit_total', 'currency'], array_keys($entry['pricing_preview']));
        $this->assertSame(['code', 'symbol', 'minor_unit'], array_keys($entry['pricing_preview']['currency']));

        $raw = $response->getContent();
        foreach (['category_id', 'service_id', 'is_active', 'pricing_rule_id', 'pricing_scheme_id'] as $forbiddenString) {
            $this->assertStringNotContainsString($forbiddenString, $raw, "Category services JSON leaked forbidden field name: {$forbiddenString}");
        }
    }
}
