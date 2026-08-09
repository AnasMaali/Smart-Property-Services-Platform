<?php

namespace Tests\Feature\Checkout;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Checkout\Concerns\CreatesCheckoutFixtures;
use Tests\TestCase;

/**
 * Proves SERVICE_ZONE and TIME_WINDOW resolve and reprice independently of
 * each other and can combine on a single pricing rule, and that changing
 * either the cart's location or its held slot live-reprices the checkout -
 * there is exactly one PricingEngine::evaluate() call per item, never a
 * second calculation.
 */
class CheckoutContextCombinationTest extends TestCase
{
    use CreatesCheckoutFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    public function test_service_zone_and_time_window_both_resolve_independently(): void
    {
        [$areaId] = $this->twoDistinctAreaIds();
        $zoneId = $this->createServiceZone();
        $this->mapAreaToServiceZone($areaId, $zoneId);
        $windowId = $this->createAppointmentTimeWindow();
        $slot = $this->createAppointmentSlot(['time_window_id' => $windowId]);

        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createContextAttributeConditionRule($scheme, 'SERVICE_ZONE', (string) $zoneId, ['rule_code' => 'ZONE_RULE', 'priority' => 100]);
        $this->createContextAttributeConditionRule($scheme, 'TIME_WINDOW', (string) $windowId, ['rule_code' => 'WINDOW_RULE', 'priority' => 110]);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $beforeAny = $this->getCheckout($customer['access_token']);
        $this->assertSame(['SERVICE_ZONE', 'TIME_WINDOW'], $beforeAny->json('data.checkout.required_context'));

        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);
        $afterZoneOnly = $this->getCheckout($customer['access_token']);
        $this->assertSame(['TIME_WINDOW'], $afterZoneOnly->json('data.checkout.required_context'));

        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);
        $afterBoth = $this->getCheckout($customer['access_token']);
        $this->assertSame([], $afterBoth->json('data.checkout.required_context'));
        $this->assertSame('PRICED', $afterBoth->json('data.checkout.pricing_status'));
    }

    public function test_a_single_rule_can_require_both_service_zone_and_time_window(): void
    {
        [$areaId] = $this->twoDistinctAreaIds();
        $zoneId = $this->createServiceZone();
        $this->mapAreaToServiceZone($areaId, $zoneId);
        $windowId = $this->createAppointmentTimeWindow();
        $slot = $this->createAppointmentSlot(['time_window_id' => $windowId]);

        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);

        $ruleUuid = $this->createCartPricingRule($scheme, [
            'rule_code' => 'ZONE_AND_WINDOW_RULE',
            'priority' => 100,
            'effect_amount' => '200.000000',
            'stop_processing' => 1,
        ]);
        $groupId = UuidBinary::generate();
        $now = now();
        DB::table('pricing_rule_condition_groups')->insert([
            'id' => UuidBinary::toBinary($groupId),
            'pricing_rule_id' => UuidBinary::toBinary($ruleUuid),
            'group_order' => 1,
            'created_at' => $now,
        ]);
        $zoneAttributeId = (int) DB::table('pricing_context_attributes')->where('code', 'SERVICE_ZONE')->value('id');
        $windowAttributeId = (int) DB::table('pricing_context_attributes')->where('code', 'TIME_WINDOW')->value('id');
        DB::table('pricing_rule_conditions')->insert([
            [
                'id' => UuidBinary::toBinary(UuidBinary::generate()),
                'pricing_rule_condition_group_id' => UuidBinary::toBinary($groupId),
                'subject_type' => 'CONTEXT_ATTRIBUTE',
                'context_attribute_id' => $zoneAttributeId,
                'operator' => 'EQ',
                'value_number' => (string) $zoneId,
                'created_at' => $now,
            ],
            [
                'id' => UuidBinary::toBinary(UuidBinary::generate()),
                'pricing_rule_condition_group_id' => UuidBinary::toBinary($groupId),
                'subject_type' => 'CONTEXT_ATTRIBUTE',
                'context_attribute_id' => $windowAttributeId,
                'operator' => 'EQ',
                'value_number' => (string) $windowId,
                'created_at' => $now,
            ],
        ]);
        $this->createCartPricingRule($scheme, ['rule_code' => 'BASE', 'priority' => 200, 'effect_amount' => '25.000000']);

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaId))->assertStatus(200);
        $this->createAppointmentHold($customer['access_token'], $slot['uuid'])->assertStatus(201);

        $response = $this->getCheckout($customer['access_token']);

        $this->assertSame('PRICED', $response->json('data.checkout.pricing_status'));
        $this->assertSame('200.000000', $response->json('data.checkout.total'));
    }

    public function test_changing_location_live_reprices_by_zone(): void
    {
        [$areaIdA, $areaIdB] = $this->twoDistinctAreaIds();
        $zoneA = $this->createServiceZone();
        $zoneB = $this->createServiceZone();
        $this->mapAreaToServiceZone($areaIdA, $zoneA);
        $this->mapAreaToServiceZone($areaIdB, $zoneB);

        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createContextAttributeConditionRule($scheme, 'SERVICE_ZONE', (string) $zoneA, ['rule_code' => 'ZONE_A_RULE', 'priority' => 100, 'effect_amount' => '70.000000', 'stop_processing' => 1]);
        $this->createCartPricingRule($scheme, ['rule_code' => 'BASE', 'priority' => 200, 'effect_amount' => '10.000000']);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaIdA))->assertStatus(200);
        $this->assertSame('70.000000', $this->getCheckout($customer['access_token'])->json('data.checkout.total'));

        $this->saveCheckoutLocation($customer['access_token'], $this->locationPayload($areaIdB))->assertStatus(200);
        $this->assertSame('10.000000', $this->getCheckout($customer['access_token'])->json('data.checkout.total'));
    }

    public function test_changing_held_slot_live_reprices_by_time_window(): void
    {
        $windowA = $this->createAppointmentTimeWindow();
        $windowB = $this->createAppointmentTimeWindow();
        $slotA = $this->createAppointmentSlot(['time_window_id' => $windowA]);
        $slotB = $this->createAppointmentSlot(['time_window_id' => $windowB, 'starts_at' => now()->addDays(2)]);

        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createContextAttributeConditionRule($scheme, 'TIME_WINDOW', (string) $windowA, ['rule_code' => 'WINDOW_A_RULE', 'priority' => 100, 'effect_amount' => '55.000000', 'stop_processing' => 1]);
        $this->createCartPricingRule($scheme, ['rule_code' => 'BASE', 'priority' => 200, 'effect_amount' => '15.000000']);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $this->createAppointmentHold($customer['access_token'], $slotA['uuid'])->assertStatus(201);
        $this->assertSame('55.000000', $this->getCheckout($customer['access_token'])->json('data.checkout.total'));

        $this->createAppointmentHold($customer['access_token'], $slotB['uuid'])->assertStatus(201);
        $this->assertSame('15.000000', $this->getCheckout($customer['access_token'])->json('data.checkout.total'));
    }
}
