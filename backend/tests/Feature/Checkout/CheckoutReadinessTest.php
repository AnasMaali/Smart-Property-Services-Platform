<?php

namespace Tests\Feature\Checkout;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Checkout\Concerns\CreatesCheckoutFixtures;
use Tests\TestCase;

/**
 * ready_for_payment is strictly stricter than pricing_status === PRICED:
 * per docs/05-system-requirements/07-business-rules.md, "all services must
 * use the same property information and appointment", so a cart is never
 * ready without both a location and an unexpired appointment hold - even
 * when every item is already fully priced.
 */
class CheckoutReadinessTest extends TestCase
{
    use CreatesCheckoutFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    public function test_missing_location_prevents_ready_for_payment(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['effect_amount' => '20.000000']);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $slot = $this->createAppointmentSlot();
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);

        $response = $this->getCheckout($customer['access_token']);

        $this->assertSame('PRICED', $response->json('data.checkout.pricing_status'));
        $this->assertNull($response->json('data.checkout.location'));
        $this->assertFalse($response->json('data.checkout.ready_for_payment'));
    }

    public function test_missing_appointment_prevents_ready_for_payment(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['effect_amount' => '20.000000']);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);

        $response = $this->getCheckout($customer['access_token']);

        $this->assertSame('PRICED', $response->json('data.checkout.pricing_status'));
        $this->assertNull($response->json('data.checkout.appointment'));
        $this->assertFalse($response->json('data.checkout.ready_for_payment'));
    }

    public function test_valid_priced_checkout_with_location_and_hold_is_ready_for_payment(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['effect_amount' => '20.000000']);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);

        $slot = $this->createAppointmentSlot();
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);

        $response = $this->getCheckout($customer['access_token']);

        $this->assertSame('PRICED', $response->json('data.checkout.pricing_status'));
        $this->assertSame('20.000000', $response->json('data.checkout.total'));
        $this->assertTrue($response->json('data.checkout.ready_for_payment'));
    }

    public function test_expired_hold_reverts_readiness_to_false(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['effect_amount' => '20.000000']);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);

        $slot = $this->createAppointmentSlot();
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);

        DB::table('appointment_holds')->update(['held_at' => now()->subHour(), 'expires_at' => now()->subMinute()]);

        $response = $this->getCheckout($customer['access_token']);

        $this->assertNull($response->json('data.checkout.appointment'));
        $this->assertFalse($response->json('data.checkout.ready_for_payment'));
    }

    public function test_missing_required_service_zone_prevents_ready_for_payment(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createContextAttributeConditionRule($scheme, 'SERVICE_ZONE', '1');
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        // Location is set, but its area has no service-zone mapping.
        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);

        $slot = $this->createAppointmentSlot();
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);

        $response = $this->getCheckout($customer['access_token']);

        $this->assertSame('MISSING_CONTEXT', $response->json('data.checkout.pricing_status'));
        $this->assertFalse($response->json('data.checkout.ready_for_payment'));
    }

    public function test_missing_required_time_window_prevents_ready_for_payment(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createContextAttributeConditionRule($scheme, 'TIME_WINDOW', '1');
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);

        // No appointment hold at all - TIME_WINDOW cannot resolve.
        $response = $this->getCheckout($customer['access_token']);

        $this->assertSame('MISSING_CONTEXT', $response->json('data.checkout.pricing_status'));
        $this->assertFalse($response->json('data.checkout.ready_for_payment'));
    }
}
