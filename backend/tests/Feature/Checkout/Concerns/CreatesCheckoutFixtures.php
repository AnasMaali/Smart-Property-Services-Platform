<?php

namespace Tests\Feature\Checkout\Concerns;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cart\Concerns\CreatesCartFixtures;

/**
 * Checkout-specific fixture builders and HTTP helpers, layered on top of
 * the Phase 4 CreatesCartFixtures (customer/service/pricing-scheme
 * builders) instead of duplicating them - Checkout tests always need a
 * priced, CART_ELIGIBLE cart item to reach checkout in the first place.
 */
trait CreatesCheckoutFixtures
{
    use CreatesCartFixtures;

    private static int $checkoutFixtureSequence = 0;

    private int $otherPropertyTypeId;

    protected function setUpCheckoutFixtures(): void
    {
        $this->setUpCartFixtures();
        $this->otherPropertyTypeId = (int) DB::table('property_types')->where('code', 'OTHER')->value('id');
    }

    /**
     * @return array<int, int> Two distinct active area ids.
     */
    private function twoDistinctAreaIds(): array
    {
        return DB::table('areas')->where('is_active', 1)->orderBy('id')->limit(2)->pluck('id')->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function locationPayload(int $areaId, array $overrides = []): array
    {
        self::$checkoutFixtureSequence++;
        $propertyTypeId = $overrides['property_type_id'] ?? (int) DB::table('property_types')->where('code', 'APARTMENT')->value('id');

        return array_merge([
            'property_type_id' => $propertyTypeId,
            'area_id' => $areaId,
            'street_name' => 'Sheikh Zayed Road',
            'address_line' => 'Checkout QA fixture address line '.self::$checkoutFixtureSequence,
            'building_name_or_number' => 'Tower '.self::$checkoutFixtureSequence,
            'floor_number' => '12',
            'unit_number' => '1201',
            'nearby_landmark' => 'Near QA Metro Station',
            'additional_location_notes' => 'QA fixture note, not real address data.',
            'visit_contact_phone' => '+97150'.str_pad((string) self::$checkoutFixtureSequence, 7, '0', STR_PAD_LEFT),
        ], $overrides);
    }

    /**
     * @return array{uuid: string, starts_at: Carbon, ends_at: Carbon, booking_capacity: int, time_window_id: int}
     */
    private function createAppointmentSlot(array $overrides = []): array
    {
        $uuid = UuidBinary::generate();
        $now = now();
        $startsAt = $overrides['starts_at'] ?? $now->copy()->addDay();
        $endsAt = $overrides['ends_at'] ?? $startsAt->copy()->addHours(2);
        $timeWindowId = $overrides['time_window_id'] ?? $this->createAppointmentTimeWindow();

        DB::table('appointment_slots')->insert([
            'id' => UuidBinary::toBinary($uuid),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'booking_capacity' => $overrides['booking_capacity'] ?? 1,
            'time_window_id' => $timeWindowId,
            'is_active' => $overrides['is_active'] ?? 1,
            'internal_note' => $overrides['internal_note'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'uuid' => $uuid,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'booking_capacity' => (int) ($overrides['booking_capacity'] ?? 1),
            'time_window_id' => (int) $timeWindowId,
        ];
    }

    /**
     * Schema-driven lookup fixture, mirroring service_zones' own columns -
     * QA rows are always clearly labelled so they can never be mistaken for
     * real business data (real zones "remain empty until populated", per
     * the BLUE V1 Phase 5 correction).
     */
    private function createServiceZone(array $overrides = []): int
    {
        self::$checkoutFixtureSequence++;
        $now = now();

        return DB::table('service_zones')->insertGetId([
            'code' => $overrides['code'] ?? 'CHECKOUT_QA_ZONE_'.self::$checkoutFixtureSequence,
            'name' => $overrides['name'] ?? 'Checkout QA Zone '.self::$checkoutFixtureSequence,
            'description' => 'QA test fixture zone, not real business data.',
            'display_order' => 900 + self::$checkoutFixtureSequence,
            'is_active' => $overrides['is_active'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * service_zone_areas.area_id is that table's primary key, so this is
     * always an upsert - one area maps to at most one zone.
     */
    private function mapAreaToServiceZone(int $areaId, int $serviceZoneId): void
    {
        $now = now();

        DB::table('service_zone_areas')->updateOrInsert(
            ['area_id' => $areaId],
            ['service_zone_id' => $serviceZoneId, 'created_at' => $now, 'updated_at' => $now]
        );
    }

    private function createAppointmentTimeWindow(array $overrides = []): int
    {
        self::$checkoutFixtureSequence++;
        $now = now();

        return DB::table('appointment_time_windows')->insertGetId([
            'code' => $overrides['code'] ?? 'CHECKOUT_QA_WINDOW_'.self::$checkoutFixtureSequence,
            'name' => $overrides['name'] ?? 'Checkout QA Window '.self::$checkoutFixtureSequence,
            'description' => 'QA test fixture time window, not real business data.',
            'display_order' => 900 + self::$checkoutFixtureSequence,
            'is_active' => $overrides['is_active'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * A CART_ELIGIBLE service with an unconditional PUBLISHED PRICED scheme
     * (100.000000) - the shape every checkout/location/slot/hold test needs
     * merely to get past AddCartItemAction's "reject on UNAVAILABLE
     * pricing" check, without that pricing itself being the point of the
     * test.
     *
     * @return array{uuid: string, slug: string}
     */
    private function createPricedCartService(): array
    {
        $service = $this->createCartService();
        $scheme = $this->createCartPricingScheme($service['uuid']);
        $this->createCartPricingRule($scheme);

        return $service;
    }

    /**
     * Inserts a rule with a single CONTEXT_ATTRIBUTE EQ condition, matching
     * the pricing_rules / pricing_rule_condition_groups / pricing_rule_
     * conditions shape already exercised by AddCartItemTest::test_missing_
     * context_item_allowed_with_required_context_returned. Reused here so
     * Checkout's SERVICE_ZONE/TIME_WINDOW tests build rules the exact same
     * schema-correct way instead of inventing a second fixture shape.
     */
    private function createContextAttributeConditionRule(string $schemeVersionUuid, string $attributeCode, string $value, array $overrides = []): string
    {
        $ruleUuid = $this->createCartPricingRule($schemeVersionUuid, $overrides);
        $now = now();

        $conditionGroupId = UuidBinary::generate();
        DB::table('pricing_rule_condition_groups')->insert([
            'id' => UuidBinary::toBinary($conditionGroupId),
            'pricing_rule_id' => UuidBinary::toBinary($ruleUuid),
            'group_order' => 1,
            'created_at' => $now,
        ]);

        $attributeId = (int) DB::table('pricing_context_attributes')->where('code', $attributeCode)->value('id');

        DB::table('pricing_rule_conditions')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'pricing_rule_condition_group_id' => UuidBinary::toBinary($conditionGroupId),
            'subject_type' => 'CONTEXT_ATTRIBUTE',
            'context_attribute_id' => $attributeId,
            'operator' => 'EQ',
            'value_number' => $value,
            'created_at' => $now,
        ]);

        return $ruleUuid;
    }

    private function getCheckout(string $accessToken): TestResponse
    {
        return $this->getJson('/api/v1/checkout', ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function saveCheckoutLocation(string $accessToken, array $payload): TestResponse
    {
        return $this->putJson('/api/v1/checkout/location', $payload, ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function getAppointmentSlots(string $accessToken): TestResponse
    {
        return $this->getJson('/api/v1/checkout/appointment-slots', ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function createAppointmentHold(string $accessToken, string $slotUuid): TestResponse
    {
        return $this->postJson('/api/v1/checkout/appointment-hold', ['appointment_slot_uuid' => $slotUuid], ['Authorization' => 'Bearer '.$accessToken]);
    }

    private function releaseAppointmentHold(string $accessToken): TestResponse
    {
        return $this->deleteJson('/api/v1/checkout/appointment-hold', [], ['Authorization' => 'Bearer '.$accessToken]);
    }
}
