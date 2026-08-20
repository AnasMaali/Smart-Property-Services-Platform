<?php

namespace Tests\Feature\Checkout;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Checkout\Concerns\CreatesCheckoutFixtures;
use Tests\TestCase;

class GetCheckoutTest extends TestCase
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
        $this->getJson('/api/v1/checkout')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_no_active_cart_returns_safe_empty_checkout(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $response = $this->getCheckout($customer['access_token']);

        $response->assertStatus(200);
        $this->assertNull($response->json('data.checkout.cart_uuid'));
        $this->assertNull($response->json('data.checkout.location'));
        $this->assertNull($response->json('data.checkout.appointment'));
        $this->assertSame([], $response->json('data.checkout.items'));
        $this->assertFalse($response->json('data.checkout.ready_for_payment'));
        $this->assertDatabaseCount('carts', 0);
    }

    public function test_empty_cart_is_not_ready_for_payment(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        $this->removeLastCartItem($customer['access_token']);

        $response = $this->getCheckout($customer['access_token']);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.checkout.cart_uuid'));
        $this->assertSame([], $response->json('data.checkout.items'));
        $this->assertFalse($response->json('data.checkout.ready_for_payment'));
    }

    public function test_checkout_reflects_existing_cart_items(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['effect_amount' => '80.000000']);

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid'], 'quantity' => 2])->assertStatus(201);

        $response = $this->getCheckout($customer['access_token']);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.checkout.items'));
        $this->assertSame($service['uuid'], $response->json('data.checkout.items.0.service.uuid'));
        $this->assertSame(2, $response->json('data.checkout.items.0.quantity'));
        $this->assertSame('160.000000', $response->json('data.checkout.items.0.pricing.line_total'));
        $this->assertSame('160.000000', $response->json('data.checkout.total'));
    }

    public function test_checkout_reprices_live_rather_than_reusing_a_stored_value(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['effect_amount' => '50.000000']);

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $newScheme = $this->createCartPricingScheme($service['uuid'], ['status' => 'DRAFT']);
        DB::table('pricing_scheme_versions')->where('id', UuidBinary::toBinary($scheme))->update(['effective_to' => now()->subMinute()]);
        DB::table('pricing_scheme_versions')->where('id', UuidBinary::toBinary($newScheme))->update([
            'status' => 'PUBLISHED',
            'effective_from' => now()->subMinute(),
        ]);
        $this->createCartPricingRule($newScheme, ['effect_amount' => '90.000000']);

        $response = $this->getCheckout($customer['access_token']);

        $this->assertSame('90.000000', $response->json('data.checkout.total'));
    }

    public function test_raw_binary_ids_never_leak(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $response = $this->getCheckout($customer['access_token']);
        $rawBody = $response->getContent();

        $this->assertTrue(mb_check_encoding($rawBody, 'UTF-8'), 'Response body must be valid UTF-8 (no raw binary(16) leaked).');

        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
        $this->assertMatchesRegularExpression($uuidPattern, $response->json('data.checkout.cart_uuid'));
        $this->assertMatchesRegularExpression($uuidPattern, $response->json('data.checkout.items.0.cart_item_uuid'));
        $this->assertMatchesRegularExpression($uuidPattern, $response->json('data.checkout.items.0.service.uuid'));
    }

    public function test_no_pricing_internals_leak(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $response = $this->getCheckout($customer['access_token']);
        $pricing = $response->json('data.checkout.items.0.pricing');

        $this->assertArrayNotHasKey('condition_groups', $pricing);
        $this->assertArrayNotHasKey('rules', $pricing);
    }

    // Locks the exact CheckoutPresenter shape - top-level plus the nested
    // location/appointment/slot objects - rather than only checking a
    // handful of forbidden fields. appointment_slots.internal_note is a
    // real admin-only schema column (see AppointmentSlotsTest::test_slot_
    // response_contains_only_safe_fields for the list-endpoint equivalent
    // of this same guarantee); this proves the nested slot object embedded
    // in checkout.appointment never carries it either.
    public function test_checkout_response_exposes_only_the_documented_public_field_set(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createPricedCartService();
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        [$areaId] = $this->twoDistinctAreaIds();
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);

        $slot = $this->createAppointmentSlot(['internal_note' => 'Staffing note, never customer-facing.']);
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);

        $response = $this->getCheckout($customer['access_token']);
        $response->assertStatus(200);

        $checkout = $response->json('data.checkout');
        $this->assertSame([
            'cart_uuid', 'location', 'appointment', 'pricing_status', 'required_context',
            'requires_quote', 'ready_for_payment', 'currency', 'items', 'total',
        ], array_keys($checkout));

        $this->assertSame([
            'property_type', 'other_property_type_name', 'area', 'city', 'street_name', 'address_line',
            'building_name_or_number', 'floor_number', 'unit_number', 'nearby_landmark',
            'additional_location_notes', 'visit_contact_phone',
        ], array_keys($checkout['location']));

        $this->assertSame(['hold_uuid', 'slot', 'expires_at'], array_keys($checkout['appointment']));
        $this->assertSame(['uuid', 'starts_at', 'ends_at', 'time_window'], array_keys($checkout['appointment']['slot']));
        $this->assertSame(['code', 'name'], array_keys($checkout['appointment']['slot']['time_window']));

        $raw = $response->getContent();
        foreach ([
            'customer_user_id',
            'cart_id',
            'service_id',
            'status_id',
            'currency_id',
            'pricing_rule_id',
            'internal_note',
        ] as $forbiddenString) {
            $this->assertStringNotContainsString($forbiddenString, $raw, "Checkout JSON leaked forbidden field name: {$forbiddenString}");
        }
    }

    private function removeLastCartItem(string $accessToken): void
    {
        $cart = $this->getCart($accessToken);
        $itemUuid = $cart->json('data.cart.items.0.uuid');
        $this->deleteJson('/api/v1/cart/items/'.$itemUuid, [], ['Authorization' => 'Bearer '.$accessToken])->assertStatus(200);
    }
}
