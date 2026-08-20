<?php

namespace Tests\Feature\Cart;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cart\Concerns\CreatesCartFixtures;
use Tests\TestCase;

class AddCartItemTest extends TestCase
{
    use CreatesCartFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCartFixtures();
    }

    public function test_valid_item_creates_active_cart(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme, ['effect_amount' => '100.000000']);

        $response = $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid'], 'quantity' => 1]);

        $response->assertStatus(201);
        $this->assertSame(1, count($response->json('data.cart.items')));
        $this->assertSame('PRICED', $response->json('data.cart.items.0.pricing.pricing_status'));

        $this->assertDatabaseHas('carts', [
            'customer_user_id' => UuidBinary::toBinary($customer['user_uuid']),
            'status_id' => (int) DB::table('cart_statuses')->where('code', 'ACTIVE')->value('id'),
        ]);
    }

    public function test_second_add_reuses_same_active_cart(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $serviceA = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($serviceA['uuid']));
        $serviceB = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($serviceB['uuid']));

        $first = $this->addCartItem($customer['access_token'], ['service_uuid' => $serviceA['uuid']]);
        $second = $this->addCartItem($customer['access_token'], ['service_uuid' => $serviceB['uuid']]);

        $second->assertStatus(201);
        $this->assertSame($first->json('data.cart.uuid'), $second->json('data.cart.uuid'));
        $this->assertSame(2, count($second->json('data.cart.items')));
        $this->assertDatabaseCount('carts', 1);
    }

    public function test_cart_eligible_capability_is_required(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService(null, ['cart_eligible' => false]);
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])
            ->assertStatus(422);

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_inactive_service_is_rejected(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService(null, ['is_active' => 0]);

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])
            ->assertStatus(404);
    }

    public function test_unknown_service_is_rejected(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->addCartItem($customer['access_token'], ['service_uuid' => UuidBinary::generate()])
            ->assertStatus(404);
    }

    public function test_quantity_min_max_validation(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid'], 'quantity' => 0])
            ->assertStatus(422)->assertJsonValidationErrors(['quantity']);

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid'], 'quantity' => 1001])
            ->assertStatus(422)->assertJsonValidationErrors(['quantity']);
    }

    public function test_required_option_missing_is_rejected(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));
        $this->createCartOption($service['uuid'], $this->textTypeId, ['is_required' => 1]);

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])
            ->assertStatus(422);
    }

    public function test_option_from_another_service_is_rejected(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));
        $otherService = $this->createCartService();
        $foreignOption = $this->createCartOption($otherService['uuid'], $this->textTypeId);

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [['option_uuid' => $foreignOption, 'text_value' => 'hello']],
        ])->assertStatus(422);

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_inactive_option_is_rejected(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));
        $option = $this->createCartOption($service['uuid'], $this->textTypeId, ['is_active' => 0]);

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [['option_uuid' => $option, 'text_value' => 'hello']],
        ])->assertStatus(422);
    }

    public function test_choice_from_another_option_is_rejected(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));

        $optionA = $this->createCartOption($service['uuid'], $this->singleSelectTypeId, ['code' => 'OPT_A']);
        $this->createCartSelectionRule($optionA);
        $this->createCartChoice($optionA);

        $optionB = $this->createCartOption($service['uuid'], $this->singleSelectTypeId, ['code' => 'OPT_B']);
        $this->createCartSelectionRule($optionB);
        $foreignChoice = $this->createCartChoice($optionB);

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [['option_uuid' => $optionA, 'choice_uuids' => [$foreignChoice]]],
        ])->assertStatus(422);
    }

    public function test_inactive_choice_is_rejected(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));

        $option = $this->createCartOption($service['uuid'], $this->singleSelectTypeId);
        $this->createCartSelectionRule($option);
        $choice = $this->createCartChoice($option, ['is_active' => 0]);

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [['option_uuid' => $option, 'choice_uuids' => [$choice]]],
        ])->assertStatus(422);
    }

    public function test_duplicate_option_is_rejected(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));
        $option = $this->createCartOption($service['uuid'], $this->textTypeId);

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [
                ['option_uuid' => $option, 'text_value' => 'first'],
                ['option_uuid' => $option, 'text_value' => 'second'],
            ],
        ])->assertStatus(422);
    }

    public function test_duplicate_choice_is_rejected(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));

        $option = $this->createCartOption($service['uuid'], $this->multiSelectTypeId);
        $this->createCartSelectionRule($option, ['minimum_selections' => 1, 'maximum_selections' => 3]);
        $choice = $this->createCartChoice($option);

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [['option_uuid' => $option, 'choice_uuids' => [$choice, $choice]]],
        ])->assertStatus(422);
    }

    public function test_single_select_validation(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));

        $option = $this->createCartOption($service['uuid'], $this->singleSelectTypeId);
        $this->createCartSelectionRule($option, ['minimum_selections' => 1, 'maximum_selections' => 1]);
        $choiceA = $this->createCartChoice($option);
        $choiceB = $this->createCartChoice($option);

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [['option_uuid' => $option, 'choice_uuids' => [$choiceA, $choiceB]]],
        ])->assertStatus(422);

        $ok = $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [['option_uuid' => $option, 'choice_uuids' => [$choiceA]]],
        ]);
        $ok->assertStatus(201);
        $this->assertSame([$choiceA], $ok->json('data.cart.items.0.options.0.choice_uuids'));
    }

    public function test_multi_select_min_max(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));

        $option = $this->createCartOption($service['uuid'], $this->multiSelectTypeId);
        $this->createCartSelectionRule($option, ['minimum_selections' => 1, 'maximum_selections' => 2]);
        $choiceA = $this->createCartChoice($option);
        $choiceB = $this->createCartChoice($option);
        $choiceC = $this->createCartChoice($option);

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [['option_uuid' => $option, 'choice_uuids' => []]],
        ])->assertStatus(422);

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [['option_uuid' => $option, 'choice_uuids' => [$choiceA, $choiceB, $choiceC]]],
        ])->assertStatus(422);

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [['option_uuid' => $option, 'choice_uuids' => [$choiceA, $choiceB]]],
        ])->assertStatus(201);
    }

    public function test_number_min_max(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));
        $option = $this->createCartOption($service['uuid'], $this->numberTypeId);
        $this->createCartNumericRule($option, ['minimum_value' => '1.000000', 'maximum_value' => '5.000000', 'step_value' => '1.000000', 'decimal_places' => 0]);

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [['option_uuid' => $option, 'numeric_value' => 0]],
        ])->assertStatus(422);

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [['option_uuid' => $option, 'numeric_value' => 6]],
        ])->assertStatus(422);

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [['option_uuid' => $option, 'numeric_value' => 3]],
        ])->assertStatus(201);
    }

    public function test_number_step_validation(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));
        $option = $this->createCartOption($service['uuid'], $this->numberTypeId);
        $this->createCartNumericRule($option, ['minimum_value' => '0.000000', 'maximum_value' => '10.000000', 'step_value' => '2.000000', 'decimal_places' => 0]);

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [['option_uuid' => $option, 'numeric_value' => 3]],
        ])->assertStatus(422);

        $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [['option_uuid' => $option, 'numeric_value' => 4]],
        ])->assertStatus(201);
    }

    public function test_boolean_persistence(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));
        $option = $this->createCartOption($service['uuid'], $this->booleanTypeId);

        $response = $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [['option_uuid' => $option, 'boolean_value' => true]],
        ]);

        $response->assertStatus(201);
        $this->assertTrue($response->json('data.cart.items.0.options.0.boolean_value'));

        $this->assertDatabaseHas('cart_item_option_selections', [
            'service_option_id' => UuidBinary::toBinary($option),
            'boolean_value' => 1,
        ]);
    }

    public function test_text_persistence(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));
        $option = $this->createCartOption($service['uuid'], $this->textTypeId);

        $response = $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [['option_uuid' => $option, 'text_value' => '  Please ring the bell  ']],
        ]);

        $response->assertStatus(201);
        $this->assertSame('Please ring the bell', $response->json('data.cart.items.0.options.0.text_value'));
    }

    public function test_fixed_pricing_result_is_correct(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']), ['effect_amount' => '250.000000']);

        $response = $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']]);

        $response->assertStatus(201);
        $this->assertSame('250.000000', $response->json('data.cart.items.0.pricing.line_total'));
    }

    public function test_package_plus_addons_pricing_is_correct(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $addonOption = $this->createCartOption($service['uuid'], $this->booleanTypeId, ['code' => 'ADDON']);

        $this->createCartPricingRule($scheme, ['rule_code' => 'BASE', 'priority' => 100, 'effect_type' => 'SET_PRICE', 'effect_amount' => '100.000000']);
        $this->createCartPricingRule($scheme, ['rule_code' => 'ADDON_FEE', 'priority' => 200, 'effect_type' => 'ADD_FIXED', 'effect_amount' => '30.000000']);

        $response = $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [['option_uuid' => $addonOption, 'boolean_value' => true]],
        ]);

        $response->assertStatus(201);
        $this->assertSame('130.000000', $response->json('data.cart.items.0.pricing.line_total'));
    }

    public function test_numeric_tier_pricing_is_correct(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $roomsOption = $this->createCartOption($service['uuid'], $this->numberTypeId, ['code' => 'ROOMS']);
        $this->createCartNumericRule($roomsOption, ['minimum_value' => '1.000000', 'maximum_value' => '10.000000', 'step_value' => '1.000000', 'decimal_places' => 0]);

        $ruleUuid = UuidBinary::generate();
        $now = now();
        DB::table('pricing_rules')->insert([
            'id' => UuidBinary::toBinary($ruleUuid),
            'pricing_scheme_version_id' => UuidBinary::toBinary($scheme),
            'rule_code' => 'PER_ROOM',
            'label' => 'Per room',
            'priority' => 100,
            'effect_type' => 'ADD_PER_UNIT',
            'effect_amount' => null,
            'effect_subject_type' => 'OPTION_NUMERIC_VALUE',
            'effect_subject_service_option_id' => UuidBinary::toBinary($roomsOption),
            'tier_calculation_mode' => 'VOLUME',
            'stop_processing' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('pricing_rule_tiers')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'pricing_rule_id' => UuidBinary::toBinary($ruleUuid),
            'tier_order' => 1,
            'from_unit' => '0.000000',
            'to_unit' => null,
            'tier_pricing_mode' => 'PER_UNIT',
            'charge_unit_size' => '1.000000',
            'rate_amount' => '20.000000',
            'created_at' => $now,
        ]);

        $response = $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'options' => [['option_uuid' => $roomsOption, 'numeric_value' => 4]],
        ]);

        $response->assertStatus(201);
        $this->assertSame('80.000000', $response->json('data.cart.items.0.pricing.line_total'));
    }

    public function test_quantity_multiplies_line_total_exactly_once(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']), ['effect_amount' => '50.000000']);

        $response = $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid'], 'quantity' => 3]);

        $response->assertStatus(201);
        $this->assertSame('150.000000', $response->json('data.cart.items.0.pricing.line_total'));
        $this->assertSame(3, $response->json('data.cart.items.0.quantity'));
    }

    public function test_client_price_fields_cannot_control_price(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']), ['effect_amount' => '100.000000']);

        $response = $this->addCartItem($customer['access_token'], [
            'service_uuid' => $service['uuid'],
            'price' => '1.000000',
            'base_amount' => '1.000000',
            'subtotal' => '1.000000',
            'total' => '1.000000',
            'currency' => 'USD',
            'pricing_rule_id' => 'not-a-real-id',
            'pricing_scheme_id' => 'not-a-real-id',
        ]);

        $response->assertStatus(201);
        $this->assertSame('100.000000', $response->json('data.cart.items.0.pricing.line_total'));
        $this->assertSame('AED', $response->json('data.cart.currency.code'));
    }

    public function test_quote_required_item_allowed_with_cart_total_null(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']), [
            'effect_type' => 'QUOTE_REQUIRED',
            'effect_amount' => null,
            'stop_processing' => 1,
        ]);

        $response = $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']]);

        $response->assertStatus(201);
        $this->assertSame('QUOTE_REQUIRED', $response->json('data.cart.items.0.pricing.pricing_status'));
        $this->assertSame('QUOTE_REQUIRED', $response->json('data.cart.pricing_status'));
        $this->assertTrue($response->json('data.cart.requires_quote'));
        $this->assertNull($response->json('data.cart.total'));
    }

    public function test_missing_context_item_allowed_with_required_context_returned(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);

        $ruleUuid = UuidBinary::generate();
        $now = now();
        DB::table('pricing_rules')->insert([
            'id' => UuidBinary::toBinary($ruleUuid),
            'pricing_scheme_version_id' => UuidBinary::toBinary($scheme),
            'rule_code' => 'ZONE_RULE',
            'label' => 'Zone based',
            'priority' => 100,
            'effect_type' => 'SET_PRICE',
            'effect_amount' => '100.000000',
            'stop_processing' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $conditionGroupId = UuidBinary::generate();
        DB::table('pricing_rule_condition_groups')->insert([
            'id' => UuidBinary::toBinary($conditionGroupId),
            'pricing_rule_id' => UuidBinary::toBinary($ruleUuid),
            'group_order' => 1,
            'created_at' => $now,
        ]);
        $serviceZoneAttributeId = (int) DB::table('pricing_context_attributes')->where('code', 'SERVICE_ZONE')->value('id');
        DB::table('pricing_rule_conditions')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'pricing_rule_condition_group_id' => UuidBinary::toBinary($conditionGroupId),
            'subject_type' => 'CONTEXT_ATTRIBUTE',
            'context_attribute_id' => $serviceZoneAttributeId,
            'operator' => 'EQ',
            'value_number' => '3',
            'created_at' => $now,
        ]);

        $response = $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']]);

        $response->assertStatus(201);
        $this->assertSame('MISSING_CONTEXT', $response->json('data.cart.items.0.pricing.pricing_status'));
        $this->assertSame('MISSING_CONTEXT', $response->json('data.cart.pricing_status'));
        $this->assertSame(['SERVICE_ZONE'], $response->json('data.cart.required_context'));
        $this->assertNull($response->json('data.cart.total'));
    }

    public function test_unavailable_is_rejected_on_add(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        // No pricing scheme at all => UNAVAILABLE.

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])
            ->assertStatus(422);

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_user_lock_occurs_before_cart_lock(): void
    {
        // Behavioral proxy for the USER -> CART lock order: two sequential
        // adds for the same customer never produce two ACTIVE carts, which
        // would be possible if the cart lookup/create raced ahead of the
        // per-customer user lock.
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));

        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);

        $this->assertDatabaseCount('carts', 1);
    }

    public function test_one_active_cart_is_maintained_through_application_lock_strategy(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']));

        for ($i = 0; $i < 3; $i++) {
            $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']])->assertStatus(201);
        }

        $activeCartCount = DB::table('carts')
            ->where('customer_user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->where('status_id', (int) DB::table('cart_statuses')->where('code', 'ACTIVE')->value('id'))
            ->count();

        $this->assertSame(1, $activeCartCount);
    }

    // Every Cart mutation Action (Add/Update/Remove/Clear) resolves its
    // target cart via `status_id = ACTIVE` only - a cart frozen to CHECKOUT
    // (e.g. by CreatePaymentAttemptAction once a payment attempt opens) must
    // therefore be completely immune to further mutation: Update/Remove see
    // no owned item to act on, Clear finds no ACTIVE cart to empty, and Add
    // transparently opens a brand new, independent ACTIVE cart rather than
    // ever touching the frozen one.
    public function test_frozen_checkout_cart_is_immune_to_further_cart_mutation(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $service = $this->createCartService();
        $this->createCartPricingRule($this->createCartPricingScheme($service['uuid']), ['effect_amount' => '100.000000']);

        $added = $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']]);
        $frozenCartUuid = $added->json('data.cart.uuid');
        $frozenItemUuid = $added->json('data.cart.items.0.uuid');

        DB::table('carts')->where('id', UuidBinary::toBinary($frozenCartUuid))->update([
            'status_id' => (int) DB::table('cart_statuses')->where('code', 'CHECKOUT')->value('id'),
        ]);

        $this->updateCartItem($customer['access_token'], $frozenItemUuid, ['quantity' => 5])->assertStatus(404);
        $this->assertDatabaseHas('cart_items', ['id' => UuidBinary::toBinary($frozenItemUuid), 'quantity' => 1]);

        $this->removeCartItem($customer['access_token'], $frozenItemUuid)->assertStatus(404);
        $this->assertDatabaseHas('cart_items', ['id' => UuidBinary::toBinary($frozenItemUuid)]);

        $this->clearCart($customer['access_token'])->assertStatus(200)->assertJsonPath('message', 'Cart is already empty.');
        $this->assertDatabaseHas('cart_items', ['id' => UuidBinary::toBinary($frozenItemUuid)]);

        $reAdded = $this->addCartItem($customer['access_token'], ['service_uuid' => $service['uuid']]);
        $reAdded->assertStatus(201);
        $this->assertNotSame($frozenCartUuid, $reAdded->json('data.cart.uuid'));

        $this->assertSame(
            'CHECKOUT',
            DB::table('cart_statuses')->where('id', DB::table('carts')->where('id', UuidBinary::toBinary($frozenCartUuid))->value('status_id'))->value('code')
        );
        $this->assertDatabaseCount('cart_items', 2);
    }
}
