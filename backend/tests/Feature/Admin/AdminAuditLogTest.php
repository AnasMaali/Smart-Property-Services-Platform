<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase B12 - Admin Audit Log viewer (App\Actions\Admin\Audit\*
 * / App\Support\Admin\AdminAuditLogPresenter). Entirely read-only over the
 * exact `admin_audit_logs` rows every prior phase already writes through
 * App\Support\Admin\AdminAuditLogger - no second audit trail.
 */
class AdminAuditLogTest extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    /**
     * Triggers one real, currently-implemented Admin mutation
     * (Service Category metadata update, BLUE V1 Phase B8) so this suite
     * asserts against a genuine `admin_audit_logs` row written by
     * production code - never a synthetic row shaped to fit the test.
     */
    private function triggerRealAuditedMutation(array $admin): int
    {
        $categoryId = $this->createCartCategory();

        $this->patchJson('/api/v1/admin/service-categories/'.$categoryId, [
            'name' => 'Audit QA Category',
            'description' => null,
            'display_order' => 1,
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        return $categoryId;
    }

    private function insertAuditRow(string $actorIdBinary, array $overrides = []): string
    {
        $uuid = UuidBinary::generate();

        DB::table('admin_audit_logs')->insert(array_merge([
            'id' => UuidBinary::toBinary($uuid),
            'admin_user_id' => $actorIdBinary,
            'action_code' => 'QA_AUDIT_EVENT',
            'entity_type' => 'QA_ENTITY',
            'entity_identifier' => 'qa-1',
            'was_successful' => 1,
            'failure_reason' => null,
            'created_at' => now(),
        ], $overrides));

        return $uuid;
    }

    // -----------------------------------------------------------------
    // AUTHORIZATION
    // -----------------------------------------------------------------

    public function test_unauthenticated_request_cannot_list_audit_logs(): void
    {
        $this->getJson('/api/v1/admin/audit-logs')->assertStatus(401);
    }

    public function test_customer_cannot_list_audit_logs(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();

        $this->getJson('/api/v1/admin/audit-logs', $this->bearer($customer['access_token']))->assertStatus(401);
    }

    public function test_admin_can_list_audit_logs(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->triggerRealAuditedMutation($admin);

        $response = $this->getJson('/api/v1/admin/audit-logs', $this->bearer($admin['access_token']));

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertContains('SERVICE_CATEGORY_UPDATED', collect($response->json('data.audit_logs'))->pluck('action_code')->all());
    }

    public function test_super_admin_can_list_audit_logs(): void
    {
        $admin = $this->createAndLoginAdmin(['SUPER_ADMIN']);

        $this->getJson('/api/v1/admin/audit-logs', $this->bearer($admin['access_token']))
            ->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_admin_without_audit_capability_is_denied(): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'audit.view')->value('id');

        DB::table('admin_role_permissions')->where('role_id', $adminRoleId)->where('permission_id', $permissionId)->delete();

        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/audit-logs', $this->bearer($admin['access_token']))->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // LIST
    // -----------------------------------------------------------------

    public function test_audit_log_list_pagination_shape_is_present(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $actorId = UuidBinary::toBinary($admin['user_uuid']);
        $this->insertAuditRow($actorId);
        $this->insertAuditRow($actorId);

        $response = $this->getJson('/api/v1/admin/audit-logs?per_page=1&page=1', $this->bearer($admin['access_token']));

        $this->assertSame(1, count($response->json('data.audit_logs')));
        $this->assertSame(1, $response->json('data.pagination.per_page'));
        $this->assertGreaterThanOrEqual(2, $response->json('data.pagination.total'));
    }

    public function test_action_code_filter_matches_exactly(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $actorId = UuidBinary::toBinary($admin['user_uuid']);
        $match = $this->insertAuditRow($actorId, ['action_code' => 'QA_MATCH_EVENT']);
        $other = $this->insertAuditRow($actorId, ['action_code' => 'QA_OTHER_EVENT']);

        $response = $this->getJson('/api/v1/admin/audit-logs?action_code=QA_MATCH_EVENT', $this->bearer($admin['access_token']));

        $uuids = collect($response->json('data.audit_logs'))->pluck('uuid')->all();
        $this->assertContains($match, $uuids);
        $this->assertNotContains($other, $uuids);
    }

    public function test_entity_type_and_identifier_filters_match_exactly(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $actorId = UuidBinary::toBinary($admin['user_uuid']);
        $match = $this->insertAuditRow($actorId, ['entity_type' => 'QA_ENTITY_TYPE', 'entity_identifier' => 'target-1']);
        $other = $this->insertAuditRow($actorId, ['entity_type' => 'QA_ENTITY_TYPE', 'entity_identifier' => 'target-2']);

        $response = $this->getJson(
            '/api/v1/admin/audit-logs?entity_type=QA_ENTITY_TYPE&entity_identifier=target-1',
            $this->bearer($admin['access_token']),
        );

        $uuids = collect($response->json('data.audit_logs'))->pluck('uuid')->all();
        $this->assertContains($match, $uuids);
        $this->assertNotContains($other, $uuids);
    }

    public function test_was_successful_filter_narrows_results(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $actorId = UuidBinary::toBinary($admin['user_uuid']);
        $success = $this->insertAuditRow($actorId, ['was_successful' => 1, 'failure_reason' => null]);
        $failure = $this->insertAuditRow($actorId, ['was_successful' => 0, 'failure_reason' => 'QA failure reason.']);

        $response = $this->getJson('/api/v1/admin/audit-logs?was_successful=0', $this->bearer($admin['access_token']));

        $uuids = collect($response->json('data.audit_logs'))->pluck('uuid')->all();
        $this->assertContains($failure, $uuids);
        $this->assertNotContains($success, $uuids);
    }

    public function test_actor_uuid_filter_scopes_to_that_admin_only(): void
    {
        $adminA = $this->createAndLoginAdmin(['ADMIN']);
        $adminB = $this->createAndLoginAdmin(['ADMIN']);
        $rowA = $this->insertAuditRow(UuidBinary::toBinary($adminA['user_uuid']));
        $rowB = $this->insertAuditRow(UuidBinary::toBinary($adminB['user_uuid']));

        $response = $this->getJson(
            '/api/v1/admin/audit-logs?actor_uuid='.$adminA['user_uuid'],
            $this->bearer($adminA['access_token']),
        );

        $uuids = collect($response->json('data.audit_logs'))->pluck('uuid')->all();
        $this->assertContains($rowA, $uuids);
        $this->assertNotContains($rowB, $uuids);
    }

    public function test_date_range_filter_excludes_rows_outside_the_window(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $actorId = UuidBinary::toBinary($admin['user_uuid']);
        $old = $this->insertAuditRow($actorId, ['created_at' => now()->subDays(10)]);
        $recent = $this->insertAuditRow($actorId, ['created_at' => now()]);

        $response = $this->getJson(
            '/api/v1/admin/audit-logs?from='.urlencode(now()->subDay()->toIso8601String()),
            $this->bearer($admin['access_token']),
        );

        $uuids = collect($response->json('data.audit_logs'))->pluck('uuid')->all();
        $this->assertContains($recent, $uuids);
        $this->assertNotContains($old, $uuids);
    }

    public function test_malformed_actor_uuid_filter_is_rejected_with_validation_error(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/audit-logs?actor_uuid=not-a-uuid', $this->bearer($admin['access_token']))
            ->assertStatus(422);
    }

    public function test_audit_log_list_is_deterministically_ordered_most_recent_first(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $actorId = UuidBinary::toBinary($admin['user_uuid']);

        $first = $this->insertAuditRow($actorId, ['action_code' => 'QA_ORDER_EVENT', 'created_at' => now()->subMinutes(2)]);
        $second = $this->insertAuditRow($actorId, ['action_code' => 'QA_ORDER_EVENT', 'created_at' => now()->subMinute()]);

        $response = $this->getJson('/api/v1/admin/audit-logs?action_code=QA_ORDER_EVENT', $this->bearer($admin['access_token']));
        $uuids = collect($response->json('data.audit_logs'))->pluck('uuid')->all();

        $this->assertSame([$second, $first], $uuids);
    }

    // -----------------------------------------------------------------
    // DETAIL
    // -----------------------------------------------------------------

    public function test_admin_can_view_audit_log_detail(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->triggerRealAuditedMutation($admin);

        $listResponse = $this->getJson('/api/v1/admin/audit-logs?action_code=SERVICE_CATEGORY_UPDATED', $this->bearer($admin['access_token']));
        $auditUuid = $listResponse->json('data.audit_logs.0.uuid');

        $response = $this->getJson('/api/v1/admin/audit-logs/'.$auditUuid, $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $data = $response->json('data.audit_log');
        $this->assertSame('SERVICE_CATEGORY_UPDATED', $data['action_code']);
        $this->assertSame('SERVICE_CATEGORY', $data['entity_type']);
        $this->assertTrue($data['was_successful']);
        $this->assertSame($admin['user_uuid'], $data['actor']['uuid']);
        $this->assertArrayHasKey('ip_address', $data);
        $this->assertArrayHasKey('user_agent', $data);
    }

    public function test_malformed_audit_log_uuid_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/audit-logs/not-a-uuid', $this->bearer($admin['access_token']))
            ->assertStatus(404);
    }

    public function test_unknown_audit_log_uuid_returns_404(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->getJson('/api/v1/admin/audit-logs/'.UuidBinary::generate(), $this->bearer($admin['access_token']))
            ->assertStatus(404);
    }

    public function test_customer_cannot_view_audit_log_detail(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $customer = $this->createAuthenticatedCartCustomer();
        $auditUuid = $this->insertAuditRow(UuidBinary::toBinary($admin['user_uuid']));

        $this->getJson('/api/v1/admin/audit-logs/'.$auditUuid, $this->bearer($customer['access_token']))
            ->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // SAFETY
    // -----------------------------------------------------------------

    public function test_audit_log_responses_never_expose_secrets_or_old_new_values(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->triggerRealAuditedMutation($admin);

        $listResponse = $this->getJson('/api/v1/admin/audit-logs?action_code=SERVICE_CATEGORY_UPDATED', $this->bearer($admin['access_token']));
        $auditUuid = $listResponse->json('data.audit_logs.0.uuid');

        $detailResponse = $this->getJson('/api/v1/admin/audit-logs/'.$auditUuid, $this->bearer($admin['access_token']));
        $detail = $detailResponse->json('data.audit_log');

        $this->assertArrayNotHasKey('old_values', $detail);
        $this->assertArrayNotHasKey('new_values', $detail);

        $json = json_encode([$listResponse->json(), $detailResponse->json()]);

        foreach (['password_hash', 'refresh_token_hash', 'client_secret', 'Audit QA Category'] as $forbiddenValue) {
            $this->assertStringNotContainsString($forbiddenValue, $json, "Response must never contain {$forbiddenValue}.");
        }
    }

    public function test_audit_log_never_exposes_raw_binary_ids(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $auditUuid = $this->insertAuditRow(UuidBinary::toBinary($admin['user_uuid']));

        $response = $this->getJson('/api/v1/admin/audit-logs/'.$auditUuid, $this->bearer($admin['access_token']));
        $json = json_encode($response->json());

        $this->assertStringNotContainsString(bin2hex(UuidBinary::toBinary($auditUuid)), $json);
    }

    public function test_no_writes_or_partial_state_from_reading_the_audit_log(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $before = DB::table('admin_audit_logs')->count();

        $this->getJson('/api/v1/admin/audit-logs', $this->bearer($admin['access_token']))->assertStatus(200);

        $this->assertSame($before, DB::table('admin_audit_logs')->count());
    }
}
