<?php

namespace Tests\Feature\Pricing;

use App\Support\Pricing\SchemePublishValidator;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature-level (DB-backed) coverage for the publish-time checks that
 * cannot be expressed as plain CHECK/UNIQUE constraints: cross-service
 * option references, tier gap/overlap/mode validity, and effective-period
 * overlap between PUBLISHED scheme versions (item 9 of the approved
 * flexible-pricing architecture). PricingRuleEvaluator/PricingSchemeSelector
 * arithmetic itself is covered without a database in
 * tests/Unit/Pricing/*.
 */
class SchemePublishValidatorTest extends TestCase
{
    use DatabaseTransactions;

    private static int $sequence = 0;

    private int $aedCurrencyId;

    private SchemePublishValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aedCurrencyId = (int) DB::table('currencies')->where('code', 'AED')->value('id');
        $this->validator = new SchemePublishValidator;
    }

    private function createService(array $overrides = []): string
    {
        self::$sequence++;
        $categoryId = DB::table('service_categories')->insertGetId([
            'code' => 'QA_PUB_CAT_'.self::$sequence,
            'name' => 'QA Pub Category '.self::$sequence,
            'display_order' => 900 + self::$sequence,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $uuid = UuidBinary::generate();

        DB::table('services')->insert([
            'id' => UuidBinary::toBinary($uuid),
            'category_id' => $categoryId,
            'code' => 'QA_PUB_SERVICE_'.self::$sequence,
            'slug' => 'qa-pub-service-'.self::$sequence,
            'name' => 'QA Pub Service '.self::$sequence,
            'display_order' => self::$sequence,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $uuid;
    }

    private function createOption(string $serviceUuid): string
    {
        self::$sequence++;
        $numberTypeId = (int) DB::table('service_option_types')->where('code', 'NUMBER')->value('id');
        $uuid = UuidBinary::generate();

        DB::table('service_options')->insert([
            'id' => UuidBinary::toBinary($uuid),
            'service_id' => UuidBinary::toBinary($serviceUuid),
            'option_type_id' => $numberTypeId,
            'code' => 'QA_PUB_OPT_'.self::$sequence,
            'name' => 'QA Pub Option '.self::$sequence,
            'is_required' => 0,
            'display_order' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $uuid;
    }

    private function createDraftScheme(string $serviceUuid): string
    {
        $uuid = UuidBinary::generate();

        DB::table('pricing_scheme_versions')->insert([
            'id' => UuidBinary::toBinary($uuid),
            'service_id' => UuidBinary::toBinary($serviceUuid),
            'currency_id' => $this->aedCurrencyId,
            'status' => 'DRAFT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $uuid;
    }

    private function createRule(string $schemeUuid, array $overrides = []): string
    {
        $uuid = UuidBinary::generate();

        DB::table('pricing_rules')->insert([
            'id' => UuidBinary::toBinary($uuid),
            'pricing_scheme_version_id' => UuidBinary::toBinary($schemeUuid),
            'rule_code' => $overrides['rule_code'] ?? 'RULE_'.$uuid,
            'label' => 'QA rule',
            'priority' => $overrides['priority'] ?? 100,
            'effect_type' => $overrides['effect_type'] ?? 'SET_PRICE',
            'effect_amount' => array_key_exists('effect_amount', $overrides) ? $overrides['effect_amount'] : '100.000000',
            'effect_subject_type' => $overrides['effect_subject_type'] ?? null,
            'effect_subject_service_option_id' => isset($overrides['effect_subject_service_option_id'])
                ? UuidBinary::toBinary($overrides['effect_subject_service_option_id'])
                : null,
            'tier_calculation_mode' => $overrides['tier_calculation_mode'] ?? null,
            'stop_processing' => $overrides['stop_processing'] ?? 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $uuid;
    }

    private function createTier(string $ruleUuid, array $overrides = []): void
    {
        DB::table('pricing_rule_tiers')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'pricing_rule_id' => UuidBinary::toBinary($ruleUuid),
            'tier_order' => $overrides['tier_order'] ?? 1,
            'from_unit' => $overrides['from_unit'] ?? '0.000000',
            'to_unit' => array_key_exists('to_unit', $overrides) ? $overrides['to_unit'] : null,
            'charge_unit_size' => $overrides['charge_unit_size'] ?? '1.000000',
            'rate_amount' => $overrides['rate_amount'] ?? '10.000000',
            'tier_pricing_mode' => $overrides['tier_pricing_mode'] ?? 'PER_UNIT',
            'created_at' => now(),
        ]);
    }

    // ---- 29. cross-service option reference rejected -----------------------

    public function test_effect_subject_referencing_another_services_option_is_rejected(): void
    {
        $serviceA = $this->createService();
        $serviceB = $this->createService();
        $optionOnServiceB = $this->createOption($serviceB);

        $scheme = $this->createDraftScheme($serviceA);
        $this->createRule($scheme, [
            'effect_type' => 'ADD_PER_UNIT',
            'effect_amount' => null,
            'effect_subject_type' => 'OPTION_NUMERIC_VALUE',
            'effect_subject_service_option_id' => $optionOnServiceB,
            'tier_calculation_mode' => 'GRADUATED',
        ]);

        $errors = $this->validator->validate(UuidBinary::toBinary($scheme));

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('does not belong to this scheme', implode(' ', $errors));
    }

    public function test_effect_subject_referencing_same_services_option_is_accepted(): void
    {
        $service = $this->createService();
        $option = $this->createOption($service);

        $scheme = $this->createDraftScheme($service);
        $rule = $this->createRule($scheme, [
            'effect_type' => 'ADD_PER_UNIT',
            'effect_amount' => null,
            'effect_subject_type' => 'OPTION_NUMERIC_VALUE',
            'effect_subject_service_option_id' => $option,
            'tier_calculation_mode' => 'GRADUATED',
        ]);
        $this->createTier($rule, ['from_unit' => '0.000000', 'to_unit' => null]);

        $errors = $this->validator->validate(UuidBinary::toBinary($scheme));

        $this->assertSame([], $errors);
    }

    // ---- 30. invalid tier configuration rejected ----------------------------

    public function test_add_per_unit_rule_without_tiers_is_rejected(): void
    {
        $service = $this->createService();
        $option = $this->createOption($service);
        $scheme = $this->createDraftScheme($service);

        $this->createRule($scheme, [
            'effect_type' => 'ADD_PER_UNIT',
            'effect_amount' => null,
            'effect_subject_type' => 'OPTION_NUMERIC_VALUE',
            'effect_subject_service_option_id' => $option,
            'tier_calculation_mode' => 'GRADUATED',
        ]);

        $errors = $this->validator->validate(UuidBinary::toBinary($scheme));

        $this->assertNotEmpty($errors);
    }

    public function test_tier_gap_is_rejected(): void
    {
        $service = $this->createService();
        $option = $this->createOption($service);
        $scheme = $this->createDraftScheme($service);

        $rule = $this->createRule($scheme, [
            'effect_type' => 'ADD_PER_UNIT',
            'effect_amount' => null,
            'effect_subject_type' => 'OPTION_NUMERIC_VALUE',
            'effect_subject_service_option_id' => $option,
            'tier_calculation_mode' => 'GRADUATED',
        ]);

        $this->createTier($rule, ['tier_order' => 1, 'from_unit' => '0.000000', 'to_unit' => '3.000000']);
        // Gap: next tier should start at 3, but starts at 5.
        $this->createTier($rule, ['tier_order' => 2, 'from_unit' => '5.000000', 'to_unit' => null]);

        $errors = $this->validator->validate(UuidBinary::toBinary($scheme));

        $this->assertNotEmpty($errors);
    }

    public function test_graduated_with_flat_tier_is_rejected(): void
    {
        $service = $this->createService();
        $option = $this->createOption($service);
        $scheme = $this->createDraftScheme($service);

        $rule = $this->createRule($scheme, [
            'effect_type' => 'ADD_PER_UNIT',
            'effect_amount' => null,
            'effect_subject_type' => 'OPTION_NUMERIC_VALUE',
            'effect_subject_service_option_id' => $option,
            'tier_calculation_mode' => 'GRADUATED',
        ]);

        $this->createTier($rule, ['from_unit' => '0.000000', 'to_unit' => null, 'tier_pricing_mode' => 'FLAT']);

        $errors = $this->validator->validate(UuidBinary::toBinary($scheme));

        $this->assertNotEmpty($errors);
    }

    public function test_quote_required_without_stop_processing_is_rejected_by_the_schema(): void
    {
        // Defense in depth: chk_pricing_rules_quote_required_stop already makes this
        // row impossible to insert, so SchemePublishValidator's own check for it
        // (checkQuoteRequiredStopsProcessing) can never actually be reached in
        // practice - this documents that guarantee at the schema level instead.
        $service = $this->createService();
        $scheme = $this->createDraftScheme($service);

        $this->expectException(QueryException::class);

        $this->createRule($scheme, ['effect_type' => 'QUOTE_REQUIRED', 'effect_amount' => null, 'stop_processing' => 0]);
    }

    // ---- overlapping effective periods --------------------------------------

    public function test_publish_rejects_overlap_with_existing_published_version(): void
    {
        $service = $this->createService();

        $existingScheme = $this->createDraftScheme($service);
        $this->createRule($existingScheme);
        $this->validator->publish(
            UuidBinary::toBinary($existingScheme),
            now()->copy()->subDays(10),
            now()->copy()->addDays(10),
        );

        $overlappingScheme = $this->createDraftScheme($service);
        $this->createRule($overlappingScheme);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/overlaps/');

        $this->validator->publish(
            UuidBinary::toBinary($overlappingScheme),
            now()->copy()->addDays(5),
            null,
        );
    }

    public function test_publish_accepts_non_overlapping_future_version(): void
    {
        $service = $this->createService();

        $currentScheme = $this->createDraftScheme($service);
        $this->createRule($currentScheme);
        $this->validator->publish(
            UuidBinary::toBinary($currentScheme),
            now()->copy()->subDays(10),
            now()->copy()->addDays(10),
        );

        $futureScheme = $this->createDraftScheme($service);
        $this->createRule($futureScheme);

        $this->validator->publish(
            UuidBinary::toBinary($futureScheme),
            now()->copy()->addDays(10),
            null,
        );

        $status = DB::table('pricing_scheme_versions')->where('id', UuidBinary::toBinary($futureScheme))->value('status');
        $this->assertSame('PUBLISHED', $status);
    }
}
