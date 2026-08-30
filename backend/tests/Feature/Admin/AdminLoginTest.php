<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * BLUE V1 Phase A2.3: this now covers ONLY Stage 1 (password) of the
 * two-stage Admin login. It never returns a session/token - see
 * AdminMfaLoginTest for the full password -> WebAuthn -> session flow with
 * real cryptography (MFA_REQUIRED / MFA_ENROLLMENT_REQUIRED, first-credential
 * bootstrap, MFA verify, session issuance, and every rejection path).
 */
class AdminLoginTest extends TestCase
{
    use DatabaseTransactions;

    private const GENERIC_MESSAGE = 'The phone number or password you entered is incorrect.';

    private static int $sequence = 0;

    private function accountStatusId(string $code): int
    {
        return (int) DB::table('user_account_statuses')->where('code', $code)->value('id');
    }

    private function roleId(string $code): int
    {
        return (int) DB::table('roles')->where('code', $code)->value('id');
    }

    /**
     * @param  array<int, string>  $roleCodes
     * @return array{user_uuid: string, phone_number: string, password: string, full_name: string}
     */
    private function createUser(array $roleCodes, array $userOverrides = [], string $rawPassword = 'Passw0rd123'): array
    {
        self::$sequence++;

        $userUuid = UuidBinary::generate();
        $phoneNumber = '+97150300'.str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT);
        $fullName = 'Omar Al Admin';
        $now = now();

        DB::table('users')->insert(array_merge([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => 'admin.login.'.self::$sequence.'@example.com',
            'password_hash' => Hash::make($rawPassword),
            'account_status_id' => $this->accountStatusId('ACTIVE'),
            'phone_verified_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $userOverrides));

        DB::table('user_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'full_name' => $fullName,
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

        return [
            'user_uuid' => $userUuid,
            'phone_number' => $phoneNumber,
            'password' => $rawPassword,
            'full_name' => $fullName,
        ];
    }

    private function loginPayload(array $user, array $overrides = []): array
    {
        return array_merge([
            'phone_number' => $user['phone_number'],
            'password' => $user['password'],
        ], $overrides);
    }

    public function test_admin_with_no_credentials_receives_mfa_enrollment_required(): void
    {
        $admin = $this->createUser(['ADMIN']);

        $response = $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($admin));

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['state' => 'MFA_ENROLLMENT_REQUIRED'],
        ])->assertJsonStructure([
            'success', 'message',
            'data' => [
                'state', 'login_ticket',
                'webauthn' => [
                    'rp' => ['id', 'name'],
                    'user' => ['id', 'name', 'display_name'],
                    'challenge', 'pub_key_cred_params', 'authenticator_selection', 'attestation',
                    'exclude_credentials', 'timeout',
                ],
            ],
        ]);
    }

    public function test_super_admin_with_no_credentials_receives_mfa_enrollment_required(): void
    {
        $superAdmin = $this->createUser(['SUPER_ADMIN']);

        $response = $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($superAdmin));

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['state' => 'MFA_ENROLLMENT_REQUIRED'],
        ]);
    }

    public function test_customer_cannot_log_into_admin_auth(): void
    {
        $customer = $this->createUser(['CUSTOMER']);

        $response = $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($customer));

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    public function test_user_with_admin_and_customer_roles_but_admin_role_inactive_is_rejected(): void
    {
        $user = $this->createUser(['CUSTOMER', 'ADMIN']);

        DB::table('roles')->where('code', 'ADMIN')->update(['is_active' => 0]);

        $response = $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($user));

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $admin = $this->createUser(['ADMIN']);

        $response = $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($admin, [
            'password' => 'WrongPassw0rd',
        ]));

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    public function test_unknown_phone_number_and_wrong_password_return_identical_generic_response(): void
    {
        $admin = $this->createUser(['ADMIN']);

        $unknown = $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($admin, [
            'phone_number' => '+971500009999',
        ]));

        $wrongPassword = $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($admin, [
            'password' => 'WrongPassw0rd',
        ]));

        $unknown->assertStatus(422)->assertExactJson(['success' => false, 'message' => self::GENERIC_MESSAGE, 'data' => null]);
        $wrongPassword->assertStatus(422)->assertExactJson(['success' => false, 'message' => self::GENERIC_MESSAGE, 'data' => null]);
    }

    public function test_inactive_admin_account_is_rejected(): void
    {
        $admin = $this->createUser(['ADMIN'], [
            'account_status_id' => $this->accountStatusId('SUSPENDED'),
        ]);

        $response = $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($admin));

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    public function test_deactivated_admin_account_is_rejected(): void
    {
        $admin = $this->createUser(['ADMIN'], [
            'account_status_id' => $this->accountStatusId('DEACTIVATED'),
        ]);

        $response = $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($admin));

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    public function test_admin_login_does_not_require_phone_verification(): void
    {
        $admin = $this->createUser(['ADMIN'], ['phone_verified_at' => null]);

        $response = $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($admin));

        $response->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_stage_one_never_creates_an_auth_sessions_row(): void
    {
        $admin = $this->createUser(['ADMIN']);

        $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($admin))->assertStatus(200);

        $sessions = DB::table('auth_sessions')
            ->where('user_id', UuidBinary::toBinary($admin['user_uuid']))
            ->count();

        $this->assertSame(0, $sessions);
    }

    public function test_stage_one_response_never_contains_a_token(): void
    {
        $admin = $this->createUser(['ADMIN']);

        $response = $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($admin));

        $data = $response->json('data');
        $this->assertArrayNotHasKey('access_token', $data);
        $this->assertArrayNotHasKey('refresh_token', $data);
        $this->assertArrayNotHasKey('session_uuid', $data);
    }

    public function test_response_never_exposes_password_hash_or_refresh_token_hash_or_role_ids(): void
    {
        $admin = $this->createUser(['ADMIN']);

        $response = $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($admin));

        $data = $response->json('data');
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('password_hash', $data);
        $this->assertArrayNotHasKey('refresh_token_hash', $data);
        $this->assertArrayNotHasKey('role_id', $data);
        $this->assertArrayNotHasKey('role_ids', $data);
    }

    public function test_repeated_failed_login_attempts_are_rate_limited(): void
    {
        $admin = $this->createUser(['ADMIN']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($admin, [
                'password' => 'WrongPassw0rd',
            ]))->assertStatus(422);
        }

        $sixthAttempt = $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($admin, [
            'password' => 'WrongPassw0rd',
        ]));

        $sixthAttempt->assertStatus(429);
    }

    public function test_same_admin_phone_is_rate_limited_even_when_source_ip_changes(): void
    {
        $admin = $this->createUser(['ADMIN']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this
                ->withServerVariables([
                    'REMOTE_ADDR' => '198.51.100.'.$attempt,
                ])
                ->postJson('/api/v1/admin/auth/login', $this->loginPayload($admin, [
                    'password' => 'WrongPassw0rd',
                ]));

            $this->assertNotSame(429, $response->status());
        }

        $this
            ->withServerVariables([
                'REMOTE_ADDR' => '203.0.113.250',
            ])
            ->postJson('/api/v1/admin/auth/login', $this->loginPayload($admin, [
                'password' => 'WrongPassw0rd',
            ]))
            ->assertStatus(429);
    }

    public function test_successful_password_stage_is_not_broken_by_rate_limiting(): void
    {
        $admin = $this->createUser(['ADMIN']);

        $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($admin))->assertStatus(200);
        $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($admin))->assertStatus(200);
    }
}
