<?php

namespace Tests\Feature\Property;

use Illuminate\Foundation\Testing\DatabaseTransactions;
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

    public function test_customer_can_update_own_property(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);

        $response = $this->updatePropertyHttp($customer['access_token'], $property['uuid'], ['label' => 'Updated label']);

        $response->assertStatus(200);
        $this->assertSame('Updated label', $response->json('data.property.label'));
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
}
