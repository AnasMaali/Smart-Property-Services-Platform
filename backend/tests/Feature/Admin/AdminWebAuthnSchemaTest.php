<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BLUE V1 Phase A2.1 - Admin WebAuthn/MFA Schema Foundation.
 *
 * Schema-only tests: no HTTP routes, no WebAuthn ceremony logic exists yet
 * (that is Phase A2.2+). These tests exercise the raw database constraints
 * of admin_webauthn_challenge_purposes / admin_webauthn_challenges /
 * admin_webauthn_credentials directly, the same way AdminMutationAuthorizerTest
 * and AdminCapabilityAuthorizationTest already exercise Phase A1's tables.
 */
class AdminWebAuthnSchemaTest extends TestCase
{
    use DatabaseTransactions;

    private function createUser(): string
    {
        $userUuid = UuidBinary::generate();
        $now = now();

        DB::table('users')->insert([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => '+97158'.random_int(1000000, 9999999),
            'email' => 'webauthn.schema.'.random_int(1000000, 9999999).'@example.com',
            'password_hash' => bcrypt('Passw0rd123'),
            'account_status_id' => DB::table('user_account_statuses')->where('code', 'ACTIVE')->value('id'),
            'phone_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'full_name' => 'WebAuthn Schema Test User',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $userUuid;
    }

    /**
     * @return array<string, mixed>
     */
    private function credentialRow(string $userUuid, array $overrides = []): array
    {
        $now = now();

        return array_merge([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'user_id' => UuidBinary::toBinary($userUuid),
            'label' => null,
            'credential_id' => random_bytes(32),
            'public_key' => random_bytes(77),
            'sign_count' => 0,
            'transports' => null,
            'aaguid' => null,
            'backup_eligible' => null,
            'backup_state' => null,
            'revoked_at' => null,
            'revoked_by_user_id' => null,
            'revoke_reason' => null,
            'last_used_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function challengeRow(string $userUuid, string $purposeCode, array $overrides = []): array
    {
        $now = now();

        return array_merge([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'user_id' => UuidBinary::toBinary($userUuid),
            'purpose_id' => DB::table('admin_webauthn_challenge_purposes')->where('code', $purposeCode)->value('id'),
            'challenge_hash' => hash('sha256', random_bytes(32), true),
            'expires_at' => $now->copy()->addMinutes(5),
            'consumed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // admin_webauthn_credentials
    // -----------------------------------------------------------------

    public function test_multiple_credentials_can_belong_to_one_admin_user(): void
    {
        $userUuid = $this->createUser();

        DB::table('admin_webauthn_credentials')->insert($this->credentialRow($userUuid, ['label' => 'Security Key']));
        DB::table('admin_webauthn_credentials')->insert($this->credentialRow($userUuid, ['label' => 'Platform Authenticator']));

        $this->assertSame(
            2,
            DB::table('admin_webauthn_credentials')->where('user_id', UuidBinary::toBinary($userUuid))->count()
        );
    }

    public function test_credential_id_must_be_globally_unique(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();
        $sharedCredentialId = random_bytes(32);

        DB::table('admin_webauthn_credentials')->insert($this->credentialRow($userA, ['credential_id' => $sharedCredentialId]));

        $this->expectException(QueryException::class);

        DB::table('admin_webauthn_credentials')->insert($this->credentialRow($userB, ['credential_id' => $sharedCredentialId]));
    }

    public function test_credential_cannot_reference_a_missing_user(): void
    {
        $this->expectException(QueryException::class);

        DB::table('admin_webauthn_credentials')->insert(
            $this->credentialRow(UuidBinary::generate())
        );
    }

    public function test_revoke_reason_is_required_when_revoked_at_is_set(): void
    {
        $userUuid = $this->createUser();

        $this->expectException(QueryException::class);

        DB::table('admin_webauthn_credentials')->insert($this->credentialRow($userUuid, [
            'revoked_at' => now(),
            'revoke_reason' => null,
        ]));
    }

    public function test_revoked_credential_with_a_reason_is_valid(): void
    {
        $userUuid = $this->createUser();

        DB::table('admin_webauthn_credentials')->insert($this->credentialRow($userUuid, [
            'revoked_at' => now(),
            'revoke_reason' => 'Reported lost by the Admin.',
        ]));

        $this->assertSame(
            1,
            DB::table('admin_webauthn_credentials')->where('user_id', UuidBinary::toBinary($userUuid))->whereNotNull('revoked_at')->count()
        );
    }

    public function test_backup_eligible_flag_rejects_a_non_boolean_value(): void
    {
        $userUuid = $this->createUser();

        $this->expectException(QueryException::class);

        DB::table('admin_webauthn_credentials')->insert($this->credentialRow($userUuid, ['backup_eligible' => 2]));
    }

    public function test_credential_supports_minimal_required_fields_only(): void
    {
        // Confirms every metadata column (label, transports, aaguid,
        // backup_eligible, backup_state) is genuinely optional - Phase A2.1
        // does not yet populate any of them.
        $userUuid = $this->createUser();

        DB::table('admin_webauthn_credentials')->insert($this->credentialRow($userUuid));

        $this->assertSame(
            1,
            DB::table('admin_webauthn_credentials')->where('user_id', UuidBinary::toBinary($userUuid))->count()
        );
    }

    public function test_admin_webauthn_credentials_user_fk_never_references_role_membership(): void
    {
        // Documents the deliberate design (see admin-webauthn-mfa-v1.md):
        // a credential's only FK to identity is users.id - never user_roles
        // or roles. Role validity is re-checked dynamically at the
        // application layer, exactly like auth_sessions.
        $referencedTables = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'admin_webauthn_credentials')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->pluck('REFERENCED_TABLE_NAME')
            ->unique()
            ->values()
            ->all();

        $this->assertSame(['users'], $referencedTables);
    }

    // -----------------------------------------------------------------
    // admin_webauthn_challenges
    // -----------------------------------------------------------------

    public function test_challenge_purpose_must_reference_a_real_purpose(): void
    {
        $userUuid = $this->createUser();

        $this->expectException(QueryException::class);

        DB::table('admin_webauthn_challenges')->insert(
            $this->challengeRow($userUuid, 'REGISTRATION', ['purpose_id' => 999_999])
        );
    }

    public function test_challenge_expiry_must_be_after_created_at(): void
    {
        $userUuid = $this->createUser();
        $now = now();

        $this->expectException(QueryException::class);

        DB::table('admin_webauthn_challenges')->insert(
            $this->challengeRow($userUuid, 'LOGIN_ASSERTION', [
                'created_at' => $now,
                'expires_at' => $now,
            ])
        );
    }

    public function test_challenge_consumed_at_cannot_precede_created_at(): void
    {
        $userUuid = $this->createUser();
        $now = now();

        $this->expectException(QueryException::class);

        DB::table('admin_webauthn_challenges')->insert(
            $this->challengeRow($userUuid, 'STEP_UP', [
                'created_at' => $now,
                'consumed_at' => $now->copy()->subMinute(),
            ])
        );
    }

    public function test_challenge_hash_must_be_unique(): void
    {
        $userUuid = $this->createUser();
        $sharedHash = hash('sha256', 'same-challenge', true);

        DB::table('admin_webauthn_challenges')->insert(
            $this->challengeRow($userUuid, 'REGISTRATION', ['challenge_hash' => $sharedHash])
        );

        $this->expectException(QueryException::class);

        DB::table('admin_webauthn_challenges')->insert(
            $this->challengeRow($userUuid, 'LOGIN_ASSERTION', ['challenge_hash' => $sharedHash])
        );
    }

    public function test_challenge_cannot_reference_a_missing_user(): void
    {
        $this->expectException(QueryException::class);

        DB::table('admin_webauthn_challenges')->insert(
            $this->challengeRow(UuidBinary::generate(), 'REGISTRATION')
        );
    }

    public function test_valid_challenge_can_be_consumed_exactly_once(): void
    {
        $userUuid = $this->createUser();
        $challengeId = UuidBinary::toBinary(UuidBinary::generate());

        DB::table('admin_webauthn_challenges')->insert(
            $this->challengeRow($userUuid, 'STEP_UP', ['id' => $challengeId])
        );

        DB::table('admin_webauthn_challenges')->where('id', $challengeId)->update(['consumed_at' => now()]);

        $this->assertNotNull(
            DB::table('admin_webauthn_challenges')->where('id', $challengeId)->value('consumed_at')
        );
    }

    // -----------------------------------------------------------------
    // admin_webauthn_challenge_purposes / seed consistency
    // -----------------------------------------------------------------

    public function test_seeded_challenge_purposes_are_exactly_the_three_expected_active_codes(): void
    {
        $codes = DB::table('admin_webauthn_challenge_purposes')
            ->where('is_active', 1)
            ->orderBy('code')
            ->pluck('code')
            ->all();

        $this->assertSame(['LOGIN_ASSERTION', 'REGISTRATION', 'STEP_UP'], $codes);
    }
}
