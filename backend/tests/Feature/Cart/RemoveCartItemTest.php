<?php

namespace Tests\Feature\Cart;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Cart\Concerns\CreatesCartFixtures;
use Tests\TestCase;

class RemoveCartItemTest extends TestCase
{
    use CreatesCartFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCartFixtures();
    }

    public function test_remove_one_owned_item(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $serviceA = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($serviceA['uuid']));
        $serviceB = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($serviceB['uuid']));

        $this->addCartItem($customer['access_token'], ['service_uuid' => $serviceA['uuid']]);
        $addedB = $this->addCartItem($customer['access_token'], ['service_uuid' => $serviceB['uuid']]);
        $itemBUuid = $addedB->json('data.cart.items.1.uuid');

        $response = $this->removeCartItem($customer['access_token'], $itemBUuid);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.cart.items'));
        $this->assertDatabaseMissing('cart_items', ['id' => UuidBinary::toBinary($itemBUuid)]);
        $this->assertDatabaseMissing('cart_item_option_selections', ['cart_item_id' => UuidBinary::toBinary($itemBUuid)]);
    }

    public function test_cannot_remove_another_customers_item(): void
    {
        $owner = $this->createAuthenticatedCartCustomer();
        $intruder = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));

        $added = $this->addCartItem($owner['access_token'], ['service_uuid' => $service['uuid']]);
        $itemUuid = $added->json('data.cart.items.0.uuid');

        $this->removeCartItem($intruder['access_token'], $itemUuid)
            ->assertStatus(404);

        $this->assertDatabaseHas('cart_items', ['id' => UuidBinary::toBinary($itemUuid)]);
    }


    public function test_unknown_item_uuid_returns_not_found_on_remove(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->removeCartItem(
            $customer['access_token'],
            UuidBinary::generate()
        )->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_malformed_item_uuid_returns_clean_not_found_on_remove(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->removeCartItem(
            $customer['access_token'],
            'not-a-uuid'
        )->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_requires_auth(): void
    {
        $this->deleteJson('/api/v1/cart/items/'.UuidBinary::generate())
            ->assertStatus(401);
    }
}
