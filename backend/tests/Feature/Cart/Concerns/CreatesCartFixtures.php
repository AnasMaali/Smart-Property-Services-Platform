<?php

namespace Tests\Feature\Cart\Concerns;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Support\AuthenticatesCustomersForTests;

/**
 * Shared cart-test fixture builders, following the same direct-insert
 * convention already used by GetServiceDetailsTest (catalog/pricing rows)
 * and UpdateProfileTest (customer/session rows) - no factories exist in
 * this codebase, and QA fixture rows are always clearly labelled as such so
 * they can never be mistaken for real catalog/business data.
 */
trait CreatesCartFixtures
{
    use AuthenticatesCustomersForTests;

    private static int $cartFixtureSequence = 0;

    private int $aedCurrencyId;

    private int $textTypeId;

    private int $numberTypeId;

    private int $booleanTypeId;

    private int $singleSelectTypeId;

    private int $multiSelectTypeId;

    private int $roomUnitId;

    private int $cartEligibleCapabilityId;

    protected function setUpCartFixtures(): void
    {
        $this->aedCurrencyId = (int) DB::table('currencies')->where('code', 'AED')->value('id');
        $this->textTypeId = (int) DB::table('service_option_types')->where('code', 'TEXT')->value('id');
        $this->numberTypeId = (int) DB::table('service_option_types')->where('code', 'NUMBER')->value('id');
        $this->booleanTypeId = (int) DB::table('service_option_types')->where('code', 'BOOLEAN')->value('id');
        $this->singleSelectTypeId = (int) DB::table('service_option_types')->where('code', 'SINGLE_SELECT')->value('id');
        $this->multiSelectTypeId = (int) DB::table('service_option_types')->where('code', 'MULTI_SELECT')->value('id');
        $this->roomUnitId = (int) DB::table('measurement_units')->where('code', 'ROOM')->value('id');
        $this->cartEligibleCapabilityId = (int) DB::table('service_capability_types')->where('code', 'CART_ELIGIBLE')->value('id');
    }

    /**
     * @return array{user_uuid: string, phone_number: string, email: string, password: string}
     */
    private function createCartCustomer(array $overrides = []): array
    {
        self::$cartFixtureSequence++;
        $sequence = self::$cartFixtureSequence;

        $userUuid = UuidBinary::generate();
        $phoneNumber = '+97156'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        $email = $overrides['email'] ?? 'cart.qa.'.$sequence.'@example.com';
        $password = 'CartTestPassw0rd';
        $now = now();
        $areaId = (int) DB::table('areas')->value('id');
        $propertyRelationshipTypeId = (int) DB::table('property_relationship_types')->value('id');

        DB::table('users')->insert([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => $email,
            'password_hash' => Hash::make($password),
            'account_status_id' => (int) DB::table('user_account_statuses')->where('code', 'ACTIVE')->value('id'),
            'phone_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'full_name' => 'Cart QA Customer '.$sequence,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('customer_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'area_id' => $areaId,
            'property_relationship_type_id' => $propertyRelationshipTypeId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_roles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'role_id' => (int) DB::table('roles')->where('code', 'CUSTOMER')->value('id'),
            'assigned_by_user_id' => null,
            'assigned_at' => $now,
        ]);

        return ['user_uuid' => $userUuid, 'phone_number' => $phoneNumber, 'email' => $email, 'password' => $password];
    }

    /**
     * @return array{access_token: string}
     */
    private function loginCartCustomer(array $customer): array
    {
        return $this->issueCustomerSession($customer['user_uuid']);
    }

    /**
     * @return array{user_uuid: string, access_token: string}
     */
    private function createAuthenticatedCartCustomer(array $overrides = []): array
    {
        $customer = $this->createCartCustomer($overrides);
        $session = $this->loginCartCustomer($customer);

        return ['user_uuid' => $customer['user_uuid'], 'access_token' => $session['access_token']];
    }

    private function createCartCategory(): int
    {
        self::$cartFixtureSequence++;
        $now = now();

        return DB::table('service_categories')->insertGetId([
            'code' => 'CART_QA_CAT_'.self::$cartFixtureSequence,
            'name' => 'Cart QA Category '.self::$cartFixtureSequence,
            'description' => 'QA test fixture category, not real catalog content.',
            'display_order' => 900 + self::$cartFixtureSequence,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array{uuid: string, slug: string}
     */
    private function createCartService(?int $categoryId = null, array $overrides = []): array
    {
        self::$cartFixtureSequence++;
        $sequence = self::$cartFixtureSequence;
        $categoryId ??= $this->createCartCategory();
        $uuid = UuidBinary::generate();
        $now = now();

        DB::table('services')->insert([
            'id' => UuidBinary::toBinary($uuid),
            'category_id' => $categoryId,
            'code' => 'CART_QA_SERVICE_'.$sequence,
            'slug' => 'cart-qa-service-'.$sequence,
            'name' => 'Cart QA Service '.$sequence,
            'short_description' => 'QA fixture short description.',
            'description' => 'QA fixture full description, not real catalog content.',
            'display_order' => $sequence,
            'is_active' => $overrides['is_active'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($overrides['cart_eligible'] ?? true) {
            $this->makeCartEligible($uuid);
        }

        return ['uuid' => $uuid, 'slug' => 'cart-qa-service-'.$sequence];
    }

    private function makeCartEligible(string $serviceUuid): void
    {
        DB::table('service_capabilities')->insert([
            'service_id' => UuidBinary::toBinary($serviceUuid),
            'capability_type_id' => $this->cartEligibleCapabilityId,
            'created_at' => now(),
        ]);
    }

    private function createCartPricingScheme(string $serviceUuid, array $overrides = []): string
    {
        $uuid = UuidBinary::generate();
        $now = now();

        DB::table('pricing_scheme_versions')->insert([
            'id' => UuidBinary::toBinary($uuid),
            'service_id' => UuidBinary::toBinary($serviceUuid),
            'currency_id' => $this->aedCurrencyId,
            'status' => $overrides['status'] ?? 'PUBLISHED',
            'effective_from' => array_key_exists('effective_from', $overrides) ? $overrides['effective_from'] : $now->copy()->subDay(),
            'effective_to' => array_key_exists('effective_to', $overrides) ? $overrides['effective_to'] : null,
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }

    private function createCartPricingRule(string $schemeVersionUuid, array $overrides = []): string
    {
        $uuid = UuidBinary::generate();
        $now = now();

        DB::table('pricing_rules')->insert([
            'id' => UuidBinary::toBinary($uuid),
            'pricing_scheme_version_id' => UuidBinary::toBinary($schemeVersionUuid),
            'rule_code' => $overrides['rule_code'] ?? 'BASE_'.$uuid,
            'label' => $overrides['label'] ?? 'Base price',
            'priority' => $overrides['priority'] ?? 100,
            'effect_type' => $overrides['effect_type'] ?? 'SET_PRICE',
            'effect_amount' => array_key_exists('effect_amount', $overrides) ? $overrides['effect_amount'] : '100.000000',
            'effect_subject_type' => $overrides['effect_subject_type'] ?? null,
            'effect_subject_service_option_id' => isset($overrides['effect_subject_service_option_id'])
                ? UuidBinary::toBinary($overrides['effect_subject_service_option_id'])
                : null,
            'tier_calculation_mode' => $overrides['tier_calculation_mode'] ?? null,
            'stop_processing' => $overrides['stop_processing'] ?? 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }

    private function createCartOption(string $serviceUuid, int $typeId, array $overrides = []): string
    {
        $uuid = UuidBinary::generate();
        $now = now();

        DB::table('service_options')->insert([
            'id' => UuidBinary::toBinary($uuid),
            'service_id' => UuidBinary::toBinary($serviceUuid),
            'option_type_id' => $typeId,
            'code' => $overrides['code'] ?? 'CART_QA_OPTION_'.$uuid,
            'name' => $overrides['name'] ?? 'Cart QA Option',
            'description' => 'QA fixture option, not real catalog content.',
            'is_required' => $overrides['is_required'] ?? 0,
            'display_order' => $overrides['display_order'] ?? 0,
            'is_active' => $overrides['is_active'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }

    private function createCartNumericRule(string $optionUuid, array $overrides = []): void
    {
        $now = now();

        DB::table('service_option_numeric_rules')->insert([
            'service_option_id' => UuidBinary::toBinary($optionUuid),
            'measurement_unit_id' => array_key_exists('measurement_unit_id', $overrides) ? $overrides['measurement_unit_id'] : $this->roomUnitId,
            'minimum_value' => $overrides['minimum_value'] ?? '1.000000',
            'maximum_value' => $overrides['maximum_value'] ?? '10.000000',
            'step_value' => $overrides['step_value'] ?? '1.000000',
            'default_value' => $overrides['default_value'] ?? '2.000000',
            'decimal_places' => $overrides['decimal_places'] ?? 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createCartSelectionRule(string $optionUuid, array $overrides = []): void
    {
        $now = now();

        DB::table('service_option_selection_rules')->insert([
            'service_option_id' => UuidBinary::toBinary($optionUuid),
            'minimum_selections' => $overrides['minimum_selections'] ?? 1,
            'maximum_selections' => $overrides['maximum_selections'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createCartChoice(string $optionUuid, array $overrides = []): string
    {
        $uuid = UuidBinary::generate();
        $now = now();

        DB::table('service_option_choices')->insert([
            'id' => UuidBinary::toBinary($uuid),
            'service_option_id' => UuidBinary::toBinary($optionUuid),
            'code' => $overrides['code'] ?? 'CART_QA_CHOICE_'.$uuid,
            'name' => $overrides['name'] ?? 'Cart QA Choice '.$uuid,
            'description' => 'QA fixture choice, not real catalog content.',
            'display_order' => $overrides['display_order'] ?? 0,
            'is_active' => $overrides['is_active'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }

    private function getCart(string $accessToken): TestResponse
    {
        return $this->getJson('/api/v1/cart', ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function addCartItem(string $accessToken, array $payload): TestResponse
    {
        return $this->postJson('/api/v1/cart/items', $payload, ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function updateCartItem(string $accessToken, string $itemUuid, array $payload): TestResponse
    {
        return $this->patchJson('/api/v1/cart/items/'.$itemUuid, $payload, ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function removeCartItem(string $accessToken, string $itemUuid): TestResponse
    {
        return $this->deleteJson('/api/v1/cart/items/'.$itemUuid, [], ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function clearCart(string $accessToken): TestResponse
    {
        return $this->deleteJson('/api/v1/cart', [], ['Authorization' => 'Bearer '.$accessToken]);
    }
}
