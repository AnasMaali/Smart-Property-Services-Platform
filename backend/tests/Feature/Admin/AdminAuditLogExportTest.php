<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Admin Reports - Audit Log CSV/PDF export
 * (App\Actions\Admin\Reports\AdminExportAuditLogAction). Reuses the exact
 * filters App\Actions\Admin\Audit\AdminListAuditLogsAction's screen already
 * accepts - never a second audit trail, and the Audit Log stays
 * read-only (this export performs no writes).
 */
class AdminAuditLogExportTest extends TestCase
{
    use CreatesAdminFixtures;
    use CreatesContractFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
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

    public function test_unauthenticated_request_is_denied(): void
    {
        $this->getJson('/api/v1/admin/reports/audit-log/csv')->assertStatus(401);
    }

    public function test_admin_without_audit_view_is_denied(): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'audit.view')->value('id');
        DB::table('admin_role_permissions')->where('role_id', $adminRoleId)->where('permission_id', $permissionId)->delete();

        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->get('/api/v1/admin/reports/audit-log/csv', $this->bearer($admin['access_token']))->assertStatus(403);
    }

    public function test_csv_export_contains_matching_rows_and_respects_filters(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $actorId = UuidBinary::toBinary($admin['user_uuid']);

        $this->insertAuditRow($actorId, ['action_code' => 'MATCH_ME', 'entity_identifier' => 'entity-1']);
        $this->insertAuditRow($actorId, ['action_code' => 'DO_NOT_MATCH', 'entity_identifier' => 'entity-2']);

        $response = $this->get('/api/v1/admin/reports/audit-log/csv?action_code=MATCH_ME', $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $body = $response->streamedContent();
        $this->assertStringContainsString('entity-1', $body);
        $this->assertStringNotContainsString('entity-2', $body);
    }

    public function test_csv_export_never_mutates_any_audit_log_row(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $countBefore = DB::table('admin_audit_logs')->count();

        $this->get('/api/v1/admin/reports/audit-log/csv', $this->bearer($admin['access_token']))->assertStatus(200);

        $this->assertSame($countBefore, DB::table('admin_audit_logs')->count());
    }

    public function test_pdf_export_returns_a_pdf(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);
        $this->insertAuditRow(UuidBinary::toBinary($admin['user_uuid']));

        $response = $this->get('/api/v1/admin/reports/audit-log/pdf', $this->bearer($admin['access_token']));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
    }
}
