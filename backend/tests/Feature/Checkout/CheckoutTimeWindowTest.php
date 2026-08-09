<?php

namespace Tests\Feature\Checkout;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Checkout\Concerns\CreatesCheckoutFixtures;
use Tests\TestCase;

/**
 * Proves TIME_WINDOW is resolved exclusively through the cart's current
 * active appointment hold -> its appointment_slots row -> that slot's
 * active appointment_time_windows row - never guessed from clock time,
 * never accepted from the client. See docs/api-contracts/checkout-v1.md
 * "Pricing context trust boundary".
 */
class CheckoutTimeWindowTest extends TestCase
{
    use CreatesCheckoutFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    public function test_active_slot_has_a_time_window_id(): void
    {
        $slot = $this->createAppointmentSlot();

        $this->assertDatabaseHas('appointment_slots', [
            'id' => UuidBinary::toBinary($slot['uuid']),
            'time_window_id' => $slot['time_window_id'],
        ]);
    }

    public function test_active_hold_resolves_time_window_from_its_slot(): void
    {
        $windowId = $this->createAppointmentTimeWindow();
        $slot = $this->createAppointmentSlot(['time_window_id' => $windowId]);

        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['rule_code' => 'BASE', 'priority' => 200, 'effect_amount' => '40.000000']);
        $this->createContextAttributeConditionRule($scheme, 'TIME_WINDOW', (string) $windowId, [
            'rule_code' => 'WINDOW_RULE',
            'priority' => 100,
            'effect_amount' => '150.000000',
            'stop_processing' => 1,
        ]);

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);

        $response = $this->getCheckout($customer['access_token']);

        $this->assertSame('PRICED', $response->json('data.checkout.pricing_status'));
        $this->assertSame('150.000000', $response->json('data.checkout.total'));
    }

    public function test_inactive_time_window_slot_is_not_bookable(): void
    {
        $inactiveWindowId = $this->createAppointmentTimeWindow(['is_active' => 0]);
        $slot = $this->createAppointmentSlot(['time_window_id' => $inactiveWindowId]);

        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        // Excluded from the availability list.
        $slotsResponse = $this->getAppointmentSlots($customer['access_token']);
        $uuids = array_column($slotsResponse->json('data.appointment_slots'), 'uuid');
        $this->assertNotContains($slot['uuid'], $uuids);

        // And rejected outright if a client tries to hold it anyway.
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(404);
    }

    public function test_client_cannot_spoof_time_window_through_the_hold_request(): void
    {
        $windowId = $this->createAppointmentTimeWindow();
        $spoofedWindowId = $windowId + 999;
        $slot = $this->createAppointmentSlot(['time_window_id' => $windowId]);

        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['rule_code' => 'BASE', 'priority' => 200, 'effect_amount' => '20.000000']);
        $this->createContextAttributeConditionRule($scheme, 'TIME_WINDOW', (string) $spoofedWindowId, [
            'rule_code' => 'WINDOW_RULE',
            'priority' => 100,
            'effect_amount' => '999.000000',
            'stop_processing' => 1,
        ]);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        // appointment_slot_uuid is the only accepted key - a client-supplied time_window_id
        // is silently dropped, never reaching CheckoutContextResolver.
        $response = $this->postJson('/api/v1/checkout/appointment-hold', [
            'appointment_slot_uuid' => $slot['uuid'],
            'time_window_id' => $spoofedWindowId,
            'TIME_WINDOW' => $spoofedWindowId,
        ], ['Authorization' => 'Bearer '.$customer['access_token']]);
        $response->assertStatus(201);

        $checkout = $this->getCheckout($customer['access_token']);
        // The real slot's window ($windowId) never matches the spoofed rule's condition.
        $this->assertSame('20.000000', $checkout->json('data.checkout.total'));
    }

    public function test_no_hold_leaves_time_window_missing(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createContextAttributeConditionRule($scheme, 'TIME_WINDOW', '1');
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $response = $this->getCheckout($customer['access_token']);

        $this->assertSame('MISSING_CONTEXT', $response->json('data.checkout.pricing_status'));
        $this->assertSame(['TIME_WINDOW'], $response->json('data.checkout.required_context'));
    }

    public function test_expired_hold_no_longer_supplies_time_window(): void
    {
        $windowId = $this->createAppointmentTimeWindow();
        $slot = $this->createAppointmentSlot(['time_window_id' => $windowId]);

        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createContextAttributeConditionRule($scheme, 'TIME_WINDOW', (string) $windowId);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);
        DB::table('appointment_holds')->update(['held_at' => now()->subHour(), 'expires_at' => now()->subMinute()]);

        $response = $this->getCheckout($customer['access_token']);

        $this->assertNull($response->json('data.checkout.appointment'));
        $this->assertSame('MISSING_CONTEXT', $response->json('data.checkout.pricing_status'));
        $this->assertSame(['TIME_WINDOW'], $response->json('data.checkout.required_context'));
    }
}
