<?php

namespace Tests\Feature\Auth;

use App\Support\Uuid\UuidBinary;
use Carbon\Carbon;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
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
     * @return array{user_uuid: string, phone_number: string, password: string, full_name: string}
     */
    private function createCustomer(array $userOverrides = [], bool $assignCustomerRole = true, string $rawPassword = 'Passw0rd123'): array
    {
        self::$sequence++;

        $userUuid = UuidBinary::generate();
        $phoneNumber = '+97150200'.str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT);
        $fullName = 'Layla Hassan';
        $now = now();

        DB::table('users')->insert(array_merge([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => 'login.customer.'.self::$sequence.'@example.com',
            'password_hash' => Hash::make($rawPassword),
            'account_status_id' => $this->accountStatusId('ACTIVE'),
            'phone_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $userOverrides));

        DB::table('user_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'full_name' => $fullName,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($assignCustomerRole) {
            DB::table('user_roles')->insert([
                'user_id' => UuidBinary::toBinary($userUuid),
                'role_id' => $this->roleId('CUSTOMER'),
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

    private function loginPayload(array $customer, array $overrides = []): array
    {
        return array_merge([
            'phone_number' => $customer['phone_number'],
            'password' => $customer['password'],
            'client_type' => 'MOBILE_IOS',
        ], $overrides);
    }

    public function test_successful_login_returns_200_with_expected_payload(): void
    {
        $customer = $this->createCustomer();

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($customer, [
            'device_name' => "Layla's iPhone",
            'app_version' => '1.2.0',
        ]));

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user_uuid' => $customer['user_uuid'],
                    'full_name' => $customer['full_name'],
                    'phone_number' => $customer['phone_number'],
                    'role' => 'CUSTOMER',
                ],
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user_uuid',
                    'full_name',
                    'phone_number',
                    'email',
                    'role',
                    'session_uuid',
                    'access_token',
                    'access_token_expires_at',
                    'refresh_token',
                    'session_expires_at',
                ],
            ]);

        $data = $response->json('data');
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('password_hash', $data);
        $this->assertArrayNotHasKey('refresh_token_hash', $data);
    }

    public function test_unknown_phone_number_and_wrong_password_return_identical_generic_response(): void
    {
        $customer = $this->createCustomer();

        $unknownPhoneResponse = $this->postJson('/api/v1/auth/login', $this->loginPayload($customer, [
            'phone_number' => '+971500009999',
        ]));

        $wrongPasswordResponse = $this->postJson('/api/v1/auth/login', $this->loginPayload($customer, [
            'password' => 'WrongPassw0rd',
        ]));

        $unknownPhoneResponse->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);

        $wrongPasswordResponse->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    public function test_pending_verification_user_is_rejected_with_generic_response(): void
    {
        $customer = $this->createCustomer([
            'account_status_id' => $this->accountStatusId('PENDING_VERIFICATION'),
            'phone_verified_at' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($customer));

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    public function test_suspended_user_is_rejected_with_generic_response(): void
    {
        $customer = $this->createCustomer([
            'account_status_id' => $this->accountStatusId('SUSPENDED'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($customer));

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    public function test_deactivated_user_is_rejected_with_generic_response(): void
    {
        $customer = $this->createCustomer([
            'account_status_id' => $this->accountStatusId('DEACTIVATED'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($customer));

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    public function test_unverified_phone_is_rejected_with_generic_response(): void
    {
        $customer = $this->createCustomer([
            'phone_verified_at' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($customer));

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    public function test_missing_customer_role_is_rejected_with_generic_response(): void
    {
        $customer = $this->createCustomer([], assignCustomerRole: false);

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($customer));

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    public function test_inactive_customer_role_is_rejected_with_generic_response(): void
    {
        $customer = $this->createCustomer();

        DB::table('roles')->where('code', 'CUSTOMER')->update(['is_active' => 0]);

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($customer));

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    public function test_invalid_client_type_is_rejected(): void
    {
        $customer = $this->createCustomer();

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($customer, [
            'client_type' => 'WEB_BROWSER',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['client_type']);
    }

    public function test_inactive_client_type_is_rejected(): void
    {
        $customer = $this->createCustomer();

        DB::table('auth_client_types')->where('code', 'MOBILE_ANDROID')->update(['is_active' => 0]);

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($customer, [
            'client_type' => 'MOBILE_ANDROID',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['client_type']);
    }

    public function test_admin_web_client_type_is_rejected_for_customer_login(): void
    {
        $customer = $this->createCustomer();

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($customer, [
            'client_type' => 'ADMIN_WEB',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['client_type']);
    }

    public function test_successful_login_creates_auth_sessions_row(): void
    {
        $customer = $this->createCustomer();

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($customer));

        $sessionUuid = $response->json('data.session_uuid');

        $session = DB::table('auth_sessions')->where('id', UuidBinary::toBinary($sessionUuid))->first();

        $this->assertNotNull($session);
        $this->assertSame(
            UuidBinary::toBinary($customer['user_uuid']),
            $session->user_id
        );
        $this->assertNull($session->revoked_at);
        $this->assertNotNull($session->last_used_at);

        $expectedClientTypeId = (int) DB::table('auth_client_types')->where('code', 'MOBILE_IOS')->value('id');
        $this->assertSame($expectedClientTypeId, (int) $session->client_type_id);
    }

    public function test_refresh_token_hash_matches_sha256_of_returned_raw_token(): void
    {
        $customer = $this->createCustomer();

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($customer));

        $rawRefreshToken = $response->json('data.refresh_token');
        $sessionUuid = $response->json('data.session_uuid');

        $storedHash = DB::table('auth_sessions')->where('id', UuidBinary::toBinary($sessionUuid))->value('refresh_token_hash');

        $this->assertSame(hash('sha256', hex2bin($rawRefreshToken), true), $storedHash);
    }

    public function test_raw_refresh_token_is_never_stored(): void
    {
        $customer = $this->createCustomer();

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($customer));

        $rawRefreshToken = $response->json('data.refresh_token');
        $sessionUuid = $response->json('data.session_uuid');

        $session = DB::table('auth_sessions')->where('id', UuidBinary::toBinary($sessionUuid))->first();

        $this->assertNotSame($rawRefreshToken, $session->refresh_token_hash);
        $this->assertNotSame(hex2bin($rawRefreshToken), $session->refresh_token_hash);
        $this->assertSame(32, strlen($session->refresh_token_hash));
    }

    public function test_access_token_verifies_with_configured_secret(): void
    {
        $customer = $this->createCustomer();

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($customer));

        $accessToken = $response->json('data.access_token');

        $decoded = JWT::decode($accessToken, new Key(config('jwt.secret'), 'HS256'));

        $this->assertSame($customer['user_uuid'], $decoded->sub);
    }

    public function test_access_token_claims_and_expiry_are_correct(): void
    {
        $customer = $this->createCustomer();

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($customer, [
            'client_type' => 'MOBILE_ANDROID',
        ]));

        $accessToken = $response->json('data.access_token');
        $sessionUuid = $response->json('data.session_uuid');

        $decoded = JWT::decode($accessToken, new Key(config('jwt.secret'), 'HS256'));

        $this->assertSame($customer['user_uuid'], $decoded->sub);
        $this->assertSame($sessionUuid, $decoded->sid);
        $this->assertSame('CUSTOMER', $decoded->role);
        $this->assertSame('MOBILE_ANDROID', $decoded->client);
        $this->assertIsInt($decoded->iat);
        $this->assertIsInt($decoded->nbf);
        $this->assertIsInt($decoded->exp);
        $this->assertNotEmpty($decoded->jti);
        $this->assertSame(15 * 60, $decoded->exp - $decoded->iat);
    }

    public function test_access_token_contains_no_sensitive_claims(): void
    {
        $customer = $this->createCustomer();

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($customer));

        $accessToken = $response->json('data.access_token');

        $decoded = JWT::decode($accessToken, new Key(config('jwt.secret'), 'HS256'));

        $claims = array_keys((array) $decoded);
        sort($claims);

        $this->assertSame(['client', 'exp', 'iat', 'jti', 'nbf', 'role', 'sid', 'sub'], $claims);
    }

    public function test_session_expiry_is_approximately_thirty_days(): void
    {
        $customer = $this->createCustomer();

        $before = now();
        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($customer));

        $sessionExpiresAt = Carbon::parse($response->json('data.session_expires_at'));

        $diffSeconds = $before->copy()->addDays(30)->diffInSeconds($sessionExpiresAt, false);

        $this->assertTrue(abs($diffSeconds) < 10, "Expected session expiry within 10 seconds of +30 days, diff was {$diffSeconds}s.");
    }

    public function test_last_login_at_is_updated_on_successful_login(): void
    {
        $customer = $this->createCustomer();

        $before = DB::table('users')->where('id', UuidBinary::toBinary($customer['user_uuid']))->value('last_login_at');
        $this->assertNull($before);

        $this->postJson('/api/v1/auth/login', $this->loginPayload($customer))->assertStatus(200);

        $after = DB::table('users')->where('id', UuidBinary::toBinary($customer['user_uuid']))->value('last_login_at');
        $this->assertNotNull($after);
    }

    public function test_device_metadata_and_user_agent_are_stored(): void
    {
        $customer = $this->createCustomer();

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($customer, [
            'device_name' => "Layla's iPhone 15",
            'app_version' => '2.3.1',
        ]), [
            'User-Agent' => 'BlueMobileApp/2.3.1 (iOS 17.4)',
        ]);

        $sessionUuid = $response->json('data.session_uuid');
        $session = DB::table('auth_sessions')->where('id', UuidBinary::toBinary($sessionUuid))->first();

        $this->assertSame("Layla's iPhone 15", $session->device_name);
        $this->assertSame('2.3.1', $session->app_version);
        $this->assertSame('BlueMobileApp/2.3.1 (iOS 17.4)', $session->user_agent);
        $this->assertNotNull($session->ip_address);
        $this->assertContains(strlen($session->ip_address), [4, 16]);
    }

    public function test_login_without_optional_device_metadata_succeeds(): void
    {
        $customer = $this->createCustomer();

        $response = $this->postJson('/api/v1/auth/login', $this->loginPayload($customer));

        $response->assertStatus(200);

        $sessionUuid = $response->json('data.session_uuid');
        $session = DB::table('auth_sessions')->where('id', UuidBinary::toBinary($sessionUuid))->first();

        $this->assertNull($session->device_name);
        $this->assertNull($session->app_version);
    }

    public function test_failed_login_creates_no_session_row(): void
    {
        $customer = $this->createCustomer();

        $countBefore = DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($customer['user_uuid']))->count();

        $this->postJson('/api/v1/auth/login', $this->loginPayload($customer, [
            'password' => 'WrongPassw0rd',
        ]))->assertStatus(422);

        $countAfter = DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($customer['user_uuid']))->count();

        $this->assertSame($countBefore, $countAfter);

        $lastLoginAt = DB::table('users')->where('id', UuidBinary::toBinary($customer['user_uuid']))->value('last_login_at');
        $this->assertNull($lastLoginAt);
    }

    public function test_rejected_status_login_creates_no_session_row(): void
    {
        $customer = $this->createCustomer([
            'account_status_id' => $this->accountStatusId('SUSPENDED'),
        ]);

        $this->postJson('/api/v1/auth/login', $this->loginPayload($customer))->assertStatus(422);

        $countAfter = DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($customer['user_uuid']))->count();
        $this->assertSame(0, $countAfter);
    }
}
