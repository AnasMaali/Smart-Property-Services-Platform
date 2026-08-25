<?php

namespace Tests\Feature\Admin\Concerns;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Technician\Concerns\CreatesTechnicianFixtures;
use Tests\Support\AuthenticatesAdminsForTests;

/**
 * Admin-Operations-specific fixture builders (BLUE V1 Phase 9B), layered on
 * top of Tests\Feature\Technician\Concerns\CreatesTechnicianFixtures. Every
 * Admin Operations test needs a real, authenticated Admin/Super Admin
 * session (not just a bare actor uuid, since these tests exercise the real
 * routes end-to-end).
 *
 * BLUE V1 Phase A2.3 made Admin login MFA-gated - createAndLoginAdmin() no
 * longer drives the real HTTP login endpoint (which now only ever returns
 * an MFA challenge, never a token); it mints the session directly via
 * Tests\Support\AuthenticatesAdminsForTests, the exact same production
 * session-issuance code the real post-MFA login path uses. Tests that
 * specifically exercise the Admin login/MFA flow itself live in
 * AdminMfaLoginTest and drive the real HTTP endpoints with real WebAuthn
 * cryptography instead.
 */
trait CreatesAdminFixtures
{
    use AuthenticatesAdminsForTests;
    use CreatesTechnicianFixtures;

    private static int $adminOpsFixtureSequence = 0;

    private const ADMIN_FIXTURE_PASSWORD = 'AdminOpsTestPassw0rd';

    /**
     * @param  array<int, string>  $roleCodes
     * @return array{user_uuid: string, access_token: string}
     */
    private function createAndLoginAdmin(array $roleCodes = ['ADMIN']): array
    {
        self::$adminOpsFixtureSequence++;
        $sequence = self::$adminOpsFixtureSequence;
        $userUuid = UuidBinary::generate();
        $phoneNumber = '+97159'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        $now = now();

        DB::table('users')->insert([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => 'admin.ops.qa.'.$sequence.'@example.com',
            'password_hash' => Hash::make(self::ADMIN_FIXTURE_PASSWORD),
            'account_status_id' => (int) DB::table('user_account_statuses')->where('code', 'ACTIVE')->value('id'),
            'phone_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'full_name' => 'Admin Ops QA '.$sequence,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($roleCodes as $roleCode) {
            DB::table('user_roles')->insert([
                'user_id' => UuidBinary::toBinary($userUuid),
                'role_id' => (int) DB::table('roles')->where('code', $roleCode)->value('id'),
                'assigned_by_user_id' => null,
                'assigned_at' => $now,
            ]);
        }

        $session = $this->issueAdminSession($userUuid, $roleCodes);

        return [
            'user_uuid' => $userUuid,
            'access_token' => $session['access_token'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function bearer(string $accessToken): array
    {
        return ['Authorization' => 'Bearer '.$accessToken];
    }

    private function auditLogsFor(string $entityIdentifier): Collection
    {
        return DB::table('admin_audit_logs')->where('entity_identifier', $entityIdentifier)->get();
    }
}
