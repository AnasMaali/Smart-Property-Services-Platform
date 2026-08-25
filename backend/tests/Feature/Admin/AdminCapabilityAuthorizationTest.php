<?php

namespace Tests\Feature\Admin;

use App\Support\Admin\AdminAuthorizationService;
use App\Support\Admin\AdminCapability;
use App\Support\Uuid\UuidBinary;
use Firebase\JWT\JWT;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\Support\AuthenticatesCustomersForTests;
use Tests\TestCase;

/**
 * BLUE V1 Phase A1 - Admin Authorization / Permission Foundation.
 *
 * Covers the NEW capability layer (App\Support\Admin\AdminAuthorizationService,
 * App\Http\Middleware\EnsureAdminHasCapability, the admin_permissions /
 * admin_role_permissions tables) on top of, never instead of, the existing
 * `auth.admin` authentication boundary already covered by
 * AdminAuthorizationTest. Every existing Admin Operations test
 * (AdminAssignmentTest, AdminBookingReadTest, AdminContractTest,
 * AdminJobExecutionTest, AdminTechnicianReadTest) is left unmodified and must
 * keep passing unchanged - this file adds coverage, it does not replace any.
 */
class AdminCapabilityAuthorizationTest extends TestCase
{
    use AuthenticatesCustomersForTests;
    use CreatesAdminFixtures;
    use DatabaseTransactions;

    private function authorizationService(): AdminAuthorizationService
    {
        return app(AdminAuthorizationService::class);
    }

    // -----------------------------------------------------------------
    // Service-level: AdminAuthorizationService::authorize()
    // -----------------------------------------------------------------

    public function test_empty_role_list_fails_closed(): void
    {
        $this->assertFalse(
            $this->authorizationService()->authorize([], AdminCapability::BOOKINGS_VIEW)
        );
    }

    public function test_unknown_capability_code_fails_closed_for_admin(): void
    {
        $this->assertFalse(
            $this->authorizationService()->authorize(['ADMIN'], 'bookings.nonexistent_capability')
        );
    }

    public function test_deactivated_capability_row_fails_closed_for_admin(): void
    {
        DB::table('admin_permissions')->where('code', 'bookings.view')->update(['is_active' => 0]);

        $this->assertFalse(
            $this->authorizationService()->authorize(['ADMIN'], AdminCapability::BOOKINGS_VIEW)
        );
    }

    public function test_admin_with_granted_capability_is_authorized(): void
    {
        $this->assertTrue(
            $this->authorizationService()->authorize(['ADMIN'], AdminCapability::BOOKINGS_VIEW)
        );
    }

    public function test_admin_without_granted_capability_is_denied(): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'bookings.view')->value('id');

        DB::table('admin_role_permissions')
            ->where('role_id', $adminRoleId)
            ->where('permission_id', $permissionId)
            ->delete();

        $this->assertFalse(
            $this->authorizationService()->authorize(['ADMIN'], AdminCapability::BOOKINGS_VIEW)
        );
    }

    public function test_super_admin_is_authorized_through_the_centralized_override_with_zero_grant_rows(): void
    {
        $superAdminRoleId = DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');
        $this->assertSame(0, DB::table('admin_role_permissions')->where('role_id', $superAdminRoleId)->count());

        $this->assertTrue(
            $this->authorizationService()->authorize(['SUPER_ADMIN'], AdminCapability::BOOKINGS_VIEW)
        );
    }

    public function test_super_admin_override_does_not_validate_the_capability_code_itself(): void
    {
        // Documents the deliberate design: SUPER_ADMIN bypasses permission
        // ASSIGNMENT only. The override is unconditional on the role, so it
        // never even needs the capability code to exist - see
        // AdminAuthorizationService's own docblock.
        $this->assertTrue(
            $this->authorizationService()->authorize(['SUPER_ADMIN'], 'totally.unknown_capability')
        );
    }

    public function test_one_active_admin_role_is_enough_when_actor_holds_both_roles(): void
    {
        $this->assertTrue(
            $this->authorizationService()->authorize(['ADMIN', 'SUPER_ADMIN'], AdminCapability::BOOKINGS_VIEW)
        );
    }

    // -----------------------------------------------------------------
    // HTTP-level: the admin.capability middleware gating a real route
    // (GET /v1/admin/technicians, gated by technicians.view)
    // -----------------------------------------------------------------

    public function test_admin_with_required_capability_succeeds_over_http(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $response = $this->getJson('/api/v1/admin/technicians', $this->bearer($admin['access_token']));

        $response->assertStatus(200);
    }

    public function test_admin_without_required_capability_is_denied_over_http(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'technicians.view')->value('id');

        DB::table('admin_role_permissions')
            ->where('role_id', $adminRoleId)
            ->where('permission_id', $permissionId)
            ->delete();

        $response = $this->getJson('/api/v1/admin/technicians', $this->bearer($admin['access_token']));

        $response->assertStatus(403)->assertExactJson([
            'success' => false,
            'message' => 'You are not authorized to perform this action.',
        ]);
    }

    public function test_super_admin_succeeds_over_http_through_centralized_override(): void
    {
        $superAdmin = $this->createAndLoginAdmin(['SUPER_ADMIN']);

        $response = $this->getJson('/api/v1/admin/technicians', $this->bearer($superAdmin['access_token']));

        $response->assertStatus(200);
    }

    public function test_super_admin_still_fails_when_account_is_deactivated(): void
    {
        // Proves the override bypasses permission ASSIGNMENT only - it never
        // bypasses authentication. A deactivated account is rejected by
        // auth.admin before EnsureAdminHasCapability ever runs.
        $superAdmin = $this->createAndLoginAdmin(['SUPER_ADMIN']);

        DB::table('users')
            ->where('id', UuidBinary::toBinary($superAdmin['user_uuid']))
            ->update([
                'account_status_id' => DB::table('user_account_statuses')->where('code', 'DEACTIVATED')->value('id'),
            ]);

        $response = $this->getJson('/api/v1/admin/technicians', $this->bearer($superAdmin['access_token']));

        $response->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => 'This session is invalid or has expired.',
        ]);
    }

    public function test_super_admin_still_fails_when_session_is_revoked(): void
    {
        $superAdmin = $this->createAndLoginAdmin(['SUPER_ADMIN']);

        $this->postJson('/api/v1/auth/logout', [], $this->bearer($superAdmin['access_token']))
            ->assertStatus(200);

        $response = $this->getJson('/api/v1/admin/technicians', $this->bearer($superAdmin['access_token']));

        $response->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => 'This session is invalid or has expired.',
        ]);
    }

    public function test_customer_session_cannot_reach_a_capability_gated_admin_route(): void
    {
        $customerUuid = UuidBinary::generate();
        DB::table('users')->insert([
            'id' => UuidBinary::toBinary($customerUuid),
            'phone_number' => '+971507'.random_int(100000, 999999),
            'email' => 'capability.customer.'.random_int(100000, 999999).'@example.com',
            'password_hash' => bcrypt('Passw0rd123'),
            'account_status_id' => DB::table('user_account_statuses')->where('code', 'ACTIVE')->value('id'),
            'phone_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_profiles')->insert([
            'user_id' => UuidBinary::toBinary($customerUuid),
            'full_name' => 'Capability Test Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'user_id' => UuidBinary::toBinary($customerUuid),
            'role_id' => DB::table('roles')->where('code', 'CUSTOMER')->value('id'),
            'assigned_by_user_id' => null,
            'assigned_at' => now(),
        ]);

        $session = $this->issueCustomerSession($customerUuid);

        $response = $this->getJson('/api/v1/admin/technicians', $this->bearer($session['access_token']));

        // Rejected by auth.admin (authentication) before admin.capability
        // (authorization) is ever reached - a Customer token is never a
        // "wrong capability" case, it is a "not an Admin session" case.
        $response->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => 'This session is invalid or has expired.',
        ]);
    }

    public function test_spoofed_super_admin_jwt_role_claim_does_not_bypass_capability_authorization(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        // Remove ADMIN's real grant for technicians.view - a genuine ADMIN
        // token must now be denied. This proves a token whose JWT `role`
        // claim falsely says SUPER_ADMIN is STILL denied: AuthenticateAdmin
        // never trusts that claim, and auth_admin_roles is always re-derived
        // from user_roles/roles in the database, never from the JWT.
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'technicians.view')->value('id');
        DB::table('admin_role_permissions')
            ->where('role_id', $adminRoleId)
            ->where('permission_id', $permissionId)
            ->delete();

        $session = DB::table('auth_sessions')
            ->where('user_id', UuidBinary::toBinary($admin['user_uuid']))
            ->orderByDesc('created_at')
            ->first();

        $spoofedToken = JWT::encode([
            'sub' => $admin['user_uuid'],
            'sid' => UuidBinary::toString($session->id),
            'role' => 'SUPER_ADMIN',
            'client' => 'ADMIN_WEB',
            'iat' => now()->getTimestamp(),
            'nbf' => now()->getTimestamp(),
            'exp' => now()->addMinutes(15)->getTimestamp(),
            'jti' => (string) Str::uuid(),
        ], config('jwt.secret'), 'HS256');

        $response = $this->getJson('/api/v1/admin/technicians', $this->bearer($spoofedToken));

        $response->assertStatus(403)->assertExactJson([
            'success' => false,
            'message' => 'You are not authorized to perform this action.',
        ]);
    }

    public function test_admin_me_requires_no_capability(): void
    {
        // GET /v1/admin/me is authentication-only by design (see
        // routes/api.php) - even an ADMIN with every capability grant
        // removed can still read their own identity.
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        DB::table('admin_role_permissions')
            ->where('role_id', DB::table('roles')->where('code', 'ADMIN')->value('id'))
            ->delete();

        $response = $this->getJson('/api/v1/admin/me', $this->bearer($admin['access_token']));

        $response->assertStatus(200);
    }

    // -----------------------------------------------------------------
    // Data/schema constraints on admin_permissions / admin_role_permissions
    // -----------------------------------------------------------------

    public function test_duplicate_permission_code_is_rejected_by_the_unique_constraint(): void
    {
        $this->expectException(QueryException::class);

        DB::table('admin_permissions')->insert([
            'code' => 'bookings.view',
            'name' => 'Duplicate',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_malformed_permission_code_is_rejected_by_the_check_constraint(): void
    {
        $this->expectException(QueryException::class);

        DB::table('admin_permissions')->insert([
            'code' => 'NoDotOrLowercase',
            'name' => 'Malformed Code',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_duplicate_role_permission_grant_is_rejected_by_the_primary_key(): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permissionId = DB::table('admin_permissions')->where('code', 'bookings.view')->value('id');

        $this->expectException(QueryException::class);

        DB::table('admin_role_permissions')->insert([
            'role_id' => $adminRoleId,
            'permission_id' => $permissionId,
            'granted_by_user_id' => null,
            'granted_at' => now(),
        ]);
    }

    public function test_role_permission_grant_requires_a_real_permission(): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');

        $this->expectException(QueryException::class);

        DB::table('admin_role_permissions')->insert([
            'role_id' => $adminRoleId,
            'permission_id' => 999_999,
            'granted_by_user_id' => null,
            'granted_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------
    // Capability catalog / enum consistency
    // -----------------------------------------------------------------

    public function test_every_admincapability_enum_case_has_a_matching_seeded_row(): void
    {
        foreach (AdminCapability::cases() as $capability) {
            $row = DB::table('admin_permissions')->where('code', $capability->value)->first();

            $this->assertNotNull($row, "Missing admin_permissions row for capability code [{$capability->value}].");
            $this->assertSame(1, (int) $row->is_active);
        }
    }

    public function test_every_current_admincapability_is_granted_to_admin(): void
    {
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');

        foreach (AdminCapability::cases() as $capability) {
            $permissionId = DB::table('admin_permissions')->where('code', $capability->value)->value('id');

            $granted = DB::table('admin_role_permissions')
                ->where('role_id', $adminRoleId)
                ->where('permission_id', $permissionId)
                ->exists();

            $this->assertTrue($granted, "ADMIN is missing the seeded grant for [{$capability->value}] - this would be a regression of existing Admin behavior.");
        }
    }
}
