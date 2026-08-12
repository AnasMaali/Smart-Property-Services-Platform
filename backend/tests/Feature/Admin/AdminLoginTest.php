<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

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

    public function test_admin_can_log_in(): void
    {
        $admin = $this->createUser(['ADMIN']);

        $response = $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($admin));

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user_uuid' => $admin['user_uuid'],
                    'full_name' => $admin['full_name'],
                    'phone_number' => $admin['phone_number'],
                    'role' => 'ADMIN',
                    'roles' => ['ADMIN'],
                ],
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user_uuid', 'full_name', 'phone_number', 'email', 'role', 'roles',
                    'session_uuid', 'access_token', 'access_token_expires_at',
                    'refresh_token', 'session_expires_at',
                ],
            ]);
    }

    public function test_super_admin_can_log_in(): void
    {
        $superAdmin = $this->createUser(['SUPER_ADMIN']);

        $response = $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($superAdmin));

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data' => [
                'role' => 'SUPER_ADMIN',
                'roles' => ['SUPER_ADMIN'],
            ],
        ]);
    }

    public function test_user_holding_both_admin_and_super_admin_roles_gets_super_admin_as_primary_role(): void
    {
        $user = $this->createUser(['ADMIN', 'SUPER_ADMIN']);

        $response = $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($user));

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['role' => 'SUPER_ADMIN'],
        ]);

        $roles = $response->json('data.roles');
        sort($roles);
        $this->assertSame(['ADMIN', 'SUPER_ADMIN'], $roles);
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

    public function test_successful_login_creates_admin_web_auth_sessions_row(): void
    {
        $admin = $this->createUser(['ADMIN']);

        $response = $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($admin));

        $sessionUuid = $response->json('data.session_uuid');
        $session = DB::table('auth_sessions')->where('id', UuidBinary::toBinary($sessionUuid))->first();

        $this->assertNotNull($session);
        $this->assertNull($session->revoked_at);

        $expectedClientTypeId = (int) DB::table('auth_client_types')->where('code', 'ADMIN_WEB')->value('id');
        $this->assertSame($expectedClientTypeId, (int) $session->client_type_id);
    }

    public function test_access_token_claims_use_admin_web_client_and_resolved_role(): void
    {
        $admin = $this->createUser(['ADMIN']);

        $response = $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($admin));

        $accessToken = $response->json('data.access_token');
        $decoded = JWT::decode($accessToken, new Key(config('jwt.secret'), 'HS256'));

        $this->assertSame('ADMIN', $decoded->role);
        $this->assertSame('ADMIN_WEB', $decoded->client);
        $this->assertSame($admin['user_uuid'], $decoded->sub);
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

    public function test_successful_login_is_not_broken_by_rate_limiting(): void
    {
        $admin = $this->createUser(['ADMIN']);

        $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($admin))->assertStatus(200);
        $this->postJson('/api/v1/admin/auth/login', $this->loginPayload($admin))->assertStatus(200);
    }
}
