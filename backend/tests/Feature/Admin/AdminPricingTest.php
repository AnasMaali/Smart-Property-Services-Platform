<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B9 - Admin Pricing Management (App\Actions\Admin\Pricing\*
 * / App\Support\Admin\AdminPricingSchemePresenter). Reuses the same Cart
 * fixture builders every other Service-Catalog-adjacent test in this suite
 * already uses (createCartService/createCartOption/createCartPricingScheme/
 * createCartPricingRule via CreatesCartFixtures, composed transitively
 * through CreatesContractFixtures) rather than re-inventing pricing
 * fixture insertion.
 *
 * The most important test here is test_admin_authored_published_pricing_is_
 * used_by_the_real_pricing_engine, which proves B9 writes canonical
 * configuration the existing PricingEngine reads - never a second pricing
 * implementation.
 */
class AdminPricingTest extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    private function schemeUrl(string $suffix = ''): string
    {
        return '/api/v1/admin/pricing-schemes'.$suffix;
    }

    // -----------------------------------------------------------------
    // READ
    // -----------------------------------------------------------------

    public function test_unauthenticated_request_cannot_list_pricing_schemes(): void
    {
        $this->getJson($this->schemeUrl())->assertStatus(401);
    }

    public function test_customer_cannot_list_pricing_schemes(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->getJson($this->schemeUrl(), $this->bearer($customer['access_token']))->assertStatus(401);
    }

    public function test_admin_can_list_pricing_schemes(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $schemeUuid = $this->createCartPricingScheme($service['uuid']);

        $response = $this->getJson($this->schemeUrl(), $this->bearer($admin['access_token']));

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertContains($schemeUuid, collect($response->json('data.pricing_schemes'))->pluck('uuid')->all());
    }

    public function test_super_admin_can_list_pricing_schemes(): void
    {
        $admin = $this->createAndLoginAdmin(['SUPER_ADMIN']);

        $this->getJson($this->schemeUrl(), $this->bearer($admin['access_token']))
            ->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_status_filter_narrows_pricing_scheme_list(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $published = $this->createCartPricingScheme($service['uuid'], ['status' => 'PUBLISHED']);
        $draft = $this->createCartPricingScheme($service['uuid'], ['status' => 'DRAFT', 'effective_from' => null]);

        $response = $this->getJson($this->schemeUrl().'?status=DRAFT', $this->bearer($admin['access_token']));

        $uuids = collect($response->json('data.pricing_schemes'))->pluck('uuid')->all();
        $this->assertContains($draft, $uuids);
        $this->assertNotContains($published, $uuids);
    }

    public function test_service_uuid_filter_narrows_pricing_scheme_list(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $serviceA = $this->createCartService($categoryId);
        $serviceB = $this->createCartService($categoryId);
        $schemeA = $this->createCartPricingScheme($serviceA['uuid']);
        $schemeB = $this->createCartPricingScheme($serviceB['uuid']);

        $response = $this->getJson($this->schemeUrl().'?service_uuid='.$serviceA['uuid'], $this->bearer($admin['access_token']));

        $uuids = collect($response->json('data.pricing_schemes'))->pluck('uuid')->all();
        $this->assertContains($schemeA, $uuids);
        $this->assertNotContains($schemeB, $uuids);
    }

    public function test_pricing_scheme_pagination_shape_is_present(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $serviceA = $this->createCartService($categoryId);
        $serviceB = $this->createCartService($categoryId);
        $this->createCartPricingScheme($serviceA['uuid']);
        $this->createCartPricingScheme($serviceB['uuid']);

        $response = $this->getJson($this->schemeUrl().'?per_page=1&page=1', $this->bearer($admin['access_token']));

        $this->assertSame(1, count($response->json('data.pricing_schemes')));
        $this->assertSame(1, $response->json('data.pagination.per_page'));
    }

    public function test_malformed_pricing_scheme_uuid_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson($this->schemeUrl('/not-a-uuid'), $this->bearer($admin['access_token']))->assertStatus(404);
    }

    public function test_unknown_pricing_scheme_uuid_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson($this->schemeUrl('/'.UuidBinary::generate()), $this->bearer($admin['access_token']))->assertStatus(404);
    }

    public function test_pricing_scheme_detail_presents_rules_conditions_and_tiers(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $schemeUuid = $this->createCartPricingScheme($service['uuid'], ['status' => 'DRAFT', 'effective_from' => null]);
        $this->createCartPricingRule($schemeUuid, ['rule_code' => 'BASE', 'priority' => 100, 'effect_amount' => '150.000000']);

        $response = $this->getJson($this->schemeUrl('/'.$schemeUuid), $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $data = $response->json('data.pricing_scheme');
        $this->assertSame($service['uuid'], $data['service']['uuid']);
        $this->assertSame('AED', $data['currency']['code']);
        $this->assertCount(1, $data['rules']);
        $this->assertSame('BASE', $data['rules'][0]['rule_code']);
        $this->assertSame('150.000000', $data['rules'][0]['effect_amount']);
    }

    public function test_pricing_scheme_responses_never_expose_security_material(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $schemeUuid = $this->createCartPricingScheme($service['uuid']);

        $response = $this->getJson($this->schemeUrl('/'.$schemeUuid), $this->bearer($admin['access_token']));
        $json = json_encode($response->json());

        foreach (['password_hash', 'refresh_token_hash', 'client_secret'] as $forbiddenKey) {
            $this->assertStringNotContainsString($forbiddenKey, $json);
        }
    }

    // -----------------------------------------------------------------
    // DRAFT CREATION
    // -----------------------------------------------------------------

    public function test_admin_can_create_a_pricing_scheme_draft(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $response = $this->postJson($this->schemeUrl(), [
            'service_uuid' => $service['uuid'],
            'currency_code' => 'AED',
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(201);
        $this->assertSame('DRAFT', $response->json('data.pricing_scheme.status'));
        $this->assertNull($response->json('data.pricing_scheme.effective_from'));

        $audit = DB::table('admin_audit_logs')->where('action_code', 'PRICING_SCHEME_DRAFT_CREATED')->first();
        $this->assertNotNull($audit);
    }

    public function test_draft_creation_rejects_unknown_service(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->postJson($this->schemeUrl(), [
            'service_uuid' => UuidBinary::generate(),
            'currency_code' => 'AED',
        ], $this->bearer($admin['access_token']))->assertStatus(422);
    }

    public function test_draft_creation_requires_service_uuid(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->postJson($this->schemeUrl(), ['currency_code' => 'AED'], $this->bearer($admin['access_token']))
            ->assertStatus(422);
    }

    public function test_customer_cannot_create_a_pricing_scheme_draft(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $this->postJson($this->schemeUrl(), [
            'service_uuid' => $service['uuid'],
            'currency_code' => 'AED',
        ], $this->bearer($customer['access_token']))->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // RULE AUTHORING
    // -----------------------------------------------------------------

    private function createDraftScheme(string $adminAccessToken, string $serviceUuid): string
    {
        $response = $this->postJson($this->schemeUrl(), [
            'service_uuid' => $serviceUuid,
            'currency_code' => 'AED',
        ], $this->bearer($adminAccessToken));

        return $response->json('data.pricing_scheme.uuid');
    }

    public function test_admin_can_create_an_unconditional_set_price_rule(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $schemeUuid = $this->createDraftScheme($admin['access_token'], $service['uuid']);

        $response = $this->postJson($this->schemeUrl('/'.$schemeUuid.'/rules'), [
            'rule_code' => 'BASE',
            'label' => 'Base price',
            'priority' => 100,
            'effect_type' => 'SET_PRICE',
            'effect_amount' => '200.000000',
            'stop_processing' => false,
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(201);
        $this->assertCount(1, $response->json('data.pricing_scheme.rules'));

        $audit = DB::table('admin_audit_logs')->where('action_code', 'PRICING_RULE_CREATED')->first();
        $this->assertNotNull($audit);
    }

    public function test_admin_can_create_an_add_per_unit_rule_with_tiers(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $optionUuid = $this->createCartOption($service['uuid'], $this->numberTypeId);
        $this->createCartNumericRule($optionUuid);
        $schemeUuid = $this->createDraftScheme($admin['access_token'], $service['uuid']);

        $response = $this->postJson($this->schemeUrl('/'.$schemeUuid.'/rules'), [
            'rule_code' => 'PER_ROOM',
            'label' => 'Per room surcharge',
            'priority' => 200,
            'effect_type' => 'ADD_PER_UNIT',
            'effect_subject_service_option_id' => $optionUuid,
            'tier_calculation_mode' => 'VOLUME',
            'stop_processing' => false,
            'tiers' => [
                ['tier_order' => 0, 'from_unit' => '0.000000', 'to_unit' => null, 'rate_amount' => '25.000000', 'tier_pricing_mode' => 'PER_UNIT'],
            ],
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(201);
        $rule = $response->json('data.pricing_scheme.rules')[0];
        $this->assertSame('ADD_PER_UNIT', $rule['effect_type']);
        $this->assertCount(1, $rule['tiers']);
    }

    public function test_admin_can_create_a_rule_with_a_condition_group(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $optionUuid = $this->createCartOption($service['uuid'], $this->singleSelectTypeId);
        $choiceUuid = $this->createCartChoice($optionUuid);
        $schemeUuid = $this->createDraftScheme($admin['access_token'], $service['uuid']);

        $response = $this->postJson($this->schemeUrl('/'.$schemeUuid.'/rules'), [
            'rule_code' => 'CHOICE_SURCHARGE',
            'label' => 'Choice surcharge',
            'priority' => 50,
            'effect_type' => 'ADD_FIXED',
            'effect_amount' => '10.000000',
            'stop_processing' => false,
            'condition_groups' => [
                ['conditions' => [
                    ['subject_type' => 'OPTION_CHOICE', 'service_option_id' => $optionUuid, 'operator' => 'EQ', 'value_choice_id' => $choiceUuid],
                ]],
            ],
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(201);
        $rule = $response->json('data.pricing_scheme.rules')[0];
        $this->assertCount(1, $rule['condition_groups']);
        $this->assertSame('OPTION_CHOICE', $rule['condition_groups'][0]['conditions'][0]['subject_type']);
        $this->assertSame($choiceUuid, $rule['condition_groups'][0]['conditions'][0]['value_choice']['uuid']);
    }

    public function test_rule_creation_rejects_amount_on_add_per_unit(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $optionUuid = $this->createCartOption($service['uuid'], $this->numberTypeId);
        $schemeUuid = $this->createDraftScheme($admin['access_token'], $service['uuid']);

        $response = $this->postJson($this->schemeUrl('/'.$schemeUuid.'/rules'), [
            'rule_code' => 'BAD',
            'label' => 'Bad rule',
            'priority' => 1,
            'effect_type' => 'ADD_PER_UNIT',
            'effect_amount' => '5.000000',
            'effect_subject_service_option_id' => $optionUuid,
            'tier_calculation_mode' => 'VOLUME',
            'stop_processing' => false,
            'tiers' => [['tier_order' => 0, 'from_unit' => '0.000000', 'rate_amount' => '1.000000', 'tier_pricing_mode' => 'PER_UNIT']],
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('pricing_rules')->where('pricing_scheme_version_id', UuidBinary::toBinary($schemeUuid))->count());
    }

    public function test_rule_creation_rejects_duplicate_priority(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $schemeUuid = $this->createDraftScheme($admin['access_token'], $service['uuid']);

        $ruleBody = fn (string $code) => [
            'rule_code' => $code,
            'label' => 'Rule',
            'priority' => 10,
            'effect_type' => 'SET_PRICE',
            'effect_amount' => '10.000000',
            'stop_processing' => false,
        ];

        $this->postJson($this->schemeUrl('/'.$schemeUuid.'/rules'), $ruleBody('FIRST'), $this->bearer($admin['access_token']))->assertStatus(201);
        $this->postJson($this->schemeUrl('/'.$schemeUuid.'/rules'), $ruleBody('SECOND'), $this->bearer($admin['access_token']))->assertStatus(409);
    }

    public function test_rule_cannot_be_added_to_a_published_scheme(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $schemeUuid = $this->createCartPricingScheme($service['uuid'], ['status' => 'PUBLISHED']);

        $this->postJson($this->schemeUrl('/'.$schemeUuid.'/rules'), [
            'rule_code' => 'NEW',
            'label' => 'New rule',
            'priority' => 1,
            'effect_type' => 'SET_PRICE',
            'effect_amount' => '1.000000',
            'stop_processing' => false,
        ], $this->bearer($admin['access_token']))->assertStatus(409);
    }

    public function test_customer_cannot_create_a_pricing_rule(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $schemeUuid = $this->createDraftScheme($admin['access_token'], $service['uuid']);

        $this->postJson($this->schemeUrl('/'.$schemeUuid.'/rules'), [
            'rule_code' => 'X', 'label' => 'X', 'priority' => 1, 'effect_type' => 'SET_PRICE',
            'effect_amount' => '1.000000', 'stop_processing' => false,
        ], $this->bearer($customer['access_token']))->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // RULE DELETION
    // -----------------------------------------------------------------

    public function test_admin_can_delete_a_draft_rule(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $schemeUuid = $this->createDraftScheme($admin['access_token'], $service['uuid']);
        $ruleUuid = $this->createCartPricingRule($schemeUuid, ['rule_code' => 'TO_DELETE']);

        $response = $this->deleteJson($this->schemeUrl('/'.$schemeUuid.'/rules/'.$ruleUuid), [], $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.pricing_scheme.rules'));
        $this->assertSame(0, DB::table('pricing_rules')->where('id', UuidBinary::toBinary($ruleUuid))->count());

        $audit = DB::table('admin_audit_logs')->where('action_code', 'PRICING_RULE_DELETED')->first();
        $this->assertNotNull($audit);
    }

    public function test_rule_cannot_be_deleted_from_a_published_scheme(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $schemeUuid = $this->createCartPricingScheme($service['uuid'], ['status' => 'PUBLISHED']);
        $ruleUuid = $this->createCartPricingRule($schemeUuid);

        $this->deleteJson($this->schemeUrl('/'.$schemeUuid.'/rules/'.$ruleUuid), [], $this->bearer($admin['access_token']))
            ->assertStatus(409);

        $this->assertSame(1, DB::table('pricing_rules')->where('id', UuidBinary::toBinary($ruleUuid))->count());
    }

    public function test_unknown_rule_delete_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $schemeUuid = $this->createDraftScheme($admin['access_token'], $service['uuid']);

        $this->deleteJson($this->schemeUrl('/'.$schemeUuid.'/rules/'.UuidBinary::generate()), [], $this->bearer($admin['access_token']))
            ->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // PUBLISH
    // -----------------------------------------------------------------

    public function test_publish_without_step_up_is_blocked(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $schemeUuid = $this->createDraftScheme($admin['access_token'], $service['uuid']);
        $this->createCartPricingRule($schemeUuid);

        $response = $this->postJson($this->schemeUrl('/'.$schemeUuid.'/publish'), [
            'effective_from' => now()->toIso8601String(),
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(428)->assertJson(['code' => 'STEP_UP_REQUIRED']);
    }

    public function test_customer_cannot_publish(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $schemeUuid = $this->createDraftScheme($admin['access_token'], $service['uuid']);

        $this->postJson($this->schemeUrl('/'.$schemeUuid.'/publish'), [
            'effective_from' => now()->toIso8601String(),
        ], $this->bearer($customer['access_token']))->assertStatus(401);
    }

    public function test_publish_rejects_a_draft_with_no_rules(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $schemeUuid = $this->createDraftScheme($admin['access_token'], $service['uuid']);

        $response = $this->postJson($this->schemeUrl('/'.$schemeUuid.'/publish'), [
            'effective_from' => now()->toIso8601String(),
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(422);
        $this->assertSame('DRAFT', DB::table('pricing_scheme_versions')->where('id', UuidBinary::toBinary($schemeUuid))->value('status'));
    }

    public function test_admin_can_publish_a_valid_draft(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $schemeUuid = $this->createDraftScheme($admin['access_token'], $service['uuid']);
        $this->createCartPricingRule($schemeUuid);

        $effectiveFrom = now()->startOfSecond();

        $response = $this->postJson($this->schemeUrl('/'.$schemeUuid.'/publish'), [
            'effective_from' => $effectiveFrom->toIso8601String(),
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $this->assertSame('PUBLISHED', $response->json('data.pricing_scheme.status'));
        $this->assertNotNull($response->json('data.pricing_scheme.published_at'));

        $audit = DB::table('admin_audit_logs')->where('action_code', 'PRICING_SCHEME_PUBLISHED')->first();
        $this->assertNotNull($audit);
    }

    public function test_publishing_an_already_published_scheme_is_rejected(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);
        $schemeUuid = $this->createCartPricingScheme($service['uuid'], ['status' => 'PUBLISHED']);

        $this->postJson($this->schemeUrl('/'.$schemeUuid.'/publish'), [
            'effective_from' => now()->toIso8601String(),
        ], $this->bearer($admin['access_token']))->assertStatus(409);
    }

    public function test_publish_rejects_overlap_with_an_existing_published_scheme(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $this->createCartPricingScheme($service['uuid'], ['status' => 'PUBLISHED', 'effective_from' => now()->subYear()]);

        $draftUuid = $this->createDraftScheme($admin['access_token'], $service['uuid']);
        $this->createCartPricingRule($draftUuid);

        $response = $this->postJson($this->schemeUrl('/'.$draftUuid.'/publish'), [
            'effective_from' => now()->toIso8601String(),
        ], $this->bearer($admin['access_token']));

        $response->assertStatus(422);
        $this->assertSame('DRAFT', DB::table('pricing_scheme_versions')->where('id', UuidBinary::toBinary($draftUuid))->value('status'));
    }

    // -----------------------------------------------------------------
    // CRITICAL END-TO-END: Admin-authored config drives the real engine
    // -----------------------------------------------------------------

    public function test_admin_authored_published_pricing_is_used_by_the_real_pricing_engine(): void
    {
        $admin = $this->createAndLoginAdminWithStepUp(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId, ['cart_eligible' => false]);

        // 1. Create the DRAFT and its rule entirely through the Admin API.
        $schemeUuid = $this->createDraftScheme($admin['access_token'], $service['uuid']);

        $this->postJson($this->schemeUrl('/'.$schemeUuid.'/rules'), [
            'rule_code' => 'E2E_BASE',
            'label' => 'End-to-end base price',
            'priority' => 100,
            'effect_type' => 'SET_PRICE',
            'effect_amount' => '321.500000',
            'stop_processing' => false,
        ], $this->bearer($admin['access_token']))->assertStatus(201);

        // 2. Publish entirely through the Admin API.
        $this->postJson($this->schemeUrl('/'.$schemeUuid.'/publish'), [
            'effective_from' => now()->subMinute()->toIso8601String(),
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        // 3. Run the REAL, unmodified customer-facing pricing preview
        // (App\Actions\ServiceCatalog\GetServiceDetailsAction ->
        // App\Support\Pricing\PricingEngine) - never a second calculation.
        $response = $this->getJson('/api/v1/services/'.$service['slug']);

        $response->assertStatus(200);
        $this->assertSame('PRICED', $response->json('data.pricing_preview.pricing_status'));
        $this->assertSame('321.500000', $response->json('data.pricing_preview.unit_total'));
    }
}
