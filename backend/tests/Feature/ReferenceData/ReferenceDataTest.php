<?php

namespace Tests\Feature\ReferenceData;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReferenceDataTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_endpoint_returns_reference_data_without_authentication(): void
    {
        $response = $this->getJson('/api/v1/reference-data/registration');

        $response->assertStatus(200)->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'cities' => [['id', 'code', 'name', 'areas']],
                'property_relationship_types' => [['id', 'code', 'name']],
                'service_categories' => [['id', 'code', 'name']],
            ],
        ]);
    }

    public function test_only_active_cities_are_returned(): void
    {
        $inactiveCityId = DB::table('cities')->insertGetId([
            'country_id' => DB::table('countries')->where('iso2_code', 'AE')->value('id'),
            'code' => 'INACTIVE_TEST_CITY',
            'name' => 'Inactive Test City',
            'display_order' => 199,
            'is_active' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/reference-data/registration');

        $cityIds = collect($response->json('data.cities'))->pluck('id')->all();
        $this->assertNotContains($inactiveCityId, $cityIds);
    }

    public function test_only_active_areas_are_returned(): void
    {
        $dubaiCityId = (int) DB::table('cities')->where('code', 'DUBAI')->value('id');

        $inactiveAreaId = DB::table('areas')->insertGetId([
            'city_id' => $dubaiCityId,
            'code' => 'INACTIVE_TEST_AREA_LIST',
            'name' => 'Inactive Test Area List',
            'display_order' => 199,
            'is_active' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/reference-data/registration');

        $dubai = collect($response->json('data.cities'))->firstWhere('code', 'DUBAI');
        $areaIds = collect($dubai['areas'])->pluck('id')->all();

        $this->assertNotContains($inactiveAreaId, $areaIds);
    }

    public function test_areas_are_nested_under_the_correct_city(): void
    {
        $response = $this->getJson('/api/v1/reference-data/registration');

        $dubai = collect($response->json('data.cities'))->firstWhere('code', 'DUBAI');
        $abuDhabi = collect($response->json('data.cities'))->firstWhere('code', 'ABU_DHABI');

        $dubaiAreaCodes = collect($dubai['areas'])->pluck('code')->all();
        $abuDhabiAreaCodes = collect($abuDhabi['areas'])->pluck('code')->all();

        $this->assertContains('DUBAI_MARINA', $dubaiAreaCodes);
        $this->assertContains('AL_REEM_ISLAND', $abuDhabiAreaCodes);
        $this->assertNotContains('DUBAI_MARINA', $abuDhabiAreaCodes);
    }

    public function test_only_active_property_relationship_types_are_returned(): void
    {
        $inactiveId = DB::table('property_relationship_types')->insertGetId([
            'code' => 'INACTIVE_TEST_RELATIONSHIP_LIST',
            'name' => 'Inactive Test Relationship List',
            'display_order' => 199,
            'is_active' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/reference-data/registration');

        $ids = collect($response->json('data.property_relationship_types'))->pluck('id')->all();
        $this->assertNotContains($inactiveId, $ids);
    }

    public function test_only_active_service_categories_are_returned(): void
    {
        $inactiveId = DB::table('service_categories')->insertGetId([
            'code' => 'INACTIVE_TEST_CATEGORY_LIST',
            'name' => 'Inactive Test Category List',
            'display_order' => 199,
            'is_active' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/reference-data/registration');

        $ids = collect($response->json('data.service_categories'))->pluck('id')->all();
        $this->assertNotContains($inactiveId, $ids);
    }

    public function test_ordering_matches_display_order(): void
    {
        $response = $this->getJson('/api/v1/reference-data/registration');

        $cityCodes = collect($response->json('data.cities'))->pluck('code')->all();
        $this->assertSame(
            ['ABU_DHABI', 'DUBAI', 'SHARJAH', 'AJMAN', 'UMM_AL_QUWAIN', 'RAS_AL_KHAIMAH', 'FUJAIRAH'],
            $cityCodes
        );

        $categoryCodes = collect($response->json('data.service_categories'))->pluck('code')->all();
        $this->assertSame('AC', $categoryCodes[0]);
        $this->assertSame('OTHER', end($categoryCodes));
    }

    // assertJsonStructure (test_public_endpoint_returns_reference_data_
    // without_authentication) only proves the documented keys are present,
    // never that nothing else is - this locks the exact key set on every
    // entry (city, nested area, property_relationship_type,
    // service_category) so a future field added for an internal reason is
    // caught here.
    public function test_response_exposes_only_the_documented_public_field_set(): void
    {
        $response = $this->getJson('/api/v1/reference-data/registration');
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertSame(['cities', 'property_relationship_types', 'service_categories'], array_keys($data));

        $city = collect($data['cities'])->firstWhere('code', 'DUBAI');
        $this->assertSame(['id', 'code', 'name', 'areas'], array_keys($city));
        $this->assertSame(['id', 'code', 'name'], array_keys($city['areas'][0]));

        $this->assertSame(['id', 'code', 'name', 'description'], array_keys($data['property_relationship_types'][0]));
        $this->assertSame(['id', 'code', 'name', 'description'], array_keys($data['service_categories'][0]));

        $raw = $response->getContent();
        foreach (['is_active', 'display_order', 'country_id', 'city_id', 'created_at', 'updated_at'] as $forbiddenString) {
            $this->assertStringNotContainsString($forbiddenString, $raw, "Reference data JSON leaked forbidden field name: {$forbiddenString}");
        }
    }
}
