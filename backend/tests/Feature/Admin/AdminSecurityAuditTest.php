<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Auth\JwtTokenService;
use App\Support\Admin\AdminSecurityAuditAction;
use App\Support\Admin\WebAuthn\AdminWebAuthnRegistrationOutcome;
use App\Support\Admin\WebAuthn\AdminWebAuthnRegistrationService;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\Feature\Admin\Concerns\CreatesAdminFixtures;
use Tests\Support\WebAuthn\WebAuthnTestAuthenticator;
use Tests\TestCase;

/**
 * BLUE V1 Phase A2.6 - Admin authentication/security audit trail.
 *
 * Verifies the seven security events intentionally selected for A2.6:
 *
 * - ADMIN_LOGIN_SUCCESS
 * - ADMIN_LOGIN_MFA_FAILED
 * - WEBAUTHN_CREDENTIAL_REGISTERED
 * - STEP_UP_VERIFIED
 * - STEP_UP_FAILED
 * - ADMIN_LOGOUT
 * - ADMIN_LOGOUT_ALL
 *
 * Also verifies negative boundaries:
 *
 * - password Stage 1 alone is never logged as a successful login;
 * - account/role eligibility failures are not mislabeled as MFA failures;
 * - Customer logout/logout-all never creates Admin security audit records;
 * - no secret WebAuthn/token material is persisted in the audit payload.
 *
 * Real WebAuthn assertions/attestations are used for the ceremonies being
 * tested. Tests where an already-authenticated Admin is merely setup state
 * use CreatesAdminFixtures' production-equivalent Admin session helper.
 */
class AdminSecurityAuditTest extends TestCase
{
    use CreatesAdminFixtures;
    use DatabaseTransactions;

    private static int $securitySequence = 0;

    private function roleId(string $code): int
    {
        return (int) DB::table('roles')->where('code', $code)->value('id');
    }

    private function accountStatusId(string $code): int
    {
        return (int) DB::table('user_account_statuses')->where('code', $code)->value('id');
    }

    /**
     * @param  array<int, string>  $roles
     * @return array{user_uuid:string, phone_number:string, password:string}
     */
    private function createPasswordAdmin(array $roles = ['ADMIN']): array
    {
        self::$securitySequence++;

        $userUuid = UuidBinary::generate();
        $password = 'AuditPassw0rd123';
        $now = now();

        DB::table('users')->insert([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => '+97158'.str_pad((string) self::$securitySequence, 7, '0', STR_PAD_LEFT),
            'email' => 'admin.security.audit.'.self::$securitySequence.'@example.com',
            'password_hash' => Hash::make($password),
            'account_status_id' => $this->accountStatusId('ACTIVE'),
            'phone_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'full_name' => 'Admin Security Audit '.self::$securitySequence,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($roles as $role) {
            DB::table('user_roles')->insert([
                'user_id' => UuidBinary::toBinary($userUuid),
                'role_id' => $this->roleId($role),
                'assigned_by_user_id' => null,
                'assigned_at' => $now,
            ]);
        }

        return [
            'user_uuid' => $userUuid,
            'phone_number' => '+97158'.str_pad((string) self::$securitySequence, 7, '0', STR_PAD_LEFT),
            'password' => $password,
        ];
    }

    private function userModel(string $userUuid): User
    {
        return User::where('id', UuidBinary::toBinary($userUuid))->firstOrFail();
    }

    private function originFor(string $rpId): string
    {
        return 'https://'.$rpId;
    }

    private function decodeB64Url(string $value): string
    {
        return Base64UrlSafe::decode($value);
    }

    private function passwordLogin(array $admin): TestResponse
    {
        return $this->postJson('/api/v1/admin/auth/login', [
            'phone_number' => $admin['phone_number'],
            'password' => $admin['password'],
        ]);
    }

    /**
     * Registration through the service layer is fixture setup only and
     * intentionally bypasses the HTTP enrollment Action/audit.
     */
    private function registerCredentialDirectly(User $admin): WebAuthnTestAuthenticator
    {
        $registration = app(AdminWebAuthnRegistrationService::class);
        $authenticator = new WebAuthnTestAuthenticator;

        $options = $registration->options($admin, stepUpVerified: false);

        $rpId = $options->options->rp->id;

        $attestation = $authenticator->attestationResponseJson(
            $rpId,
            $options->options->challenge,
            $this->originFor($rpId),
        );

        $result = $registration->verify(
            $admin,
            false,
            $attestation,
            $rpId,
        );

        if ($result->outcome !== AdminWebAuthnRegistrationOutcome::REGISTERED) {
            throw new \RuntimeException(
                'Security-audit fixture registration failed: '.$result->outcome->name
            );
        }

        return $authenticator;
    }

    private function loginWithCredential(
        array $admin,
        WebAuthnTestAuthenticator $authenticator,
        bool $tamperSignature = false,
    ): TestResponse {
        $login = $this->passwordLogin($admin)
            ->assertStatus(200)
            ->assertJson(['data' => ['state' => 'MFA_REQUIRED']]);

        $ticket = $login->json('data.login_ticket');
        $rpId = $login->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url(
            $login->json('data.webauthn.challenge')
        );

        $assertion = json_decode(
            $authenticator->assertionResponseJson(
                $rpId,
                $challenge,
                $this->originFor($rpId),
                UuidBinary::toBinary($admin['user_uuid']),
                tamperSignature: $tamperSignature,
            ),
            true,
        );

        return $this->postJson('/api/v1/admin/auth/mfa/verify', [
            'login_ticket' => $ticket,
            'credential' => $assertion,
        ]);
    }

    private function audit(string $actionCode): ?object
    {
        return DB::table('admin_audit_logs')
            ->where('action_code', $actionCode)
            ->orderByDesc('created_at')
            ->first();
    }

    private function auditCount(string $actionCode): int
    {
        return DB::table('admin_audit_logs')
            ->where('action_code', $actionCode)
            ->count();
    }

    private function assertSecurityAuditPayloadContainsNoSecrets(object $audit): void
    {
        $payload = strtolower(implode('|', array_filter([
            $audit->action_description,
            $audit->old_values,
            $audit->new_values,
            $audit->failure_reason,
        ], static fn ($value): bool => is_string($value))));

        foreach ([
            'password',
            'access_token',
            'refresh_token',
            'login_ticket',
            'step_up_ticket',
            'challenge',
            'credential_id',
            'public_key',
            'signature',
            'clientdatajson',
            'authenticatordata',
            'attestationobject',
            'rawid',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $payload,
                "Admin audit payload leaked forbidden material: {$forbidden}"
            );
        }
    }

    // -----------------------------------------------------------------
    // LOGIN
    // -----------------------------------------------------------------

    public function test_successful_admin_mfa_login_is_audited(): void
    {
        $admin = $this->createPasswordAdmin();
        $authenticator = $this->registerCredentialDirectly(
            $this->userModel($admin['user_uuid'])
        );

        $response = $this->loginWithCredential($admin, $authenticator)
            ->assertStatus(200);

        $audit = $this->audit(
            AdminSecurityAuditAction::ADMIN_LOGIN_SUCCESS->value
        );

        $this->assertNotNull($audit);
        $this->assertSame(1, (int) $audit->was_successful);
        $this->assertNull($audit->failure_reason);
        $this->assertSame('AUTH_SESSION', $audit->entity_type);
        $this->assertSame(
            $response->json('data.session_uuid'),
            $audit->entity_identifier
        );

        $newValues = json_decode($audit->new_values, true);

        $this->assertSame('ADMIN_WEB', $newValues['client_type'] ?? null);
        $this->assertSame('ADMIN', $newValues['role'] ?? null);
        $this->assertCount(2, $newValues);

        $this->assertSecurityAuditPayloadContainsNoSecrets($audit);
    }

    public function test_failed_admin_webauthn_login_is_audited_generically(): void
    {
        $admin = $this->createPasswordAdmin();
        $authenticator = $this->registerCredentialDirectly(
            $this->userModel($admin['user_uuid'])
        );

        $this->loginWithCredential(
            $admin,
            $authenticator,
            tamperSignature: true,
        )->assertStatus(422);

        $audit = $this->audit(
            AdminSecurityAuditAction::ADMIN_LOGIN_MFA_FAILED->value
        );

        $this->assertNotNull($audit);
        $this->assertSame(0, (int) $audit->was_successful);
        $this->assertSame('MFA_VERIFICATION_FAILED', $audit->failure_reason);
        $this->assertSame('ADMIN_USER', $audit->entity_type);
        $this->assertSame($admin['user_uuid'], $audit->entity_identifier);
        $this->assertNull($audit->old_values);
        $this->assertNull($audit->new_values);

        $this->assertSecurityAuditPayloadContainsNoSecrets($audit);
    }

    public function test_password_stage_alone_never_creates_login_success_audit(): void
    {
        $admin = $this->createPasswordAdmin();
        $this->registerCredentialDirectly(
            $this->userModel($admin['user_uuid'])
        );

        $this->passwordLogin($admin)
            ->assertStatus(200)
            ->assertJson(['data' => ['state' => 'MFA_REQUIRED']]);

        $this->assertSame(
            0,
            $this->auditCount(
                AdminSecurityAuditAction::ADMIN_LOGIN_SUCCESS->value
            )
        );
    }

    public function test_role_or_account_eligibility_failure_is_not_mislabeled_as_mfa_failure(): void
    {
        $admin = $this->createPasswordAdmin();
        $authenticator = $this->registerCredentialDirectly(
            $this->userModel($admin['user_uuid'])
        );

        $login = $this->passwordLogin($admin)->assertStatus(200);

        $ticket = $login->json('data.login_ticket');
        $rpId = $login->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url(
            $login->json('data.webauthn.challenge')
        );

        DB::table('user_roles')
            ->where('user_id', UuidBinary::toBinary($admin['user_uuid']))
            ->where('role_id', $this->roleId('ADMIN'))
            ->delete();

        $credential = json_decode(
            $authenticator->assertionResponseJson(
                $rpId,
                $challenge,
                $this->originFor($rpId),
                UuidBinary::toBinary($admin['user_uuid']),
            ),
            true,
        );

        $this->postJson('/api/v1/admin/auth/mfa/verify', [
            'login_ticket' => $ticket,
            'credential' => $credential,
        ])->assertStatus(422);

        $this->assertSame(
            0,
            $this->auditCount(
                AdminSecurityAuditAction::ADMIN_LOGIN_MFA_FAILED->value
            )
        );

        $this->assertSame(
            0,
            $this->auditCount(
                AdminSecurityAuditAction::ADMIN_LOGIN_SUCCESS->value
            )
        );
    }

    // -----------------------------------------------------------------
    // FIRST CREDENTIAL REGISTRATION
    // -----------------------------------------------------------------

    public function test_first_webauthn_credential_registration_is_audited_by_internal_uuid_only(): void
    {
        $admin = $this->createPasswordAdmin();

        $login = $this->passwordLogin($admin)
            ->assertStatus(200)
            ->assertJson(['data' => ['state' => 'MFA_ENROLLMENT_REQUIRED']]);

        $ticket = $login->json('data.login_ticket');
        $rpId = $login->json('data.webauthn.rp.id');
        $challenge = $this->decodeB64Url(
            $login->json('data.webauthn.challenge')
        );

        $authenticator = new WebAuthnTestAuthenticator;

        $attestation = json_decode(
            $authenticator->attestationResponseJson(
                $rpId,
                $challenge,
                $this->originFor($rpId),
            ),
            true,
        );

        $this->postJson('/api/v1/admin/auth/mfa/enroll', [
            'login_ticket' => $ticket,
            'credential' => $attestation,
        ])->assertStatus(200);

        $row = DB::table('admin_webauthn_credentials')
            ->where('user_id', UuidBinary::toBinary($admin['user_uuid']))
            ->first();

        $this->assertNotNull($row);

        $audit = $this->audit(
            AdminSecurityAuditAction::WEBAUTHN_CREDENTIAL_REGISTERED->value
        );

        $this->assertNotNull($audit);
        $this->assertSame(1, (int) $audit->was_successful);
        $this->assertSame('ADMIN_WEBAUTHN_CREDENTIAL', $audit->entity_type);
        $this->assertSame(
            UuidBinary::toString($row->id),
            $audit->entity_identifier
        );
        $this->assertNull($audit->old_values);
        $this->assertNull($audit->new_values);

        $this->assertSecurityAuditPayloadContainsNoSecrets($audit);
    }

    // -----------------------------------------------------------------
    // STEP-UP
    // -----------------------------------------------------------------

    public function test_successful_step_up_is_audited(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $authenticator = $this->registerCredentialDirectly(
            $this->userModel($admin['user_uuid'])
        );

        $request = $this->postJson(
            '/api/v1/admin/auth/step-up/request',
            [],
            $this->bearer($admin['access_token'])
        )->assertStatus(200);

        $ticket = $request->json('data.step_up_ticket');
        $rpId = $request->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url(
            $request->json('data.webauthn.challenge')
        );

        $credential = json_decode(
            $authenticator->assertionResponseJson(
                $rpId,
                $challenge,
                $this->originFor($rpId),
                UuidBinary::toBinary($admin['user_uuid']),
            ),
            true,
        );

        $this->postJson('/api/v1/admin/auth/step-up/verify', [
            'step_up_ticket' => $ticket,
            'credential' => $credential,
        ], $this->bearer($admin['access_token']))->assertStatus(200);

        $audit = $this->audit(
            AdminSecurityAuditAction::STEP_UP_VERIFIED->value
        );

        $this->assertNotNull($audit);
        $this->assertSame(1, (int) $audit->was_successful);
        $this->assertSame('AUTH_SESSION', $audit->entity_type);
        $this->assertSame($admin['session_uuid'], $audit->entity_identifier);

        $newValues = json_decode($audit->new_values, true);

        $this->assertSame(
            ['step_up_verified_until'],
            array_keys($newValues)
        );

        $this->assertSecurityAuditPayloadContainsNoSecrets($audit);
    }

    public function test_failed_step_up_is_audited_generically(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->registerCredentialDirectly(
            $this->userModel($admin['user_uuid'])
        );

        $unregisteredAuthenticator = new WebAuthnTestAuthenticator;

        $request = $this->postJson(
            '/api/v1/admin/auth/step-up/request',
            [],
            $this->bearer($admin['access_token'])
        )->assertStatus(200);

        $ticket = $request->json('data.step_up_ticket');
        $rpId = $request->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url(
            $request->json('data.webauthn.challenge')
        );

        $credential = json_decode(
            $unregisteredAuthenticator->assertionResponseJson(
                $rpId,
                $challenge,
                $this->originFor($rpId),
                UuidBinary::toBinary($admin['user_uuid']),
            ),
            true,
        );

        $this->postJson('/api/v1/admin/auth/step-up/verify', [
            'step_up_ticket' => $ticket,
            'credential' => $credential,
        ], $this->bearer($admin['access_token']))->assertStatus(422);

        $audit = $this->audit(
            AdminSecurityAuditAction::STEP_UP_FAILED->value
        );

        $this->assertNotNull($audit);
        $this->assertSame(0, (int) $audit->was_successful);
        $this->assertSame(
            'STEP_UP_VERIFICATION_FAILED',
            $audit->failure_reason
        );
        $this->assertSame('AUTH_SESSION', $audit->entity_type);
        $this->assertSame($admin['session_uuid'], $audit->entity_identifier);

        $this->assertSecurityAuditPayloadContainsNoSecrets($audit);
    }

    // -----------------------------------------------------------------
    // LOGOUT
    // -----------------------------------------------------------------

    public function test_admin_logout_is_audited(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->postJson(
            '/api/v1/auth/logout',
            [],
            $this->bearer($admin['access_token'])
        )->assertStatus(200);

        $audit = $this->audit(
            AdminSecurityAuditAction::ADMIN_LOGOUT->value
        );

        $this->assertNotNull($audit);
        $this->assertSame(1, (int) $audit->was_successful);
        $this->assertSame('AUTH_SESSION', $audit->entity_type);
        $this->assertSame($admin['session_uuid'], $audit->entity_identifier);
        $this->assertNull($audit->old_values);
        $this->assertNull($audit->new_values);

        $this->assertSecurityAuditPayloadContainsNoSecrets($audit);
    }

    public function test_admin_logout_all_is_audited_with_revoked_session_count(): void
    {
        $admin = $this->createAndLoginAdmin(['ADMIN']);

        $this->issueAdminSession(
            $admin['user_uuid'],
            ['ADMIN']
        );

        $this->postJson(
            '/api/v1/auth/logout-all',
            [],
            $this->bearer($admin['access_token'])
        )->assertStatus(200);

        $audit = $this->audit(
            AdminSecurityAuditAction::ADMIN_LOGOUT_ALL->value
        );

        $this->assertNotNull($audit);
        $this->assertSame(1, (int) $audit->was_successful);
        $this->assertSame('ADMIN_USER', $audit->entity_type);
        $this->assertSame($admin['user_uuid'], $audit->entity_identifier);

        $this->assertSame(
            ['revoked_sessions' => 2],
            json_decode($audit->new_values, true)
        );

        $this->assertSecurityAuditPayloadContainsNoSecrets($audit);
    }

    // -----------------------------------------------------------------
    // CUSTOMER ISOLATION
    // -----------------------------------------------------------------

    public function test_customer_logout_and_logout_all_do_not_create_admin_security_audits(): void
    {
        $first = $this->createCustomerSessionForAuditTest();

        $this->postJson(
            '/api/v1/auth/logout',
            [],
            ['Authorization' => 'Bearer '.$first['access_token']]
        )->assertStatus(200);

        $second = $this->createCustomerSessionForAuditTest();

        $this->postJson(
            '/api/v1/auth/logout-all',
            [],
            ['Authorization' => 'Bearer '.$second['access_token']]
        )->assertStatus(200);

        $this->assertSame(
            0,
            DB::table('admin_audit_logs')
                ->whereIn('action_code', [
                    AdminSecurityAuditAction::ADMIN_LOGOUT->value,
                    AdminSecurityAuditAction::ADMIN_LOGOUT_ALL->value,
                ])
                ->count()
        );
    }

    /**
     * @return array{user_uuid:string, session_uuid:string, access_token:string}
     */
    private function createCustomerSessionForAuditTest(): array
    {
        self::$securitySequence++;

        $userUuid = UuidBinary::generate();
        $sessionUuid = UuidBinary::generate();
        $now = now();

        DB::table('users')->insert([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => '+97157'.str_pad((string) self::$securitySequence, 7, '0', STR_PAD_LEFT),
            'email' => 'customer.audit.'.self::$securitySequence.'@example.com',
            'password_hash' => Hash::make('CustomerPassw0rd123'),
            'account_status_id' => $this->accountStatusId('ACTIVE'),
            'phone_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'full_name' => 'Customer Audit '.self::$securitySequence,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_roles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'role_id' => $this->roleId('CUSTOMER'),
            'assigned_by_user_id' => null,
            'assigned_at' => $now,
        ]);

        $clientTypeId = (int) DB::table('auth_client_types')
            ->where('code', 'MOBILE_IOS')
            ->value('id');

        DB::table('auth_sessions')->insert([
            'id' => UuidBinary::toBinary($sessionUuid),
            'user_id' => UuidBinary::toBinary($userUuid),
            'client_type_id' => $clientTypeId,
            'refresh_token_hash' => hash('sha256', random_bytes(32), true),
            'device_name' => null,
            'app_version' => null,
            'ip_address' => null,
            'user_agent' => null,
            'last_used_at' => $now,
            'step_up_verified_at' => null,
            'expires_at' => $now->copy()->addDay(),
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $accessToken = app(JwtTokenService::class)->issueAccessToken(
            $userUuid,
            $sessionUuid,
            'CUSTOMER',
            'MOBILE_IOS',
        );

        return [
            'user_uuid' => $userUuid,
            'session_uuid' => $sessionUuid,
            'access_token' => $accessToken['token'],
        ];
    }
}
