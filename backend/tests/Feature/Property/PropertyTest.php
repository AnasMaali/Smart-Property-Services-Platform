<?php

namespace Tests\Feature\Property;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Property\Concerns\CreatesPropertyFixtures;
use Tests\TestCase;

class PropertyTest extends TestCase
{
    use CreatesPropertyFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCartFixtures();
    }

    public function test_customer_can_create_a_property(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $response = $this->createPropertyHttp($customer['access_token']);

        $response->assertStatus(201)->assertJson(['success' => true]);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->json('data.property.uuid'));
        $this->assertTrue($response->json('data.property.is_active'));
        $this->assertSame('APARTMENT', $response->json('data.property.property_type.code'));
    }

    // CreatePropertyRequest::rules() never declares customer_user_id, id,
    // is_active, created_at, or updated_at, so Illuminate\Http\
    // FormRequest::validated() (the only thing CreatePropertyAction ever
    // reads) strips all of them regardless of what the client sends -
    // proving here that the owner is always server-resolved and the new
    // row's identity/state can never be client-influenced.
    public function test_create_property_ignores_client_supplied_ownership_and_state_fields(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $otherCustomer = $this->createAuthenticatedCartCustomer();

        $spoofedUuid = (string) Str::uuid();

        $response = $this->createPropertyHttp($customer['access_token'], [
            'customer_user_id' => $otherCustomer['user_uuid'],
            'id' => $spoofedUuid,
            'uuid' => $spoofedUuid,
            'is_active' => false,
            'created_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00',
        ]);

        $response->assertStatus(201);
        $propertyUuid = $response->json('data.property.uuid');

        $this->assertNotSame($spoofedUuid, $propertyUuid);
        $this->assertTrue($response->json('data.property.is_active'));

        $row = $this->propertyRow($propertyUuid);
        $this->assertSame($customer['user_uuid'], UuidBinary::toString($row->customer_user_id));
        $this->assertNotSame($otherCustomer['user_uuid'], UuidBinary::toString($row->customer_user_id));
        $this->assertSame(1, (int) $row->is_active);
        $this->assertTrue(Carbon::parse($row->created_at)->greaterThan(Carbon::parse('2000-01-02')));

        // The other customer's own property list must never contain a row
        // this request could have attributed to them.
        $otherCustomerProperties = $this->listProperties($otherCustomer['access_token'])->json('data.properties');
        $this->assertSame([], $otherCustomerProperties);
    }

    public function test_create_requires_other_property_type_name_when_other(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $otherTypeId = (int) \Illuminate\Support\Facades\DB::table('property_types')->where('code', 'OTHER')->value('id');

        $response = $this->createPropertyHttp($customer['access_token'], [
            'property_type_id' => $otherTypeId,
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_create_rejects_malformed_fields(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $response = $this->postJson('/api/v1/properties', ['label' => 'x'], ['Authorization' => 'Bearer '.$customer['access_token']]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_customer_can_list_own_properties(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $this->createProperty($customer['access_token']);
        $this->createProperty($customer['access_token']);

        $response = $this->listProperties($customer['access_token']);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.properties'));
    }

    public function test_customer_can_get_own_property(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);

        $response = $this->getPropertyHttp($customer['access_token'], $property['uuid']);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertSame($property['uuid'], $response->json('data.property.uuid'));
        $this->assertSame([], $response->json('data.contracts'));
    }

    public function test_foreign_customer_cannot_read_another_customers_property(): void
    {
        $owner = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($owner['access_token']);

        $stranger = $this->createAuthenticatedCartCustomer();

        $response = $this->getPropertyHttp($stranger['access_token'], $property['uuid']);

        $response->assertStatus(404);
    }

    public function test_get_with_malformed_uuid_returns_clean_404_json(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $response = $this->getPropertyHttp($customer['access_token'], 'not-a-uuid');

        $response->assertStatus(404)->assertJson(['success' => false]);
    }

    public function test_get_with_foreign_uuid_returns_404(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $response = $this->getPropertyHttp($customer['access_token'], (string) Str::uuid());

        $response->assertStatus(404);
    }

    // A foreign (owned by someone else) Property UUID and a genuinely
    // unknown UUID must be publicly indistinguishable on GET - never
    // "exists but forbidden" vs "does not exist", matching the same
    // convention already proven for update/archive in
    // test_unknown_valid_property_uuid_is_indistinguishable_for_update_and_archive.
    public function test_get_foreign_and_unknown_property_are_publicly_indistinguishable(): void
    {
        $owner = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($owner['access_token']);

        $stranger = $this->createAuthenticatedCartCustomer();

        $foreign = $this->getPropertyHttp($stranger['access_token'], $property['uuid']);
        $unknown = $this->getPropertyHttp($stranger['access_token'], (string) Str::uuid());

        $foreign->assertStatus(404)->assertJson(['success' => false, 'message' => 'Property not found.']);
        $unknown->assertStatus(404)->assertJson(['success' => false, 'message' => 'Property not found.']);
        $this->assertSame($foreign->json('message'), $unknown->json('message'));
    }

    public function test_customer_can_update_own_property(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);

        $response = $this->updatePropertyHttp($customer['access_token'], $property['uuid'], ['label' => 'Updated label']);

        $response->assertStatus(200);
        $this->assertSame('Updated label', $response->json('data.property.label'));
    }

    // Same guarantee as create: UpdatePropertyRequest::rules() never
    // declares customer_user_id, id, or is_active, so a PATCH can never
    // reassign ownership or flip the archived flag by simply including
    // those keys in the payload - only the documented address/label
    // fields (here, label) are ever applied.
    public function test_update_property_ignores_client_supplied_ownership_and_state_fields(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $otherCustomer = $this->createAuthenticatedCartCustomer();
        $spoofedUuid = (string) Str::uuid();

        $response = $this->updatePropertyHttp($customer['access_token'], $property['uuid'], [
            'label' => 'Legitimately updated label',
            'customer_user_id' => $otherCustomer['user_uuid'],
            'id' => $spoofedUuid,
            'is_active' => false,
        ]);

        $response->assertStatus(200);
        $this->assertSame('Legitimately updated label', $response->json('data.property.label'));
        $this->assertTrue($response->json('data.property.is_active'));

        $row = $this->propertyRow($property['uuid']);
        $this->assertSame('Legitimately updated label', $row->label);
        $this->assertSame($customer['user_uuid'], UuidBinary::toString($row->customer_user_id));
        $this->assertNotSame($otherCustomer['user_uuid'], UuidBinary::toString($row->customer_user_id));
        $this->assertSame(1, (int) $row->is_active);

        // The injected ownership never attributed the property to the
        // other customer's own list either.
        $otherCustomerProperties = $this->listProperties($otherCustomer['access_token'])->json('data.properties');
        $this->assertSame([], $otherCustomerProperties);
    }

    public function test_foreign_customer_cannot_update_another_customers_property(): void
    {
        $owner = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($owner['access_token']);

        $stranger = $this->createAuthenticatedCartCustomer();

        $response = $this->updatePropertyHttp($stranger['access_token'], $property['uuid'], ['label' => 'Hijacked']);

        $response->assertStatus(404);
    }

    public function test_customer_can_archive_own_property(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);

        $response = $this->deletePropertyHttp($customer['access_token'], $property['uuid']);

        $response->assertStatus(200);
        $this->assertFalse($response->json('data.property.is_active'));

        $row = $this->propertyRow($property['uuid']);
        $this->assertSame(0, (int) $row->is_active);
    }

    public function test_archiving_is_idempotent(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);

        $this->deletePropertyHttp($customer['access_token'], $property['uuid'])->assertStatus(200);
        $second = $this->deletePropertyHttp($customer['access_token'], $property['uuid']);

        $second->assertStatus(200);
        $this->assertFalse($second->json('data.property.is_active'));
    }

    public function test_archived_property_cannot_be_edited(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $this->deletePropertyHttp($customer['access_token'], $property['uuid'])->assertStatus(200);

        $response = $this->updatePropertyHttp($customer['access_token'], $property['uuid'], ['label' => 'Should fail']);

        $response->assertStatus(409)->assertJson(['success' => false]);
    }

    public function test_archived_property_still_readable_and_remains_historically_queryable(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $this->deletePropertyHttp($customer['access_token'], $property['uuid'])->assertStatus(200);

        $response = $this->getPropertyHttp($customer['access_token'], $property['uuid']);

        $response->assertStatus(200);
        $this->assertFalse($response->json('data.property.is_active'));
    }

    public function test_list_defaults_to_active_only(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $this->deletePropertyHttp($customer['access_token'], $property['uuid'])->assertStatus(200);

        $response = $this->listProperties($customer['access_token']);

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.properties'));

        $all = $this->listProperties($customer['access_token'], ['status' => 'all']);
        $this->assertCount(1, $all->json('data.properties'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/properties');

        $response->assertStatus(401);
    }

    public function test_property_uuids_in_json_are_clean_strings(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);

        $response = $this->getPropertyHttp($customer['access_token'], $property['uuid']);

        $uuid = $response->json('data.property.uuid');
        $this->assertIsString($uuid);
        $this->assertSame(36, strlen($uuid));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid);
    }

    public function test_property_list_never_includes_another_customers_property(): void
    {
        $owner = $this->createAuthenticatedCartCustomer();
        $ownersProperty = $this->createProperty($owner['access_token']);

        $stranger = $this->createAuthenticatedCartCustomer();
        $strangersProperty = $this->createProperty($stranger['access_token']);

        $response = $this->listProperties($stranger['access_token']);

        $response->assertStatus(200);

        $uuids = collect($response->json('data.properties'))
            ->pluck('uuid')
            ->all();

        $this->assertContains($strangersProperty['uuid'], $uuids);
        $this->assertNotContains($ownersProperty['uuid'], $uuids);
    }

    public function test_foreign_customer_update_returns_404_and_does_not_mutate_property(): void
    {
        $owner = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($owner['access_token']);

        $before = $this->propertyRow($property['uuid']);
        $originalLabel = $before->label;

        $stranger = $this->createAuthenticatedCartCustomer();

        $this->updatePropertyHttp(
            $stranger['access_token'],
            $property['uuid'],
            ['label' => 'Unauthorized mutation']
        )->assertStatus(404);

        $after = $this->propertyRow($property['uuid']);

        $this->assertSame($originalLabel, $after->label);
        $this->assertSame(1, (int) $after->is_active);
    }

    public function test_foreign_customer_cannot_archive_another_customers_property(): void
    {
        $owner = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($owner['access_token']);

        $stranger = $this->createAuthenticatedCartCustomer();

        $response = $this->deletePropertyHttp(
            $stranger['access_token'],
            $property['uuid']
        );

        $response->assertStatus(404)
            ->assertJson(['success' => false]);

        $row = $this->propertyRow($property['uuid']);

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->is_active);
    }

    public function test_update_with_malformed_uuid_returns_clean_404_json(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->updatePropertyHttp(
            $customer['access_token'],
            'not-a-uuid',
            ['label' => 'Should never update']
        )->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_archive_with_malformed_uuid_returns_clean_404_json(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->deletePropertyHttp(
            $customer['access_token'],
            'not-a-uuid'
        )->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_unknown_valid_property_uuid_is_indistinguishable_for_update_and_archive(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $unknownUuid = (string) Str::uuid();

        $this->updatePropertyHttp(
            $customer['access_token'],
            $unknownUuid,
            ['label' => 'Does not exist']
        )->assertStatus(404)
            ->assertJson(['success' => false]);

        $this->deletePropertyHttp(
            $customer['access_token'],
            $unknownUuid
        )->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    // Locks the exact PropertyPresenter shape (App\Support\Property\
    // PropertyPresenter::present()) rather than only checking a handful of
    // forbidden keys, so any future field added for an internal reason
    // (e.g. customer_user_id, a raw property_type_id/area_id/
    // property_relationship_type_id foreign key, or an audit/admin field)
    // is caught here even if nobody thinks to forbid it by name first.
    public function test_property_response_exposes_only_the_documented_public_field_set(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);

        $response = $this->getPropertyHttp($customer['access_token'], $property['uuid']);
        $response->assertStatus(200);

        $payload = $response->json('data.property');

        $this->assertSame([
            'uuid', 'label', 'relationship_type', 'property_type', 'other_property_type_name',
            'area', 'street_name', 'address_line', 'building_name_or_number', 'floor_number',
            'unit_number', 'nearby_landmark', 'additional_location_notes', 'visit_contact_phone',
            'is_active', 'created_at', 'updated_at',
        ], array_keys($payload));

        $this->assertSame(['code', 'name'], array_keys($payload['relationship_type']));
        $this->assertSame(['code', 'name'], array_keys($payload['property_type']));
        $this->assertSame(['id', 'name', 'city_name', 'country_name'], array_keys($payload['area']));

        $raw = $response->getContent();
        foreach ([
            'customer_user_id',
            'property_type_id',
            'property_relationship_type_id',
            'created_by_user_id',
            'updated_by_user_id',
            'deleted_by_user_id',
            'deleted_at',
            'internal_note',
            'admin_note',
            'status_history',
        ] as $forbiddenString) {
            $this->assertStringNotContainsString($forbiddenString, $raw, "Property JSON leaked forbidden field name: {$forbiddenString}");
        }

        $this->assertTrue(mb_check_encoding($raw, 'UTF-8'));
        json_decode($raw, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

}
