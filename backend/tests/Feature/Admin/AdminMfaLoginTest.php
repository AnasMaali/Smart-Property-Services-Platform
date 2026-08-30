<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Admin\WebAuthn\AdminWebAuthnRegistrationOutcome;
use App\Support\Admin\WebAuthn\AdminWebAuthnRegistrationService;
use App\Support\Uuid\UuidBinary;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\Support\WebAuthn\WebAuthnTestAuthenticator;
use Tests\TestCase;

/**
 * BLUE V1 Phase A2.3 - Mandatory Admin MFA Login.
 *
 * Covers Stage 1 -> first-credential bootstrap -> Stage 2 (WebAuthn
 * assertion) -> session issuance end-to-end, over real HTTP, using real
 * ECDSA cryptography (Tests\Support\WebAuthn\WebAuthnTestAuthenticator,
 * introduced in Phase A2.2) - never a shortcut or fake verification path.
 * Complements AdminLoginTest.php, which covers Stage 1's password-only
 * rejection paths (unknown Admin, wrong password, inactive account,
 * missing/inactive role, Customer role, zero auth_sessions rows, zero
 * tokens in the response) in isolation.
 */
class AdminMfaLoginTest extends TestCase
{
    use DatabaseTransactions;

    private const GENERIC_MFA_MESSAGE = 'This WebAuthn verification could not be completed.';

    private static int $sequence = 0;

    private function roleId(string $code): int
    {
        return (int) DB::table('roles')->where('code', $code)->value('id');
    }

    private function accountStatusId(string $code): int
    {
        return (int) DB::table('user_account_statuses')->where('code', $code)->value('id');
    }

    /**
     * @param  array<int, string>  $roleCodes
     * @return array{user_uuid: string, phone_number: string, password: string}
     */
    private function createUser(array $roleCodes, array $overrides = []): array
    {
        self::$sequence++;
        $userUuid = UuidBinary::generate();
        $phoneNumber = '+97156'.str_pad((string) self::$sequence, 7, '0', STR_PAD_LEFT);
        $now = now();

        DB::table('users')->insert(array_merge([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => 'admin.mfa.'.self::$sequence.'@example.com',
            'password_hash' => Hash::make('Passw0rd123'),
            'account_status_id' => $this->accountStatusId('ACTIVE'),
            'phone_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        DB::table('user_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'full_name' => 'MFA Test Admin '.self::$sequence,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($roleCodes as $roleCode) {
            DB::table('user_roles')->insert([
                'user_id' => UuidBinary::toBinary($userUuid),
                'role_id' => $this->roleId($roleCode),
                'assigned_by_user_id' => null,
                'assigned_at' => $now,
            ]);
        }

        return ['user_uuid' => $userUuid, 'phone_number' => $phoneNumber, 'password' => 'Passw0rd123'];
    }

    private function passwordLogin(array $user): TestResponse
    {
        return $this->postJson('/api/v1/admin/auth/login', [
            'phone_number' => $user['phone_number'],
            'password' => $user['password'],
        ]);
    }

    private function decodeB64Url(string $value): string
    {
        return Base64UrlSafe::decode($value);
    }

    /**
     * Registers a credential directly via the Phase A2.2 service layer
     * (never through HTTP) - used for tests where "an Admin already has a
     * credential" is setup state, not the thing under test.
     */
    private function registerCredentialDirectly(array $user): WebAuthnTestAuthenticator
    {
        $userModel = User::where('id', UuidBinary::toBinary($user['user_uuid']))->firstOrFail();
        $registrationService = app(AdminWebAuthnRegistrationService::class);
        $authenticator = new WebAuthnTestAuthenticator;

        $optionsResult = $registrationService->options($userModel, stepUpVerified: false);
        $rpId = $optionsResult->options->rp->id;
        $json = $authenticator->attestationResponseJson($rpId, $optionsResult->options->challenge, $this->originFor($rpId));
        $result = $registrationService->verify($userModel, false, $json, $rpId);

        if ($result->outcome !== AdminWebAuthnRegistrationOutcome::REGISTERED) {
            throw new \RuntimeException('Fixture setup failed: '.$result->outcome->name);
        }

        return $authenticator;
    }

    private function originFor(string $rpId): string
    {
        return 'https://'.$rpId;
    }

    /**
     * Completes Stage 1 (MFA_REQUIRED branch) + Stage 2 for an Admin who
     * already has an active credential, over real HTTP with real crypto.
     */
    private function loginWithExistingCredential(array $user, WebAuthnTestAuthenticator $authenticator, array $verifyOverrides = []): TestResponse
    {
        $login = $this->passwordLogin($user)->assertStatus(200)->assertJson(['data' => ['state' => 'MFA_REQUIRED']]);

        $ticket = $login->json('data.login_ticket');
        $rpId = $login->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($login->json('data.webauthn.challenge'));

        $assertionJson = json_decode(
            $authenticator->assertionResponseJson($rpId, $challenge, $this->originFor($rpId), UuidBinary::toBinary($user['user_uuid'])),
            true
        );

        return $this->postJson('/api/v1/admin/auth/mfa/verify', array_merge([
            'login_ticket' => $ticket,
            'credential' => $assertionJson,
        ], $verifyOverrides));
    }

    // -----------------------------------------------------------------
    // PASSWORD STAGE - existing-credential branch (6, 7)
    // -----------------------------------------------------------------

    public function test_valid_admin_with_active_credential_receives_mfa_required(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $this->registerCredentialDirectly($admin);

        $response = $this->passwordLogin($admin);

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['state' => 'MFA_REQUIRED'],
        ])->assertJsonStructure([
            'data' => ['state', 'login_ticket', 'webauthn' => ['rp_id', 'challenge', 'allow_credentials', 'user_verification', 'timeout']],
        ]);

        $this->assertArrayNotHasKey('access_token', $response->json('data'));
    }

    public function test_valid_super_admin_with_active_credential_receives_mfa_required(): void
    {
        $superAdmin = $this->createUser(['SUPER_ADMIN']);
        $this->registerCredentialDirectly($superAdmin);

        $this->passwordLogin($superAdmin)->assertStatus(200)->assertJson([
            'data' => ['state' => 'MFA_REQUIRED'],
        ]);
    }

    public function test_stage_one_mfa_required_branch_creates_zero_sessions(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $this->registerCredentialDirectly($admin);

        $this->passwordLogin($admin)->assertStatus(200);

        $this->assertSame(0, DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($admin['user_uuid']))->count());
    }

    // -----------------------------------------------------------------
    // FIRST CREDENTIAL BOOTSTRAP (10-16)
    // -----------------------------------------------------------------

    public function test_zero_credential_admin_receives_mfa_enrollment_required(): void
    {
        $admin = $this->createUser(['ADMIN']);

        $this->passwordLogin($admin)->assertStatus(200)->assertJson([
            'data' => ['state' => 'MFA_ENROLLMENT_REQUIRED'],
        ]);
    }

    public function test_first_registration_can_be_completed_from_valid_bootstrap_context(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $login = $this->passwordLogin($admin)->assertStatus(200);

        $ticket = $login->json('data.login_ticket');
        $rpId = $login->json('data.webauthn.rp.id');
        $challenge = $this->decodeB64Url($login->json('data.webauthn.challenge'));

        $authenticator = new WebAuthnTestAuthenticator;
        $attestationJson = json_decode($authenticator->attestationResponseJson($rpId, $challenge, $this->originFor($rpId)), true);

        $enroll = $this->postJson('/api/v1/admin/auth/mfa/enroll', [
            'login_ticket' => $ticket,
            'credential' => $attestationJson,
        ]);

        $enroll->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['state' => 'MFA_REQUIRED'],
        ]);

        $this->assertSame(
            1,
            DB::table('admin_webauthn_credentials')->where('user_id', UuidBinary::toBinary($admin['user_uuid']))->count()
        );
    }

    public function test_customer_cannot_bootstrap_admin_credential(): void
    {
        $customer = $this->createUser(['CUSTOMER']);

        // A Customer's password login never yields a REGISTRATION ticket
        // (Stage 1 rejects them outright) - simulate the only way this
        // endpoint could be probed: an unknown/garbage ticket.
        $response = $this->postJson('/api/v1/admin/auth/mfa/enroll', [
            'login_ticket' => (string) Str::uuid(),
            'credential' => ['id' => 'x', 'rawId' => 'x', 'type' => 'public-key', 'response' => [
                'clientDataJSON' => 'x', 'attestationObject' => 'x',
            ]],
        ]);

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MFA_MESSAGE,
            'data' => null,
        ]);

        $this->assertSame(0, DB::table('admin_webauthn_credentials')->where('user_id', UuidBinary::toBinary($customer['user_uuid']))->count());
    }

    public function test_expired_bootstrap_challenge_fails(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $login = $this->passwordLogin($admin)->assertStatus(200);

        $ticket = $login->json('data.login_ticket');
        $rpId = $login->json('data.webauthn.rp.id');
        $challenge = $this->decodeB64Url($login->json('data.webauthn.challenge'));

        Carbon::setTestNow(now()->addSeconds(301));

        try {
            $authenticator = new WebAuthnTestAuthenticator;
            $attestationJson = json_decode($authenticator->attestationResponseJson($rpId, $challenge, $this->originFor($rpId)), true);

            $response = $this->postJson('/api/v1/admin/auth/mfa/enroll', [
                'login_ticket' => $ticket,
                'credential' => $attestationJson,
            ]);
        } finally {
            Carbon::setTestNow(null);
        }

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MFA_MESSAGE,
            'data' => null,
        ]);
    }

    public function test_replayed_bootstrap_fails(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $login = $this->passwordLogin($admin)->assertStatus(200);

        $ticket = $login->json('data.login_ticket');
        $rpId = $login->json('data.webauthn.rp.id');
        $challenge = $this->decodeB64Url($login->json('data.webauthn.challenge'));

        $authenticator = new WebAuthnTestAuthenticator;
        $attestationJson = json_decode($authenticator->attestationResponseJson($rpId, $challenge, $this->originFor($rpId)), true);

        $payload = ['login_ticket' => $ticket, 'credential' => $attestationJson];

        $first = $this->postJson('/api/v1/admin/auth/mfa/enroll', $payload);
        $second = $this->postJson('/api/v1/admin/auth/mfa/enroll', $payload);

        $first->assertStatus(200);
        $second->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MFA_MESSAGE,
            'data' => null,
        ]);

        $this->assertSame(1, DB::table('admin_webauthn_credentials')->where('user_id', UuidBinary::toBinary($admin['user_uuid']))->count());
    }

    public function test_existing_credential_admin_cannot_use_bootstrap_to_add_another(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $this->registerCredentialDirectly($admin);

        // Force a REGISTRATION ticket to exist for this Admin despite
        // already having a credential, by calling the service directly
        // (Stage 1 itself would never issue one - see AdminLoginAction -
        // this proves the server-side guard on the enroll endpoint itself,
        // not merely that Stage 1 routes them elsewhere).
        $userModel = User::where('id', UuidBinary::toBinary($admin['user_uuid']))->firstOrFail();
        $optionsResult = app(AdminWebAuthnRegistrationService::class)->options($userModel, stepUpVerified: true);
        $this->assertSame(AdminWebAuthnRegistrationOutcome::ELIGIBLE, $optionsResult->outcome);

        $secondAuthenticator = new WebAuthnTestAuthenticator;
        $rpId = $optionsResult->options->rp->id;
        $attestationJson = json_decode($secondAuthenticator->attestationResponseJson($rpId, $optionsResult->options->challenge, $this->originFor($rpId)), true);

        $response = $this->postJson('/api/v1/admin/auth/mfa/enroll', [
            'login_ticket' => $optionsResult->ticket,
            'credential' => $attestationJson,
        ]);

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MFA_MESSAGE,
            'data' => null,
        ]);

        $this->assertSame(1, DB::table('admin_webauthn_credentials')->where('user_id', UuidBinary::toBinary($admin['user_uuid']))->count());
    }

    public function test_successful_first_registration_alone_does_not_create_a_session(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $login = $this->passwordLogin($admin)->assertStatus(200);

        $ticket = $login->json('data.login_ticket');
        $rpId = $login->json('data.webauthn.rp.id');
        $challenge = $this->decodeB64Url($login->json('data.webauthn.challenge'));

        $authenticator = new WebAuthnTestAuthenticator;
        $attestationJson = json_decode($authenticator->attestationResponseJson($rpId, $challenge, $this->originFor($rpId)), true);

        $this->postJson('/api/v1/admin/auth/mfa/enroll', [
            'login_ticket' => $ticket,
            'credential' => $attestationJson,
        ])->assertStatus(200)->assertJson(['data' => ['state' => 'MFA_REQUIRED']]);

        $this->assertSame(0, DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($admin['user_uuid']))->count());
    }

    // -----------------------------------------------------------------
    // MFA VERIFY (17-30)
    // -----------------------------------------------------------------

    public function test_valid_assertion_creates_exactly_one_admin_session(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($admin);

        $this->loginWithExistingCredential($admin, $authenticator)->assertStatus(200);

        $this->assertSame(1, DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($admin['user_uuid']))->count());
    }

    public function test_valid_assertion_returns_access_and_refresh_tokens(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($admin);

        $response = $this->loginWithExistingCredential($admin, $authenticator);

        $response->assertStatus(200)->assertJsonStructure([
            'data' => [
                'user_uuid', 'full_name', 'phone_number', 'email', 'role', 'roles',
                'session_uuid', 'access_token', 'access_token_expires_at',
                'refresh_token', 'session_expires_at',
            ],
        ]);
    }

    public function test_wrong_challenge_creates_no_session(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($admin);

        $login = $this->passwordLogin($admin)->assertStatus(200);
        $ticket = $login->json('data.login_ticket');
        $rpId = $login->json('data.webauthn.rp_id');

        // A bogus challenge never issued for this ticket.
        $bogusChallenge = random_bytes(32);
        $assertionJson = json_decode(
            $authenticator->assertionResponseJson($rpId, $bogusChallenge, $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid'])),
            true
        );

        $response = $this->postJson('/api/v1/admin/auth/mfa/verify', [
            'login_ticket' => $ticket,
            'credential' => $assertionJson,
        ]);

        $response->assertStatus(422)->assertExactJson(['success' => false, 'message' => self::GENERIC_MFA_MESSAGE, 'data' => null]);
        $this->assertSame(0, DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($admin['user_uuid']))->count());
    }

    public function test_expired_challenge_creates_no_session(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($admin);

        $login = $this->passwordLogin($admin)->assertStatus(200);
        $ticket = $login->json('data.login_ticket');
        $rpId = $login->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($login->json('data.webauthn.challenge'));

        Carbon::setTestNow(now()->addSeconds(301));

        try {
            $assertionJson = json_decode(
                $authenticator->assertionResponseJson($rpId, $challenge, $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid'])),
                true
            );

            $response = $this->postJson('/api/v1/admin/auth/mfa/verify', [
                'login_ticket' => $ticket,
                'credential' => $assertionJson,
            ]);
        } finally {
            Carbon::setTestNow(null);
        }

        $response->assertStatus(422)->assertExactJson(['success' => false, 'message' => self::GENERIC_MFA_MESSAGE, 'data' => null]);
        $this->assertSame(0, DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($admin['user_uuid']))->count());
    }

    public function test_replay_creates_no_second_session(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($admin);

        $login = $this->passwordLogin($admin)->assertStatus(200);
        $ticket = $login->json('data.login_ticket');
        $rpId = $login->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($login->json('data.webauthn.challenge'));

        $assertionJson = json_decode(
            $authenticator->assertionResponseJson($rpId, $challenge, $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid'])),
            true
        );
        $payload = ['login_ticket' => $ticket, 'credential' => $assertionJson];

        $first = $this->postJson('/api/v1/admin/auth/mfa/verify', $payload);
        $second = $this->postJson('/api/v1/admin/auth/mfa/verify', $payload);

        $first->assertStatus(200);
        $second->assertStatus(422)->assertExactJson(['success' => false, 'message' => self::GENERIC_MFA_MESSAGE, 'data' => null]);

        $this->assertSame(1, DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($admin['user_uuid']))->count());
    }

    public function test_wrong_credential_creates_no_session(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $this->registerCredentialDirectly($admin);
        $unregistered = new WebAuthnTestAuthenticator;

        $response = $this->loginWithExistingCredential($admin, $unregistered);

        $response->assertStatus(422)->assertExactJson(['success' => false, 'message' => self::GENERIC_MFA_MESSAGE, 'data' => null]);
        $this->assertSame(0, DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($admin['user_uuid']))->count());
    }

    public function test_revoked_credential_creates_no_session(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($admin);

        DB::table('admin_webauthn_credentials')
            ->where('user_id', UuidBinary::toBinary($admin['user_uuid']))
            ->update(['revoked_at' => now(), 'revoke_reason' => 'Test revocation.']);

        // A revoked credential means the Admin now has zero ACTIVE
        // credentials, so Stage 1 itself routes to MFA_ENROLLMENT_REQUIRED
        // - proving the revoked credential is unusable end-to-end, not
        // merely that a stale assertion against it would fail.
        $this->passwordLogin($admin)->assertStatus(200)->assertJson([
            'data' => ['state' => 'MFA_ENROLLMENT_REQUIRED'],
        ]);

        $this->assertSame(0, DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($admin['user_uuid']))->count());
    }

    public function test_wrong_origin_creates_no_session(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($admin);

        $login = $this->passwordLogin($admin)->assertStatus(200);
        $ticket = $login->json('data.login_ticket');
        $rpId = $login->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($login->json('data.webauthn.challenge'));

        $assertionJson = json_decode(
            $authenticator->assertionResponseJson($rpId, $challenge, 'https://evil.test', UuidBinary::toBinary($admin['user_uuid'])),
            true
        );

        $response = $this->postJson('/api/v1/admin/auth/mfa/verify', [
            'login_ticket' => $ticket,
            'credential' => $assertionJson,
        ]);

        $response->assertStatus(422)->assertExactJson(['success' => false, 'message' => self::GENERIC_MFA_MESSAGE, 'data' => null]);
        $this->assertSame(0, DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($admin['user_uuid']))->count());
    }

    public function test_wrong_rp_id_creates_no_session(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($admin);

        $login = $this->passwordLogin($admin)->assertStatus(200);
        $ticket = $login->json('data.login_ticket');
        $challenge = $this->decodeB64Url($login->json('data.webauthn.challenge'));

        $assertionJson = json_decode(
            $authenticator->assertionResponseJson('evil.test', $challenge, $this->originFor('evil.test'), UuidBinary::toBinary($admin['user_uuid'])),
            true
        );

        $response = $this->postJson('/api/v1/admin/auth/mfa/verify', [
            'login_ticket' => $ticket,
            'credential' => $assertionJson,
        ]);

        $response->assertStatus(422)->assertExactJson(['success' => false, 'message' => self::GENERIC_MFA_MESSAGE, 'data' => null]);
        $this->assertSame(0, DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($admin['user_uuid']))->count());
    }

    public function test_missing_user_verification_creates_no_session(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($admin);

        $login = $this->passwordLogin($admin)->assertStatus(200);
        $ticket = $login->json('data.login_ticket');
        $rpId = $login->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($login->json('data.webauthn.challenge'));

        $assertionJson = json_decode(
            $authenticator->assertionResponseJson($rpId, $challenge, $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid']), userVerified: false),
            true
        );

        $response = $this->postJson('/api/v1/admin/auth/mfa/verify', [
            'login_ticket' => $ticket,
            'credential' => $assertionJson,
        ]);

        $response->assertStatus(422)->assertExactJson(['success' => false, 'message' => self::GENERIC_MFA_MESSAGE, 'data' => null]);
        $this->assertSame(0, DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($admin['user_uuid']))->count());
    }

    public function test_invalid_signature_creates_no_session(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($admin);

        $login = $this->passwordLogin($admin)->assertStatus(200);
        $ticket = $login->json('data.login_ticket');
        $rpId = $login->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($login->json('data.webauthn.challenge'));

        $assertionJson = json_decode(
            $authenticator->assertionResponseJson($rpId, $challenge, $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid']), tamperSignature: true),
            true
        );

        $response = $this->postJson('/api/v1/admin/auth/mfa/verify', [
            'login_ticket' => $ticket,
            'credential' => $assertionJson,
        ]);

        $response->assertStatus(422)->assertExactJson(['success' => false, 'message' => self::GENERIC_MFA_MESSAGE, 'data' => null]);
        $this->assertSame(0, DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($admin['user_uuid']))->count());
    }

    public function test_account_deactivated_between_stage_one_and_stage_two_creates_no_session(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($admin);

        $login = $this->passwordLogin($admin)->assertStatus(200);
        $ticket = $login->json('data.login_ticket');
        $rpId = $login->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($login->json('data.webauthn.challenge'));

        DB::table('users')
            ->where('id', UuidBinary::toBinary($admin['user_uuid']))
            ->update(['account_status_id' => $this->accountStatusId('DEACTIVATED')]);

        $assertionJson = json_decode(
            $authenticator->assertionResponseJson($rpId, $challenge, $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid'])),
            true
        );

        $response = $this->postJson('/api/v1/admin/auth/mfa/verify', [
            'login_ticket' => $ticket,
            'credential' => $assertionJson,
        ]);

        $response->assertStatus(422)->assertExactJson(['success' => false, 'message' => self::GENERIC_MFA_MESSAGE, 'data' => null]);
        $this->assertSame(0, DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($admin['user_uuid']))->count());
    }

    public function test_admin_role_revoked_between_stage_one_and_stage_two_creates_no_session(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($admin);

        $login = $this->passwordLogin($admin)->assertStatus(200);
        $ticket = $login->json('data.login_ticket');
        $rpId = $login->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($login->json('data.webauthn.challenge'));

        DB::table('user_roles')
            ->where('user_id', UuidBinary::toBinary($admin['user_uuid']))
            ->where('role_id', $this->roleId('ADMIN'))
            ->delete();

        $assertionJson = json_decode(
            $authenticator->assertionResponseJson($rpId, $challenge, $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid'])),
            true
        );

        $response = $this->postJson('/api/v1/admin/auth/mfa/verify', [
            'login_ticket' => $ticket,
            'credential' => $assertionJson,
        ]);

        $response->assertStatus(422)->assertExactJson(['success' => false, 'message' => self::GENERIC_MFA_MESSAGE, 'data' => null]);
        $this->assertSame(0, DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($admin['user_uuid']))->count());
    }

    public function test_spoofed_authorization_header_has_no_effect_on_stage_two(): void
    {
        // Stage 2 is ticket-driven, not bearer-token-authenticated - a
        // forged/irrelevant Authorization header must have zero influence
        // on the outcome, proving nothing here can be bypassed by claiming
        // authority via a header. Actual authority is re-read fresh from
        // the database, never from any caller-supplied claim.
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($admin);

        $login = $this->passwordLogin($admin)->assertStatus(200);
        $ticket = $login->json('data.login_ticket');
        $rpId = $login->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($login->json('data.webauthn.challenge'));

        $assertionJson = json_decode(
            $authenticator->assertionResponseJson($rpId, $challenge, $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid'])),
            true
        );

        $forgedToken = JWT::encode([
            'sub' => $admin['user_uuid'],
            'sid' => (string) Str::uuid(),
            'role' => 'SUPER_ADMIN',
            'client' => 'ADMIN_WEB',
            'iat' => now()->getTimestamp(),
            'nbf' => now()->getTimestamp(),
            'exp' => now()->addMinutes(15)->getTimestamp(),
            'jti' => (string) Str::uuid(),
        ], config('jwt.secret'), 'HS256');

        $response = $this->postJson('/api/v1/admin/auth/mfa/verify', [
            'login_ticket' => $ticket,
            'credential' => $assertionJson,
        ], ['Authorization' => "Bearer {$forgedToken}"]);

        $response->assertStatus(200);

        $issuedRole = JWT::decode($response->json('data.access_token'), new Key(config('jwt.secret'), 'HS256'))->role;
        $this->assertSame('ADMIN', $issuedRole, 'The issued token role must reflect real DB role membership, never a caller-supplied claim.');
    }

    // -----------------------------------------------------------------
    // SESSION COMPATIBILITY (31-35)
    // -----------------------------------------------------------------

    public function test_mfa_issued_session_works_with_admin_me(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($admin);

        $session = $this->loginWithExistingCredential($admin, $authenticator)->assertStatus(200);

        $this->getJson('/api/v1/admin/me', ['Authorization' => 'Bearer '.$session->json('data.access_token')])
            ->assertStatus(200)
            ->assertJson(['data' => ['user_uuid' => $admin['user_uuid']]]);
    }

    public function test_mfa_issued_session_works_with_capability_middleware(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($admin);

        $session = $this->loginWithExistingCredential($admin, $authenticator)->assertStatus(200);

        $this->getJson('/api/v1/admin/technicians', ['Authorization' => 'Bearer '.$session->json('data.access_token')])
            ->assertStatus(200);
    }

    public function test_refresh_works_normally_after_mfa_issued_session(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($admin);

        $session = $this->loginWithExistingCredential($admin, $authenticator)->assertStatus(200);

        $this->postJson('/api/v1/admin/auth/refresh', ['refresh_token' => $session->json('data.refresh_token')])
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'session_uuid']]);
    }

    public function test_logout_works_normally_after_mfa_issued_session(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($admin);

        $session = $this->loginWithExistingCredential($admin, $authenticator)->assertStatus(200);
        $accessToken = $session->json('data.access_token');

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$accessToken}"])->assertStatus(200);

        $this->getJson('/api/v1/admin/me', ['Authorization' => "Bearer {$accessToken}"])->assertStatus(401);
    }

    public function test_logout_all_works_normally_after_mfa_issued_session(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($admin);

        $sessionA = $this->loginWithExistingCredential($admin, $authenticator)->assertStatus(200);

        $login = $this->passwordLogin($admin)->assertStatus(200);
        $ticket = $login->json('data.login_ticket');
        $rpId = $login->json('data.webauthn.rp_id');
        $challenge = $this->decodeB64Url($login->json('data.webauthn.challenge'));
        $assertionJson = json_decode(
            $authenticator->assertionResponseJson($rpId, $challenge, $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid'])),
            true
        );
        $sessionB = $this->postJson('/api/v1/admin/auth/mfa/verify', ['login_ticket' => $ticket, 'credential' => $assertionJson])->assertStatus(200);

        $this->postJson('/api/v1/auth/logout-all', [], ['Authorization' => 'Bearer '.$sessionA->json('data.access_token')])->assertStatus(200);

        $this->getJson('/api/v1/admin/me', ['Authorization' => 'Bearer '.$sessionA->json('data.access_token')])->assertStatus(401);
        $this->getJson('/api/v1/admin/me', ['Authorization' => 'Bearer '.$sessionB->json('data.access_token')])->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // RATE LIMIT / SECURITY (36-40)
    // -----------------------------------------------------------------

    public function test_password_login_limiter_remains_effective(): void
    {
        $admin = $this->createUser(['ADMIN']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/admin/auth/login', [
                'phone_number' => $admin['phone_number'],
                'password' => 'WrongPassw0rd',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/admin/auth/login', [
            'phone_number' => $admin['phone_number'],
            'password' => 'WrongPassw0rd',
        ])->assertStatus(429);
    }

    public function test_mfa_verify_limiter_works(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $this->registerCredentialDirectly($admin);
        $login = $this->passwordLogin($admin)->assertStatus(200);
        $ticket = $login->json('data.login_ticket');

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/admin/auth/mfa/verify', [
                'login_ticket' => $ticket,
                'credential' => ['id' => 'x', 'rawId' => 'x', 'type' => 'public-key', 'response' => [
                    'clientDataJSON' => 'x', 'authenticatorData' => 'x', 'signature' => 'x',
                ]],
            ]);
        }

        $eleventh = $this->postJson('/api/v1/admin/auth/mfa/verify', [
            'login_ticket' => $ticket,
            'credential' => ['id' => 'x', 'rawId' => 'x', 'type' => 'public-key', 'response' => [
                'clientDataJSON' => 'x', 'authenticatorData' => 'x', 'signature' => 'x',
            ]],
        ]);

        $eleventh->assertStatus(429);
    }

    public function test_challenge_wrong_purpose_rejection_works_end_to_end(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $login = $this->passwordLogin($admin)->assertStatus(200);

        // A REGISTRATION ticket (MFA_ENROLLMENT_REQUIRED branch) must never
        // satisfy Stage 2 (which only accepts LOGIN_ASSERTION challenges).
        $ticket = $login->json('data.login_ticket');
        $rpId = $login->json('data.webauthn.rp.id');
        $challenge = $this->decodeB64Url($login->json('data.webauthn.challenge'));

        $authenticator = new WebAuthnTestAuthenticator;
        $assertionJson = json_decode(
            $authenticator->assertionResponseJson($rpId, $challenge, $this->originFor($rpId), UuidBinary::toBinary($admin['user_uuid'])),
            true
        );

        $response = $this->postJson('/api/v1/admin/auth/mfa/verify', [
            'login_ticket' => $ticket,
            'credential' => $assertionJson,
        ]);

        $response->assertStatus(422)->assertExactJson(['success' => false, 'message' => self::GENERIC_MFA_MESSAGE, 'data' => null]);
    }

    public function test_response_never_leaks_secrets_or_internal_credential_data(): void
    {
        $admin = $this->createUser(['ADMIN']);
        $authenticator = $this->registerCredentialDirectly($admin);

        $mfaRequired = $this->passwordLogin($admin)->assertStatus(200);
        $body = json_encode($mfaRequired->json());

        foreach (['password', 'password_hash', 'public_key', 'private_key', 'role_id', 'refresh_token_hash'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, (string) $body, "Stage 1 MFA_REQUIRED response leaked [{$forbidden}].");
        }

        $session = $this->loginWithExistingCredential($admin, $authenticator)->assertStatus(200);
        $sessionData = $session->json('data');

        $this->assertArrayNotHasKey('password', $sessionData);
        $this->assertArrayNotHasKey('password_hash', $sessionData);
        $this->assertArrayNotHasKey('role_id', $sessionData);
        $this->assertArrayNotHasKey('public_key', $sessionData);
    }
}
