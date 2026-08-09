<?php

namespace Tests\Feature\Checkout;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Checkout\Concerns\CreatesCheckoutFixtures;
use Tests\TestCase;

/**
 * Proves SERVICE_ZONE is resolved exclusively through
 * cart_locations.area_id -> service_zone_areas -> an active service_zones
 * row - never area_id directly, never customer_profiles.area_id, and never
 * a client-supplied value. See docs/api-contracts/checkout-v1.md "Pricing
 * context trust boundary".
 */
class CheckoutServiceZoneTest extends TestCase
{
    use CreatesCheckoutFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    public function test_area_mapped_to_active_service_zone_resolves_service_zone(): void
    {
        [$areaId] = $this->twoDistinctAreaIds();
        $zoneId = $this->createServiceZone();
        $this->mapAreaToServiceZone($areaId, $zoneId);

        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['rule_code' => 'BASE', 'priority' => 200, 'effect_amount' => '50.000000']);
        $this->createContextAttributeConditionRule($scheme, 'SERVICE_ZONE', (string) $zoneId, [
            'rule_code' => 'ZONE_RULE',
            'priority' => 100,
            'effect_amount' => '120.000000',
            'stop_processing' => 1,
        ]);

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);

        $response = $this->getCheckout($customer['access_token']);

        $this->assertSame('PRICED', $response->json('data.checkout.pricing_status'));
        $this->assertSame('120.000000', $response->json('data.checkout.total'));
    }

    public function test_different_areas_may_map_to_the_same_service_zone(): void
    {
        [$areaIdA, $areaIdB] = $this->twoDistinctAreaIds();
        $zoneId = $this->createServiceZone();
        $this->mapAreaToServiceZone($areaIdA, $zoneId);
        $this->mapAreaToServiceZone($areaIdB, $zoneId);

        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createContextAttributeConditionRule($scheme, 'SERVICE_ZONE', (string) $zoneId, ['effect_amount' => '90.000000']);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaIdA))->assertStatus(200);
        $fromAreaA = $this->getCheckout($customer['access_token']);
        $this->assertSame('90.000000', $fromAreaA->json('data.checkout.total'));

        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaIdB))->assertStatus(200);
        $fromAreaB = $this->getCheckout($customer['access_token']);
        $this->assertSame('90.000000', $fromAreaB->json('data.checkout.total'));
    }

    public function test_unmapped_area_does_not_resolve_a_service_zone(): void
    {
        [$areaId] = $this->twoDistinctAreaIds();
        // Deliberately no service_zone_areas row for this area.

        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createContextAttributeConditionRule($scheme, 'SERVICE_ZONE', '1');
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);

        $response = $this->getCheckout($customer['access_token']);

        $this->assertSame('MISSING_CONTEXT', $response->json('data.checkout.pricing_status'));
        $this->assertSame(['SERVICE_ZONE'], $response->json('data.checkout.required_context'));
        $this->assertNull($response->json('data.checkout.total'));
    }

    public function test_inactive_service_zone_is_not_resolved(): void
    {
        [$areaId] = $this->twoDistinctAreaIds();
        $zoneId = $this->createServiceZone(['is_active' => 0]);
        $this->mapAreaToServiceZone($areaId, $zoneId);

        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createContextAttributeConditionRule($scheme, 'SERVICE_ZONE', (string) $zoneId);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);

        $response = $this->getCheckout($customer['access_token']);

        $this->assertSame('MISSING_CONTEXT', $response->json('data.checkout.pricing_status'));
        $this->assertSame(['SERVICE_ZONE'], $response->json('data.checkout.required_context'));
    }

    public function test_profile_area_never_affects_service_zone(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $profileAreaId = (int) DB::table('customer_profiles')
            ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->value('area_id');

        // Map a zone to the profile's own area - if the resolver ever read the profile,
        // this would resolve SERVICE_ZONE even though no cart_locations row exists yet.
        $zoneId = $this->createServiceZone();
        $this->mapAreaToServiceZone($profileAreaId, $zoneId);

        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createContextAttributeConditionRule($scheme, 'SERVICE_ZONE', (string) $zoneId);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $response = $this->getCheckout($customer['access_token']);

        $this->assertSame('MISSING_CONTEXT', $response->json('data.checkout.pricing_status'));
        $this->assertSame(['SERVICE_ZONE'], $response->json('data.checkout.required_context'));
    }

    public function test_client_cannot_spoof_service_zone(): void
    {
        [$areaId] = $this->twoDistinctAreaIds();
        $realZoneId = $this->createServiceZone();
        $this->mapAreaToServiceZone($areaId, $realZoneId);
        $spoofedZoneId = $realZoneId + 999;

        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createContextAttributeConditionRule($scheme, 'SERVICE_ZONE', (string) $spoofedZoneId, ['effect_amount' => '999.000000']);
        $this->createCartPricingRule($scheme, ['rule_code' => 'BASE', 'priority' => 200, 'effect_amount' => '30.000000']);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        // save_checkout_location only accepts area_id/property_type_id/address fields -
        // there is no service_zone_id request field to submit.
        $payload = array_merge($this->locationPayload($areaId), ['service_zone_id' => $spoofedZoneId, 'SERVICE_ZONE' => $spoofedZoneId]);
        $this->saveCheckoutLocation($customer['access_token'], $payload)->assertStatus(200);

        $response = $this->getCheckout($customer['access_token']);

        // The real resolved zone ($realZoneId) never matches the spoofed rule's condition,
        // so the BASE fallback fires instead of the (client-attempted) spoofed amount.
        $this->assertSame('30.000000', $response->json('data.checkout.total'));
    }
}
