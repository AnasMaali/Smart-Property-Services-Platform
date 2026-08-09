<?php

namespace Tests\Feature\Cart;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Cart\Concerns\CreatesCartFixtures;
use Tests\TestCase;

class ClearCartTest extends TestCase
{
    use CreatesCartFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCartFixtures();
    }

    public function test_clear_cart_removes_items_but_preserves_cart(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));

        $added = $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']]);
        $cartUuid = $added->json('data.cart.uuid');

        $response = $this->clearCart($customer['access_token']);

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.cart.items'));
        $this->assertSame($cartUuid, $response->json('data.cart.uuid'));
        $this->assertDatabaseHas('carts', ['id' => UuidBinary::toBinary($cartUuid)]);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_clearing_empty_cart_is_safe(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));
        $added = $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']]);
        $itemUuid = $added->json('data.cart.items.0.uuid');
        $this->removeCartItem($customer['access_token'], $itemUuid);

        $this->clearCart($customer['access_token'])
            ->assertStatus(200)
            ->assertJsonPath('data.cart.items', []);
    }

    public function test_clearing_with_no_cart_is_safe_and_creates_no_row(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $response = $this->clearCart($customer['access_token']);

        $response->assertStatus(200);
        $this->assertNull($response->json('data.cart.uuid'));
        $this->assertDatabaseCount('carts', 0);
    }

    public function test_requires_auth(): void
    {
        $this->deleteJson('/api/v1/cart')->assertStatus(401);
    }
}
