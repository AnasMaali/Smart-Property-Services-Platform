<?php

namespace Tests\Feature\Cart;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cart\Concerns\CreatesCartFixtures;
use Tests\TestCase;

class GetCartTest extends TestCase
{
    use CreatesCartFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCartFixtures();
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/cart')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_no_cart_returns_safe_empty_response_and_creates_no_row(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $response = $this->getCart($customer['access_token']);

        $response->assertStatus(200);
        $this->assertNull($response->json('data.cart.uuid'));
        $this->assertSame([], $response->json('data.cart.items'));
        $this->assertSame('PRICED', $response->json('data.cart.pricing_status'));
        $this->assertSame('0.000000', $response->json('data.cart.total'));

        $this->assertDatabaseCount('carts', 0);
    }

    public function test_get_recomputes_using_current_pricing(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['effect_amount' => '50.000000']);

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $newScheme = $this->createCartPricingScheme($service['uuid'], ['status' => 'DRAFT']);

        // Expire the original scheme and publish a new amount, proving GET reprices live rather than reusing a stored value.
        DB::table('pricing_scheme_versions')->where('id', UuidBinary::toBinary($scheme))->update(['effective_to' => now()->subMinute()]);
        DB::table('pricing_scheme_versions')->where('id', UuidBinary::toBinary($newScheme))->update([
            'status' => 'PUBLISHED',
            'effective_from' => now()->subMinute(),
        ]);
        $this->createCartPricingRule($newScheme, ['effect_amount' => '75.000000']);

        $response = $this->getCart($customer['access_token']);

        $response->assertStatus(200);
        $this->assertSame('75.000000', $response->json('data.cart.total'));
    }

    public function test_cart_total_equals_sum_when_all_items_priced(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $serviceA = $this->createCartService();
        $schemeA = $this->createCartPricingScheme($serviceA['uuid']);
        $this->createCartPricingRule($schemeA, ['effect_amount' => '40.000000']);

        $serviceB = $this->createCartService();
        $schemeB = $this->createCartPricingScheme($serviceB['uuid']);
        $this->createCartPricingRule($schemeB, ['effect_amount' => '60.000000']);

        $this->addCartItem($customer['access_token'], ['service_uuid' => $serviceA['uuid'], 'quantity' => 2])->assertStatus(201);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $serviceB['uuid']])->assertStatus(201);

        $response = $this->getCart($customer['access_token']);

        // 40 * 2 + 60 = 140
        $this->assertSame('140.000000', $response->json('data.cart.total'));
    }

    public function test_raw_binary_ids_never_leak(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme);

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $response = $this->getCart($customer['access_token']);
        $rawBody = $response->getContent();

        $this->assertTrue(mb_check_encoding($rawBody, 'UTF-8'), 'Response body must be valid UTF-8 (no raw binary(16) leaked).');

        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
        $this->assertMatchesRegularExpression($uuidPattern, $response->json('data.cart.uuid'));
        $this->assertMatchesRegularExpression($uuidPattern, $response->json('data.cart.items.0.uuid'));
        $this->assertMatchesRegularExpression($uuidPattern, $response->json('data.cart.items.0.service.uuid'));
    }

    public function test_no_pricing_rules_or_conditions_leak(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme);

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $response = $this->getCart($customer['access_token']);
        $pricing = $response->json('data.cart.items.0.pricing');

        $this->assertArrayNotHasKey('condition_groups', $pricing);
        $this->assertArrayNotHasKey('rules', $pricing);
        foreach ($pricing['adjustments'] as $adjustment) {
            $this->assertArrayNotHasKey('condition_groups', $adjustment);
            $this->assertArrayHasKey('rule_code', $adjustment);
        }
    }
}
