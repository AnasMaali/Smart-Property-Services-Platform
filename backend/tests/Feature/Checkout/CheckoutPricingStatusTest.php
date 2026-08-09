<?php

namespace Tests\Feature\Checkout;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Checkout\Concerns\CreatesCheckoutFixtures;
use Tests\TestCase;

class CheckoutPricingStatusTest extends TestCase
{
    use CreatesCheckoutFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    public function test_quote_required_yields_null_total_and_not_ready(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, [
            'effect_type' => 'QUOTE_REQUIRED',
            'effect_amount' => null,
            'stop_processing' => 1,
        ]);

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $response = $this->getCheckout($customer['access_token']);

        $this->assertSame('QUOTE_REQUIRED', $response->json('data.checkout.pricing_status'));
        $this->assertTrue($response->json('data.checkout.requires_quote'));
        $this->assertNull($response->json('data.checkout.total'));
        $this->assertFalse($response->json('data.checkout.ready_for_payment'));
    }

    public function test_unavailable_scheme_after_add_is_reported_and_not_ready(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['effect_amount' => '30.000000']);

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        // The scheme is retired after the item was added - Add/Update never let an item reach
        // UNAVAILABLE, but a later read must still be able to report it (Cart §2 precedent).
        DB::table('pricing_scheme_versions')->where('id', UuidBinary::toBinary($scheme))->update(['effective_to' => now()->subMinute()]);

        $response = $this->getCheckout($customer['access_token']);

        $this->assertSame('UNAVAILABLE', $response->json('data.checkout.pricing_status'));
        $this->assertNull($response->json('data.checkout.total'));
        $this->assertFalse($response->json('data.checkout.ready_for_payment'));
    }

    public function test_fully_priced_checkout_returns_authoritative_total_equal_to_sum_of_line_totals(): void
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

        $response = $this->getCheckout($customer['access_token']);

        $items = $response->json('data.checkout.items');
        $sum = '0.000000';
        foreach ($items as $item) {
            $sum = bcadd($sum, $item['pricing']['line_total'], 6);
        }

        $this->assertSame('PRICED', $response->json('data.checkout.pricing_status'));
        $this->assertSame($sum, $response->json('data.checkout.total'));
        // 40 * 2 + 60 = 140
        $this->assertSame('140.000000', $response->json('data.checkout.total'));
    }

    public function test_client_monetary_fields_cannot_influence_price(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['effect_amount' => '25.000000']);

        [$areaId] = $this->twoDistinctAreaIds();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        // property_type_id/area_id are the only accepted identifiers here - a client-supplied
        // total/price is silently dropped by SaveCheckoutLocationRequest::validated().
        $payload = array_merge($this->locationPayload($areaId), ['total' => '1.000000', 'price' => '1.000000']);
        $this->saveCheckoutLocation($customer['access_token'], $payload)->assertStatus(200);

        $response = $this->getCheckout($customer['access_token']);
        $this->assertSame('25.000000', $response->json('data.checkout.total'));
    }
}
