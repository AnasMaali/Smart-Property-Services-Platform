<?php

namespace Tests\Feature\Checkout;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Checkout\Concerns\CreatesCheckoutFixtures;
use Tests\TestCase;

class CheckoutLocationTest extends TestCase
{
    use CreatesCheckoutFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    public function test_requires_auth(): void
    {
        $this->putJson('/api/v1/checkout/location', [])
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_no_active_cart_rejected(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        [$areaId] = $this->twoDistinctAreaIds();

        $response = $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId));

        $response->assertStatus(404);
    }

    public function test_save_valid_location_persists_and_returns_it(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        [$areaId] = $this->twoDistinctAreaIds();
        $payload = $this->locationPayload($areaId);

        $response = $this->saveCheckoutLocation($customer['access_token'], $payload);

        $response->assertStatus(200);
        $this->assertSame($areaId, $response->json('data.checkout.location.area.id'));
        $this->assertSame($payload['street_name'], $response->json('data.checkout.location.street_name'));
        $this->assertSame($payload['visit_contact_phone'], $response->json('data.checkout.location.visit_contact_phone'));
        $this->assertNotNull($response->json('data.checkout.location.city.id'));
        $this->assertDatabaseCount('cart_locations', 1);
    }

    public function test_updating_existing_location_replaces_it_without_duplicating(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        [$areaIdA, $areaIdB] = $this->twoDistinctAreaIds();

        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaIdA))->assertStatus(200);
        $response = $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaIdB, ['street_name' => 'New Street Name']));

        $response->assertStatus(200);
        $this->assertSame($areaIdB, $response->json('data.checkout.location.area.id'));
        $this->assertSame('New Street Name', $response->json('data.checkout.location.street_name'));
        $this->assertDatabaseCount('cart_locations', 1);
    }

    public function test_invalid_area_rejected(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $response = $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload(999999999));

        $response->assertStatus(422);
    }

    public function test_inactive_area_rejected(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $inactiveAreaId = DB::table('areas')->insertGetId([
            'city_id' => DB::table('cities')->value('id'),
            'code' => 'CHECKOUT_QA_INACTIVE_AREA',
            'name' => 'Checkout QA Inactive Area',
            'display_order' => 999,
            'is_active' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($inactiveAreaId));

        $response->assertStatus(422);
    }

    public function test_invalid_property_type_rejected(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        [$areaId] = $this->twoDistinctAreaIds();

        $response = $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId, ['property_type_id' => 999999999]));

        $response->assertStatus(422);
    }

    public function test_other_property_type_requires_a_name(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        [$areaId] = $this->twoDistinctAreaIds();

        $response = $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId, [
            'property_type_id' => $this->otherPropertyTypeId,
        ]));

        $response->assertStatus(422);

        $response = $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId, [
            'property_type_id' => $this->otherPropertyTypeId,
            'other_property_type_name' => 'Warehouse Unit',
        ]));

        $response->assertStatus(200);
        $this->assertSame('Warehouse Unit', $response->json('data.checkout.location.other_property_type_name'));
    }

    public function test_another_customer_cannot_affect_another_customers_location(): void
    {
        $customerA = $this->createAuthenticatedCartCustomer();
        $customerB = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();

        $this->addCartItem($customerA['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        $this->addCartItem($customerB['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        [$areaIdA, $areaIdB] = $this->twoDistinctAreaIds();

        $this->saveCheckoutLocation($customerA['access_token'], $this->locationPayload($areaIdA))->assertStatus(200);
        $this->saveCheckoutLocation($customerB['access_token'], $this->locationPayload($areaIdB))->assertStatus(200);

        $checkoutA = $this->getCheckout($customerA['access_token']);
        $this->assertSame($areaIdA, $checkoutA->json('data.checkout.location.area.id'));

        $cartAId = DB::table('carts')->where('customer_user_id', UuidBinary::toBinary($customerA['user_uuid']))->value('id');
        $this->assertDatabaseHas('cart_locations', ['cart_id' => $cartAId, 'area_id' => $areaIdA]);
        $this->assertDatabaseMissing('cart_locations', ['cart_id' => $cartAId, 'area_id' => $areaIdB]);
    }
}
