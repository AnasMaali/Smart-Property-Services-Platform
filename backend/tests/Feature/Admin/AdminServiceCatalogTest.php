<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B8 - Admin Service Catalog (Categories/Services)
 * (App\Actions\Admin\Service\* / App\Support\Admin\AdminServiceCategoryPresenter
 * / AdminServicePresenter). Reuses the same Cart fixture builders
 * (Tests\Feature\Cart\Concerns\CreatesCartFixtures, composed transitively
 * through CreatesContractFixtures) that every other Service-Catalog-adjacent
 * test in this suite already uses, rather than re-inventing Category/
 * Service/Option/Capability fixture insertion.
 */
class AdminServiceCatalogTest extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    // -----------------------------------------------------------------
    // READ - Categories
    // -----------------------------------------------------------------

    public function test_admin_can_list_service_categories(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();

        $response = $this->getJson('/api/v1/admin/service-categories', $this->bearer($admin['access_token']));

        $response->assertStatus(200)->assertJson(['success' => true]);
        $ids = collect($response->json('data.service_categories'))->pluck('id')->all();
        $this->assertContains($categoryId, $ids);
    }

    public function test_super_admin_can_list_service_categories(): void
    {
        $admin = $this->createAndLoginAdmin(['SUPER_ADMIN']);

        $this->getJson('/api/v1/admin/service-categories', $this->bearer($admin['access_token']))
            ->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_customer_cannot_list_admin_service_categories(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->getJson('/api/v1/admin/service-categories', $this->bearer($customer['access_token']))
            ->assertStatus(401);
    }

    public function test_unauthenticated_request_cannot_list_service_categories(): void
    {
        $this->getJson('/api/v1/admin/service-categories')->assertStatus(401);
    }

    public function test_admin_category_list_includes_inactive_categories_by_default(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        DB::table('service_categories')->where('id', $categoryId)->update(['is_active' => 0]);

        $response = $this->getJson('/api/v1/admin/service-categories', $this->bearer($admin['access_token']));

        $this->assertContains($categoryId, collect($response->json('data.service_categories'))->pluck('id')->all());
    }

    public function test_is_active_filter_narrows_category_list(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $activeId = $this->createCartCategory();
        $inactiveId = $this->createCartCategory();
        DB::table('service_categories')->where('id', $inactiveId)->update(['is_active' => 0]);

        $response = $this->getJson('/api/v1/admin/service-categories?is_active=1', $this->bearer($admin['access_token']));

        $ids = collect($response->json('data.service_categories'))->pluck('id')->all();
        $this->assertContains($activeId, $ids);
        $this->assertNotContains($inactiveId, $ids);
    }

    public function test_admin_can_view_category_detail_with_its_services(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $response = $this->getJson('/api/v1/admin/service-categories/'.$categoryId, $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $serviceUuids = collect($response->json('data.service_category.services'))->pluck('uuid')->all();
        $this->assertContains($service['uuid'], $serviceUuids);
    }

    public function test_malformed_category_id_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/service-categories/not-a-number', $this->bearer($admin['access_token']))
            ->assertStatus(404);
    }

    public function test_unknown_category_id_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/service-categories/999999999', $this->bearer($admin['access_token']))
            ->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // READ - Services
    // -----------------------------------------------------------------

    public function test_admin_can_list_services_globally_across_categories(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryA = $this->createCartCategory();
        $categoryB = $this->createCartCategory();
        $serviceA = $this->createCartService($categoryA);
        $serviceB = $this->createCartService($categoryB);

        $response = $this->getJson('/api/v1/admin/services', $this->bearer($admin['access_token']));

        $uuids = collect($response->json('data.services'))->pluck('uuid')->all();
        $this->assertContains($serviceA['uuid'], $uuids);
        $this->assertContains($serviceB['uuid'], $uuids);
    }

    public function test_customer_cannot_list_admin_services(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->getJson('/api/v1/admin/services', $this->bearer($customer['access_token']))
            ->assertStatus(401);
    }

    public function test_unauthenticated_request_cannot_list_admin_services(): void
    {
        $this->getJson('/api/v1/admin/services')->assertStatus(401);
    }

    public function test_services_pagination_shape_is_present(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $this->createCartService($categoryId);
        $this->createCartService($categoryId);

        $response = $this->getJson('/api/v1/admin/services?per_page=1&page=1', $this->bearer($admin['access_token']));

        $this->assertSame(1, count($response->json('data.services')));
        $this->assertSame(1, $response->json('data.pagination.per_page'));
        $this->assertGreaterThanOrEqual(2, $response->json('data.pagination.total'));
    }

    public function test_category_id_filter_narrows_services_list(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryA = $this->createCartCategory();
        $categoryB = $this->createCartCategory();
        $serviceA = $this->createCartService($categoryA);
        $serviceB = $this->createCartService($categoryB);

        $response = $this->getJson('/api/v1/admin/services?category_id='.$categoryA, $this->bearer($admin['access_token']));

        $uuids = collect($response->json('data.services'))->pluck('uuid')->all();
        $this->assertContains($serviceA['uuid'], $uuids);
        $this->assertNotContains($serviceB['uuid'], $uuids);
    }

    public function test_is_active_filter_narrows_services_list(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $active = $this->createCartService($categoryId);
        $inactive = $this->createCartService($categoryId, ['is_active' => 0]);

        $response = $this->getJson('/api/v1/admin/services?is_active=1', $this->bearer($admin['access_token']));

        $uuids = collect($response->json('data.services'))->pluck('uuid')->all();
        $this->assertContains($active['uuid'], $uuids);
        $this->assertNotContains($inactive['uuid'], $uuids);
    }

    public function test_search_filter_matches_service_name(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        DB::table('services')->insert([
            'id' => UuidBinary::toBinary($uuid = UuidBinary::generate()),
            'category_id' => $categoryId,
            'code' => 'QA_SEARCHABLE',
            'slug' => 'qa-searchable-service',
            'name' => 'Deep Cleaning Special',
            'short_description' => 'QA fixture.',
            'description' => 'QA fixture.',
            'display_order' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/admin/services?search=Deep+Cleaning', $this->bearer($admin['access_token']));

        $this->assertContains($uuid, collect($response->json('data.services'))->pluck('uuid')->all());
    }

    public function test_service_detail_presents_capabilities_specializations_options_and_media(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId, ['cart_eligible' => true]);

        $specializationId = DB::table('specializations')->value('id');
        DB::table('service_specializations')->insert([
            'service_id' => UuidBinary::toBinary($service['uuid']),
            'specialization_id' => $specializationId,
            'is_primary' => 1,
            'display_order' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $numberTypeId = (int) DB::table('service_option_types')->where('code', 'NUMBER')->value('id');
        $optionUuid = $this->createCartOption($service['uuid'], $numberTypeId);
        $this->createCartNumericRule($optionUuid);

        DB::table('service_media')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'service_id' => UuidBinary::toBinary($service['uuid']),
            'storage_key' => 'qa/media/'.$service['uuid'].'.jpg',
            'mime_type' => 'image/jpeg',
            'alt_text' => 'QA fixture image',
            'is_primary' => 1,
            'is_active' => 1,
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/admin/services/'.$service['uuid'], $this->bearer($admin['access_token']));
        $data = $response->json('data.service');

        $this->assertContains('CART_ELIGIBLE', collect($data['capabilities'])->pluck('code')->all());
        $this->assertCount(1, $data['specializations']);
        $this->assertCount(1, $data['options']);
        $this->assertSame('NUMBER', $data['options'][0]['type']);
        $this->assertNotNull($data['options'][0]['numeric_rule']);
        $this->assertCount(1, $data['media']);
        $this->assertArrayHasKey('pricing', $data);
        $this->assertArrayHasKey('currency_code', $data['pricing']);
    }

    public function test_malformed_service_uuid_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/services/not-a-uuid', $this->bearer($admin['access_token']))
            ->assertStatus(404);
    }

    public function test_unknown_service_uuid_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/services/'.UuidBinary::generate(), $this->bearer($admin['access_token']))
            ->assertStatus(404);
    }

    public function test_service_detail_never_exposes_security_material(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $response = $this->getJson('/api/v1/admin/services/'.$service['uuid'], $this->bearer($admin['access_token']));
        $json = json_encode($response->json());

        foreach (['password_hash', 'refresh_token_hash', 'client_secret'] as $forbiddenKey) {
            $this->assertStringNotContainsString($forbiddenKey, $json, "Response must never contain {$forbiddenKey}.");
        }
    }

    // -----------------------------------------------------------------
    // WRITE - Category metadata + activate/deactivate
    // -----------------------------------------------------------------

    public function test_admin_can_update_category_metadata(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();

        $response = $this->patchJson(
            '/api/v1/admin/service-categories/'.$categoryId,
            ['name' => 'Renamed Category', 'description' => 'New description.', 'display_order' => 42],
            $this->bearer($admin['access_token']),
        );

        $response->assertStatus(200);
        $this->assertSame('Renamed Category', $response->json('data.service_category.name'));

        $stored = DB::table('service_categories')->where('id', $categoryId)->first();
        $this->assertSame('Renamed Category', $stored->name);
        $this->assertSame(42, (int) $stored->display_order);
    }

    public function test_category_metadata_update_requires_name(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();

        $this->patchJson(
            '/api/v1/admin/service-categories/'.$categoryId,
            ['description' => 'x', 'display_order' => 1],
            $this->bearer($admin['access_token']),
        )->assertStatus(422);
    }

    public function test_customer_cannot_update_category_metadata(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $categoryId = $this->createCartCategory();

        $this->patchJson(
            '/api/v1/admin/service-categories/'.$categoryId,
            ['name' => 'Hacked', 'description' => null, 'display_order' => 1],
            $this->bearer($customer['access_token']),
        )->assertStatus(401);
    }

    public function test_admin_can_activate_and_deactivate_category_idempotently(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();

        $deactivate = $this->postJson('/api/v1/admin/service-categories/'.$categoryId.'/deactivate', [], $this->bearer($admin['access_token']));
        $deactivate->assertStatus(200);
        $this->assertFalse($deactivate->json('data.service_category.is_active'));

        $deactivateAgain = $this->postJson('/api/v1/admin/service-categories/'.$categoryId.'/deactivate', [], $this->bearer($admin['access_token']));
        $deactivateAgain->assertStatus(200)->assertJsonFragment(['message' => 'Service category is already inactive.']);

        $activate = $this->postJson('/api/v1/admin/service-categories/'.$categoryId.'/activate', [], $this->bearer($admin['access_token']));
        $activate->assertStatus(200);
        $this->assertTrue($activate->json('data.service_category.is_active'));

        $auditRows = DB::table('admin_audit_logs')->where('entity_identifier', (string) $categoryId)->orderBy('created_at')->orderBy('id')->get();
        $this->assertSame(['SERVICE_CATEGORY_DEACTIVATED', 'SERVICE_CATEGORY_ACTIVATED'], $auditRows->pluck('action_code')->all());
    }

    public function test_deactivating_category_does_not_cascade_to_its_services(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $this->postJson('/api/v1/admin/service-categories/'.$categoryId.'/deactivate', [], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $stored = DB::table('services')->where('id', UuidBinary::toBinary($service['uuid']))->first();
        $this->assertSame(1, (int) $stored->is_active);
    }

    public function test_unknown_category_activate_returns_404_with_no_partial_state(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->postJson('/api/v1/admin/service-categories/999999999/activate', [], $this->bearer($admin['access_token']))
            ->assertStatus(404);

        $this->assertSame(0, DB::table('admin_audit_logs')->where('action_code', 'SERVICE_CATEGORY_ACTIVATED')->count());
    }

    // -----------------------------------------------------------------
    // WRITE - Service metadata + activate/deactivate
    // -----------------------------------------------------------------

    public function test_admin_can_update_service_metadata(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $response = $this->patchJson(
            '/api/v1/admin/services/'.$service['uuid'],
            ['name' => 'Renamed Service', 'short_description' => 'Short.', 'description' => 'Long.', 'display_order' => 7],
            $this->bearer($admin['access_token']),
        );

        $response->assertStatus(200);
        $this->assertSame('Renamed Service', $response->json('data.service.name'));

        $stored = DB::table('services')->where('id', UuidBinary::toBinary($service['uuid']))->first();
        $this->assertSame('Renamed Service', $stored->name);
    }

    public function test_service_metadata_update_validation_rejects_short_name(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $this->patchJson(
            '/api/v1/admin/services/'.$service['uuid'],
            ['name' => 'A', 'display_order' => 1],
            $this->bearer($admin['access_token']),
        )->assertStatus(422);
    }

    public function test_customer_cannot_update_service_metadata(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $this->patchJson(
            '/api/v1/admin/services/'.$service['uuid'],
            ['name' => 'Hacked', 'display_order' => 1],
            $this->bearer($customer['access_token']),
        )->assertStatus(401);
    }

    public function test_admin_can_activate_and_deactivate_service_idempotently(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $deactivate = $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/deactivate', [], $this->bearer($admin['access_token']));
        $deactivate->assertStatus(200);
        $this->assertFalse($deactivate->json('data.service.is_active'));

        $deactivateAgain = $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/deactivate', [], $this->bearer($admin['access_token']));
        $deactivateAgain->assertStatus(200)->assertJsonFragment(['message' => 'Service is already inactive.']);

        $activate = $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/activate', [], $this->bearer($admin['access_token']));
        $activate->assertStatus(200);
        $this->assertTrue($activate->json('data.service.is_active'));

        $auditRows = DB::table('admin_audit_logs')->where('entity_identifier', $service['uuid'])->orderBy('created_at')->orderBy('id')->get();
        $this->assertSame(['SERVICE_DEACTIVATED', 'SERVICE_ACTIVATED'], $auditRows->pluck('action_code')->all());
    }

    public function test_unknown_service_activate_returns_404_with_no_partial_state(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->postJson('/api/v1/admin/services/'.UuidBinary::generate().'/activate', [], $this->bearer($admin['access_token']))
            ->assertStatus(404);

        $this->assertSame(0, DB::table('admin_audit_logs')->where('action_code', 'SERVICE_ACTIVATED')->count());
    }

    // -----------------------------------------------------------------
    // Public catalog regression: canonical mutation is reflected exactly
    // -----------------------------------------------------------------

    public function test_deactivating_a_service_removes_it_from_the_public_category_services_endpoint(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $before = $this->getJson('/api/v1/service-categories/'.$categoryId.'/services');
        $this->assertContains($service['uuid'], collect($before->json('data.services'))->pluck('uuid')->all());

        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/deactivate', [], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $after = $this->getJson('/api/v1/service-categories/'.$categoryId.'/services');
        $this->assertNotContains($service['uuid'], collect($after->json('data.services'))->pluck('uuid')->all());
    }

    public function test_deactivating_a_service_makes_its_public_detail_page_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $this->getJson('/api/v1/services/'.$service['slug'])->assertStatus(200);

        $this->postJson('/api/v1/admin/services/'.$service['uuid'].'/deactivate', [], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $this->getJson('/api/v1/services/'.$service['slug'])->assertStatus(404);
    }

    public function test_deactivating_a_category_hides_it_from_the_public_category_list_and_its_services_endpoint(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $categoryId = $this->createCartCategory();
        $service = $this->createCartService($categoryId);

        $this->postJson('/api/v1/admin/service-categories/'.$categoryId.'/deactivate', [], $this->bearer($admin['access_token']))
            ->assertStatus(200);

        $categoryList = $this->getJson('/api/v1/service-categories');
        $this->assertNotContains($categoryId, collect($categoryList->json('data'))->pluck('id')->all());

        $this->getJson('/api/v1/service-categories/'.$categoryId.'/services')->assertStatus(404);

        // Pre-existing behavior, not introduced by B8: a Service's own
        // by-slug detail page only checks the Service's own `is_active`,
        // never its Category's - so it remains individually reachable.
        $this->getJson('/api/v1/services/'.$service['slug'])->assertStatus(200);
    }
}
