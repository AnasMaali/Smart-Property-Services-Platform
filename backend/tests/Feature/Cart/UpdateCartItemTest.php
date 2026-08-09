<?php

namespace Tests\Feature\Cart;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cart\Concerns\CreatesCartFixtures;
use Tests\TestCase;

class UpdateCartItemTest extends TestCase
{
    use CreatesCartFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCartFixtures();
    }

    public function test_update_quantity_reprices(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']), ['effect_amount' => '25.000000']);

        $added = $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid'], 'quantity' => 1]);
        $itemUuid = $added->json('data.cart.items.0.uuid');

        $response = $this->updateCartItem($customer['access_token'], $itemUuid, ['quantity' => 4]);

        $response->assertStatus(200);
        $this->assertSame(4, $response->json('data.cart.items.0.quantity'));
        $this->assertSame('100.000000', $response->json('data.cart.items.0.pricing.line_total'));
    }

    public function test_update_options_full_replace(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));
        $textOption = $this->createCartOption($service['uuid'], $this->textTypeId, ['code' => 'NOTE']);
        $boolOption = $this->createCartOption($service['uuid'], $this->booleanTypeId, ['code' => 'FLAG']);

        $added = $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [['option_uuid' => $textOption, 'text_value' => 'original note']],
        ]);
        $itemUuid = $added->json('data.cart.items.0.uuid');

        $response = $this->updateCartItem($customer['access_token'], $itemUuid, [
            'options' => [['option_uuid' => $boolOption, 'boolean_value' => true]],
        ]);

        $response->assertStatus(200);
        $options = $response->json('data.cart.items.0.options');
        $this->assertCount(1, $options);
        $this->assertSame($boolOption, $options[0]['option_uuid']);

        $this->assertDatabaseMissing('cart_item_option_selections', [
            'cart_item_id' => UuidBinary::toBinary($itemUuid),
            'service_option_id' => UuidBinary::toBinary($textOption),
        ]);
    }

    public function test_update_rollback_on_validation_failure(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));
        $option = $this->createCartOption($service['uuid'], $this->textTypeId);

        $added = $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'quantity' => 1,
            'options' => [['option_uuid' => $option, 'text_value' => 'keep me']],
        ]);
        $itemUuid = $added->json('data.cart.items.0.uuid');

        $response = $this->updateCartItem($customer['access_token'], $itemUuid, [
            'quantity' => 7,
            'options' => [['option_uuid' => UuidBinary::generate(), 'text_value' => 'nope']],
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseHas('cart_items', [
            'id' => UuidBinary::toBinary($itemUuid),
            'quantity' => 1,
        ]);
        $this->assertDatabaseHas('cart_item_option_selections', [
            'cart_item_id' => UuidBinary::toBinary($itemUuid),
            'service_option_id' => UuidBinary::toBinary($option),
            'text_value' => 'keep me',
        ]);
    }

    public function test_unavailable_is_rejected_on_update(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['effect_amount' => '20.000000']);

        $added = $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']]);
        $itemUuid = $added->json('data.cart.items.0.uuid');

        // Retire the scheme so the service has no currently-effective pricing.
        DB::table('pricing_scheme_versions')->where('id', UuidBinary::toBinary($scheme))->update(['effective_to' => now()->subMinute()]);

        $this->updateCartItem($customer['access_token'], $itemUuid, ['quantity' => 2])
            ->assertStatus(422);

        $this->assertDatabaseHas('cart_items', ['id' => UuidBinary::toBinary($itemUuid), 'quantity' => 1]);
    }

    public function test_cannot_update_another_customers_item(): void
    {
        $owner = $this->createAuthenticatedCartCustomer();
        $intruder = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));

        $added = $this->addCartItem($owner['access_token'], ['service_uuid' => $service['uuid']]);
        $itemUuid = $added->json('data.cart.items.0.uuid');

        $this->updateCartItem($intruder['access_token'], $itemUuid, ['quantity' => 5])
            ->assertStatus(404);

        $this->assertDatabaseHas('cart_items', ['id' => UuidBinary::toBinary($itemUuid), 'quantity' => 1]);
    }

    public function test_unknown_item_uuid_returns_not_found(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->updateCartItem($customer['access_token'], UuidBinary::generate(), ['quantity' => 2])
            ->assertStatus(404);
    }
}
