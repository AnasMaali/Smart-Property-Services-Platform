<?php

namespace Tests\Feature\ServiceCatalog;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListServiceCategoriesTest extends TestCase
{
    use DatabaseTransactions;

    private static int $sequence = 0;

    private function createCategory(array $overrides = []): array
    {
        self::$sequence++;

        $code = $overrides['code'] ?? 'QA_TEST_CATEGORY_'.self::$sequence;
        $now = now();

        $id = DB::table('service_categories')->insertGetId([
            'code' => $code,
            'name' => $overrides['name'] ?? 'QA Test Category '.self::$sequence,
            'description' => $overrides['description'] ?? 'QA test fixture category, not real catalog content.',
            'display_order' => $overrides['display_order'] ?? 900 + self::$sequence,
            'is_active' => $overrides['is_active'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['id' => $id, 'code' => $code];
    }

    public function test_endpoint_is_public_and_returns_success(): void
    {
        $response = $this->getJson('/api/v1/service-categories');

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        $this->assertSame('Service categories retrieved successfully.', $response->json('message'));
    }

    public function test_only_active_categories_are_returned(): void
    {
        $active = $this->createCategory(['is_active' => 1]);
        $inactive = $this->createCategory(['is_active' => 0]);

        $response = $this->getJson('/api/v1/service-categories');

        $codes = collect($response->json('data.service_categories'))->pluck('code')->all();

        $this->assertContains($active['code'], $codes);
        $this->assertNotContains($inactive['code'], $codes);
    }

    public function test_categories_are_ordered_by_display_order(): void
    {
        $second = $this->createCategory(['display_order' => 950]);
        $first = $this->createCategory(['display_order' => 949]);

        $response = $this->getJson('/api/v1/service-categories');

        $codes = collect($response->json('data.service_categories'))->pluck('code')->values();

        $firstPosition = $codes->search($first['code']);
        $secondPosition = $codes->search($second['code']);

        $this->assertNotFalse($firstPosition);
        $this->assertNotFalse($secondPosition);
        $this->assertLessThan($secondPosition, $firstPosition);
    }

    public function test_response_fields_are_limited_to_safe_display_fields(): void
    {
        $category = $this->createCategory();

        $response = $this->getJson('/api/v1/service-categories');

        $entry = collect($response->json('data.service_categories'))
            ->firstWhere('code', $category['code']);

        $this->assertNotNull($entry);
        $this->assertEqualsCanonicalizing(['id', 'code', 'name', 'description'], array_keys($entry));
    }
}
