<?php

namespace Tests\Feature\Admin;

use App\Support\Uuid\UuidBinary;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AuthenticatesCustomersForTests;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use DatabaseTransactions;
    use AuthenticatesCustomersForTests;

    private const GENERIC_SESSION_MESSAGE = 'This session is invalid or has expired.';

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
     * Creates a user with the given roles and logs it in through the real
     * Admin HTTP login endpoint (or the customer login endpoint, when the
     * only role is CUSTOMER), returning the raw values needed for the
     * caller's assertions.
     *
     * @param  array<int, string>  $roleCodes
     * @return array{user_uuid: string, access_token: string, session_uuid: string}
     */
    private function loginAs(array $roleCodes): array
    {
        self::$sequence++;

        $userUuid = UuidBinary::generate();
        $phoneNumber = '+97150500'.str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT);
        $now = now();

        DB::table('users')->insert([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => 'admin.authz.'.self::$sequence.'@example.com',
            'password_hash' => Hash::make('Passw0rd123'),
            'account_status_id' => $this->accountStatusId('ACTIVE'),
            'phone_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'full_name' => 'Test Actor',
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

        $isCustomerOnly = $roleCodes === ['CUSTOMER'];

        if ($isCustomerOnly) {
            $session = $this->issueCustomerSession($userUuid);

            return [
                'user_uuid' => $userUuid,
                'access_token' => $session['access_token'],
                'session_uuid' => $session['session_uuid'],
            ];
        }

        $login = $this->postJson('/api/v1/admin/auth/login', [
            'phone_number' => $phoneNumber,
            'password' => 'Passw0rd123',
        ])->assertStatus(200);

        return [
            'user_uuid' => $userUuid,
            'access_token' => $login->json('data.access_token'),
            'session_uuid' => $login->json('data.session_uuid'),
        ];
    }

    /**
     * Creates a user with the given roles and logs it in through the real
     * Customer mobile HTTP login endpoint, regardless of which roles are
     * given - used to obtain a MOBILE_IOS-backed access token for a user
     * who also holds an Admin role, to prove that role membership alone is
     * not what keeps that token off Admin routes.
     *
     * @param  array<int, string>  $roleCodes
     * @return array{user_uuid: string, access_token: string, session_uuid: string}
     */
    private function loginAsMobileCustomer(array $roleCodes): array
    {
        self::$sequence++;

        $userUuid = UuidBinary::generate();
        $phoneNumber = '+97150501'.str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT);
        $now = now();

        DB::table('users')->insert([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => 'admin.authz.mobile.'.self::$sequence.'@example.com',
            'password_hash' => Hash::make('Passw0rd123'),
            'account_status_id' => $this->accountStatusId('ACTIVE'),
            'phone_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'full_name' => 'Test Dual-Role Actor',
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

        $session = $this->issueCustomerSession($userUuid, 'MOBILE_IOS');

        return [
            'user_uuid' => $userUuid,
            'access_token' => $session['access_token'],
            'session_uuid' => $session['session_uuid'],
        ];
    }

    private function getMe(?string $token): TestResponse
    {
        return $token === null
            ? $this->getJson('/api/v1/admin/me')
            : $this->getJson('/api/v1/admin/me', ['Authorization' => "Bearer {$token}"]);
    }

    public function test_admin_token_is_accepted_by_auth_admin_middleware(): void
    {
        $admin = $this->loginAs(['ADMIN']);

        $response = $this->getMe($admin['access_token']);

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['user_uuid' => $admin['user_uuid'], 'roles' => ['ADMIN']],
        ]);
    }

    public function test_super_admin_token_is_accepted_by_auth_admin_middleware(): void
    {
        $superAdmin = $this->loginAs(['SUPER_ADMIN']);

        $response = $this->getMe($superAdmin['access_token']);

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['roles' => ['SUPER_ADMIN']],
        ]);
    }

    public function test_customer_token_is_denied_by_auth_admin_middleware(): void
    {
        $customer = $this->loginAs(['CUSTOMER']);

        $response = $this->getMe($customer['access_token']);

        $response->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_SESSION_MESSAGE,
        ]);
    }

    public function test_customer_mobile_token_from_dual_role_user_is_denied_by_auth_admin_middleware(): void
    {
        // A user holding both CUSTOMER and ADMIN would pass the role check
        // AuthenticateAdmin performs, so this proves the middleware's
        // client_type_id === ADMIN_WEB check is what actually rejects a
        // token that was issued by the ordinary Customer mobile login
        // rather than the Admin login endpoint.
        $dualRole = $this->loginAsMobileCustomer(['CUSTOMER', 'ADMIN']);

        $response = $this->getMe($dualRole['access_token']);

        $response->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_SESSION_MESSAGE,
        ]);
    }

    public function test_admin_web_token_from_dual_role_user_is_denied_by_auth_customer_middleware(): void
    {
        // Mirrors the previous test in the opposite direction: a user
        // holding both ADMIN and CUSTOMER would pass AuthenticateCustomer's
        // CUSTOMER role check, so this proves AuthenticateCustomer's
        // client_type_id-in-mobile-types check is what actually keeps an
        // Admin-Web-issued token off customer routes.
        $dualRole = $this->loginAs(['ADMIN', 'CUSTOMER']);

        $response = $this->getJson('/api/v1/bookings', ['Authorization' => "Bearer {$dualRole['access_token']}"]);

        $response->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_SESSION_MESSAGE,
        ]);
    }

    public function test_missing_bearer_token_is_denied(): void
    {
        $this->getMe(null)->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_SESSION_MESSAGE,
        ]);
    }

    public function test_malformed_token_is_denied(): void
    {
        $this->getMe('this-is-not-a-jwt')->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_SESSION_MESSAGE,
        ]);
    }

    public function test_expired_access_token_is_denied(): void
    {
        $admin = $this->loginAs(['ADMIN']);

        // Firebase\JWT\JWT checks expiry against the real system clock, so
        // Carbon::setTestNow() cannot simulate an expired token here. This
        // hand-crafts a token with the exact same claim shape the real
        // service issues, but with exp/iat/nbf in the past, to exercise the
        // middleware's expiry rejection independent of the wall clock.
        $expiredToken = JWT::encode([
            'sub' => $admin['user_uuid'],
            'sid' => $admin['session_uuid'],
            'role' => 'ADMIN',
            'client' => 'ADMIN_WEB',
            'iat' => now()->subMinutes(30)->getTimestamp(),
            'nbf' => now()->subMinutes(30)->getTimestamp(),
            'exp' => now()->subMinutes(15)->getTimestamp(),
            'jti' => (string) Str::uuid(),
        ], config('jwt.secret'), 'HS256');

        $response = $this->getMe($expiredToken);

        $response->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_SESSION_MESSAGE,
        ]);
    }

    public function test_revoked_admin_session_is_denied(): void
    {
        $admin = $this->loginAs(['ADMIN']);

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$admin['access_token']}"])
            ->assertStatus(200);

        $response = $this->getMe($admin['access_token']);

        $response->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_SESSION_MESSAGE,
        ]);
    }

    public function test_session_expiring_exactly_now_is_denied_by_auth_admin_middleware(): void
    {
        $admin = $this->loginAs(['ADMIN']);

        // A few minutes ahead of login, not the exact login instant: the
        // auth_sessions.expires_at > created_at check constraint forbids
        // setting expires_at to the same instant as created_at. Staying
        // within a few minutes keeps the access token's own exp claim -
        // checked by firebase/php-jwt against PHP's real clock, never
        // Carbon::setTestNow (see test_expired_access_token_is_denied above)
        // - valid, since only Carbon's virtual "now" moves here, not the
        // real wall clock the JWT library reads.
        $boundary = now()->addMinutes(5);

        DB::table('auth_sessions')
            ->where('id', UuidBinary::toBinary($admin['session_uuid']))
            ->update(['expires_at' => $boundary]);

        \Illuminate\Support\Carbon::setTestNow($boundary);

        try {
            $response = $this->getMe($admin['access_token']);
        } finally {
            \Illuminate\Support\Carbon::setTestNow(null);
        }

        $response->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_SESSION_MESSAGE,
        ]);
    }

    public function test_admin_role_removed_after_login_is_denied_on_next_request(): void
    {
        $admin = $this->loginAs(['ADMIN']);

        $this->getMe($admin['access_token'])->assertStatus(200);

        DB::table('user_roles')
            ->where('user_id', UuidBinary::toBinary($admin['user_uuid']))
            ->where('role_id', $this->roleId('ADMIN'))
            ->delete();

        $response = $this->getMe($admin['access_token']);

        $response->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_SESSION_MESSAGE,
        ]);
    }

    public function test_admin_role_deactivated_after_login_is_denied_on_next_request(): void
    {
        $admin = $this->loginAs(['ADMIN']);

        $this->getMe($admin['access_token'])->assertStatus(200);

        DB::table('roles')->where('code', 'ADMIN')->update(['is_active' => 0]);

        $response = $this->getMe($admin['access_token']);

        $response->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_SESSION_MESSAGE,
        ]);
    }

    public function test_account_deactivated_after_login_is_denied_on_next_request(): void
    {
        $admin = $this->loginAs(['ADMIN']);

        $this->getMe($admin['access_token'])->assertStatus(200);

        DB::table('users')
            ->where('id', UuidBinary::toBinary($admin['user_uuid']))
            ->update(['account_status_id' => $this->accountStatusId('DEACTIVATED')]);

        $response = $this->getMe($admin['access_token']);

        $response->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_SESSION_MESSAGE,
        ]);
    }

    public function test_admin_me_returns_only_safe_fields(): void
    {
        $admin = $this->loginAs(['ADMIN']);

        $response = $this->getMe($admin['access_token']);

        $response->assertStatus(200)->assertJsonStructure([
            'success', 'message',
            'data' => ['user_uuid', 'full_name', 'phone_number', 'email', 'roles'],
        ]);

        $data = $response->json('data');
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('password_hash', $data);
        $this->assertArrayNotHasKey('refresh_token_hash', $data);
        $this->assertArrayNotHasKey('role_id', $data);
        $this->assertArrayNotHasKey('role_ids', $data);
        $this->assertArrayNotHasKey('id', $data);
        $this->assertSame(36, strlen($data['user_uuid']));
    }

    public function test_admin_logout_revokes_the_session(): void
    {
        $admin = $this->loginAs(['ADMIN']);

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$admin['access_token']}"])
            ->assertStatus(200)
            ->assertExactJson(['success' => true, 'message' => 'Logged out successfully.']);

        $session = DB::table('auth_sessions')->where('id', UuidBinary::toBinary($admin['session_uuid']))->first();
        $this->assertNotNull($session->revoked_at);

        $this->getMe($admin['access_token'])->assertStatus(401);
    }

    public function test_admin_logout_all_revokes_every_admin_session_for_the_user(): void
    {
        self::$sequence++;
        $phoneNumber = '+97150500'.str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT);
        $now = now();
        $userUuid = UuidBinary::generate();

        DB::table('users')->insert([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => 'admin.logoutall.'.self::$sequence.'@example.com',
            'password_hash' => Hash::make('Passw0rd123'),
            'account_status_id' => $this->accountStatusId('ACTIVE'),
            'phone_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('user_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'full_name' => 'Multi Session Admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('user_roles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'role_id' => $this->roleId('ADMIN'),
            'assigned_by_user_id' => null,
            'assigned_at' => $now,
        ]);

        $firstLogin = $this->postJson('/api/v1/admin/auth/login', [
            'phone_number' => $phoneNumber,
            'password' => 'Passw0rd123',
        ])->assertStatus(200);

        $secondLogin = $this->postJson('/api/v1/admin/auth/login', [
            'phone_number' => $phoneNumber,
            'password' => 'Passw0rd123',
        ])->assertStatus(200);

        $this->postJson('/api/v1/auth/logout-all', [], [
            'Authorization' => 'Bearer '.$firstLogin->json('data.access_token'),
        ])->assertStatus(200);

        $this->getMe($firstLogin->json('data.access_token'))->assertStatus(401);
        $this->getMe($secondLogin->json('data.access_token'))->assertStatus(401);

        $activeSessions = DB::table('auth_sessions')
            ->where('user_id', UuidBinary::toBinary($userUuid))
            ->whereNull('revoked_at')
            ->count();
        $this->assertSame(0, $activeSessions);
    }
}
