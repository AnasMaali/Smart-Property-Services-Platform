<?php

namespace Tests\Feature\Property\Concerns;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cart\Concerns\CreatesCartFixtures;

/**
 * Property-specific fixture builders (BLUE V1 Phase 10A), layered on top of
 * CreatesCartFixtures for the customer/session builder - Property tests
 * never need a cart, service, or pricing scheme, only an authenticated
 * customer plus reference-data (area/property_type/property_relationship_type)
 * ids that already exist from the seeded reference data.
 */
trait CreatesPropertyFixtures
{
    use CreatesCartFixtures;

    private static int $propertyFixtureSequence = 0;

    /**
     * @return array<int, int> Two distinct active area ids.
     */
    private function twoDistinctAreaIdsForProperty(): array
    {
        return DB::table('areas')->where('is_active', 1)->orderBy('id')->limit(2)->pluck('id')->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function propertyPayload(array $overrides = []): array
    {
        self::$propertyFixtureSequence++;
        $sequence = self::$propertyFixtureSequence;

        $areaId = $overrides['area_id'] ?? (int) DB::table('areas')->where('is_active', 1)->value('id');
        $propertyTypeId = $overrides['property_type_id'] ?? (int) DB::table('property_types')->where('code', 'APARTMENT')->value('id');
        $relationshipTypeId = $overrides['property_relationship_type_id'] ?? (int) DB::table('property_relationship_types')->where('is_active', 1)->value('id');

        return array_merge([
            'label' => 'Property QA fixture '.$sequence,
            'property_relationship_type_id' => $relationshipTypeId,
            'property_type_id' => $propertyTypeId,
            'area_id' => $areaId,
            'street_name' => 'Sheikh Zayed Road',
            'address_line' => 'Property QA fixture address line '.$sequence,
            'building_name_or_number' => 'Tower '.$sequence,
            'floor_number' => '5',
            'unit_number' => '501',
            'nearby_landmark' => 'Near QA Metro Station',
            'additional_location_notes' => 'QA fixture note, not real address data.',
            'visit_contact_phone' => '+97150'.str_pad((string) $sequence, 7, '0', STR_PAD_LEFT),
        ], $overrides);
    }

    private function listProperties(string $accessToken, array $query = []): TestResponse
    {
        $suffix = $query === [] ? '' : ('?'.http_build_query($query));

        return $this->getJson('/api/v1/properties'.$suffix, ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function createPropertyHttp(string $accessToken, array $payload = []): TestResponse
    {
        return $this->postJson('/api/v1/properties', $this->propertyPayload($payload), ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function getPropertyHttp(string $accessToken, string $propertyUuid): TestResponse
    {
        return $this->getJson('/api/v1/properties/'.$propertyUuid, ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function updatePropertyHttp(string $accessToken, string $propertyUuid, array $payload): TestResponse
    {
        return $this->patchJson('/api/v1/properties/'.$propertyUuid, $payload, ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function deletePropertyHttp(string $accessToken, string $propertyUuid): TestResponse
    {
        return $this->deleteJson('/api/v1/properties/'.$propertyUuid, [], ['Authorization' => 'Bearer '.$accessToken]);
    }

    /**
     * @return array{uuid: string, response: TestResponse}
     */
    private function createProperty(string $accessToken, array $payload = []): array
    {
        $response = $this->createPropertyHttp($accessToken, $payload)->assertStatus(201);

        return ['uuid' => $response->json('data.property.uuid'), 'response' => $response];
    }

    private function propertyRow(string $propertyUuid): ?object
    {
        return DB::table('customer_properties')->where('id', UuidBinary::toBinary($propertyUuid))->first();
    }
}
