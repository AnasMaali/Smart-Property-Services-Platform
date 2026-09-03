<?php

namespace Tests\Feature\ServiceCatalog;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListServicesTest extends TestCase
{
    use DatabaseTransactions;

    private static int $sequence = 0;

    public function test_services_index_returns_search_payload(): void
    {
        $response = $this->getJson('/api/v1/services?q=clean');

        $response->assertStatus(200)->assertJson([
            'success' => true,
        ])->assertJsonStructure([
            'data' => [
                'query',
                'category',
                'services',
            ],
        ]);
    }

    public function test_capability_filter_returns_only_matching_services_with_capabilities(): void
    {
        $categoryId = DB::table('service_categories')->insertGetId([
            'code' => 'QA_CAP_CAT_'.(++self::$sequence),
            'name' => 'QA Cap Category '.self::$sequence,
            'description' => 'QA fixture',
            'display_order' => 900 + self::$sequence,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $subscriptionUuid = UuidBinary::generate();
        $otherUuid = UuidBinary::generate();

        foreach ([
            [$subscriptionUuid, 'QA_SUB_'.self::$sequence],
            [$otherUuid, 'QA_OTHER_'.self::$sequence],
        ] as [$uuid, $code]) {
            DB::table('services')->insert([
                'id' => UuidBinary::toBinary($uuid),
                'category_id' => $categoryId,
                'code' => $code,
                'slug' => strtolower(str_replace('_', '-', $code)),
                'name' => $code,
                'short_description' => 'QA fixture',
                'description' => 'QA fixture',
                'display_order' => self::$sequence,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('service_capabilities')->insert([
            'service_id' => UuidBinary::toBinary($subscriptionUuid),
            'capability_type_id' => (int) DB::table('service_capability_types')->where('code', 'SUBSCRIPTION')->value('id'),
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/services?capability=SUBSCRIPTION');

        $response->assertStatus(200);
        $uuids = array_column($response->json('data.services'), 'uuid');
        $this->assertContains($subscriptionUuid, $uuids);
        $this->assertNotContains($otherUuid, $uuids);

        $matched = collect($response->json('data.services'))
            ->firstWhere('uuid', $subscriptionUuid);

        $this->assertIsArray($matched);
        $this->assertContains('SUBSCRIPTION', $matched['capabilities']);
        $this->assertSame('QA Cap Category '.self::$sequence, $matched['category']['name']);
    }
}
