<?php

namespace Tests\Feature\Auth;

use App\Services\Auth\JwtTokenService;
use App\Support\Uuid\UuidBinary;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use DatabaseTransactions;

    private const GENERIC_MESSAGE = 'This session is invalid or has expired.';

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
        $phoneNumber = '+97153300'.str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT);
        $fullName = 'Omar Rashid';
        $now = now();

        DB::table('users')->insert(array_merge([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => 'logout.customer.'.self::$sequence.'@example.com',
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

    /**
     * Logs the given customer in through the real /login endpoint so tests
     * exercise a genuine auth_sessions row and access token.
     *
     * @return array{session_uuid: string, access_token: string, refresh_token: string}
     */
    private function loginCustomer(array $customer, string $clientType = 'MOBILE_IOS'): array
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'phone_number' => $customer['phone_number'],
            'password' => $customer['password'],
            'client_type' => $clientType,
        ])->assertStatus(200);

        return [
            'session_uuid' => $response->json('data.session_uuid'),
            'access_token' => $response->json('data.access_token'),
            'refresh_token' => $response->json('data.refresh_token'),
        ];
    }

    private function sessionRow(string $sessionUuid): ?object
    {
        return DB::table('auth_sessions')->where('id', UuidBinary::toBinary($sessionUuid))->first();
    }

    private function logout(?string $accessToken)
    {
        if ($accessToken === null) {
            return $this->postJson('/api/v1/auth/logout');
        }

        return $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$accessToken]);
    }

    /**
     * Builds a raw HS256 JWT with the given claim overrides, independently
     * of JwtTokenService, so malformed/expired/mis-signed tokens can be
     * crafted directly for negative test cases.
     */
    private function buildToken(array $claimOverrides = [], ?string $secret = null): string
    {
        $now = now();

        $claims = array_merge([
            'sub' => UuidBinary::generate(),
            'sid' => UuidBinary::generate(),
            'role' => 'CUSTOMER',
            'client' => 'MOBILE_IOS',
            'iat' => $now->getTimestamp(),
            'nbf' => $now->getTimestamp(),
            'exp' => $now->copy()->addMinutes(15)->getTimestamp(),
            'jti' => (string) Str::uuid(),
        ], $claimOverrides);

        return JWT::encode($claims, $secret ?? config('jwt.secret'), 'HS256');
    }

    // 1. Successful logout.
    public function test_successful_logout_returns_200_with_expected_payload(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $response = $this->logout($session['access_token']);

        $response->assertStatus(200)->assertExactJson([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    // 2. revoked_at set in DB.
    public function test_logout_sets_revoked_at_in_database(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->assertNull($this->sessionRow($session['session_uuid'])->revoked_at);

        $this->logout($session['access_token'])->assertStatus(200);

        $this->assertNotNull($this->sessionRow($session['session_uuid'])->revoked_at);
    }

    // 3. Session row not deleted.
    public function test_logout_does_not_delete_the_session_row(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->logout($session['access_token'])->assertStatus(200);

        $this->assertNotNull($this->sessionRow($session['session_uuid']));
        $this->assertSame(
            1,
            DB::table('auth_sessions')->where('id', UuidBinary::toBinary($session['session_uuid']))->count()
        );
    }

    // 4. Malformed / missing token rejected.
    public function test_malformed_or_missing_token_is_rejected_with_generic_response(): void
    {
        $this->logout('not-a-jwt')->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);

        $this->postJson('/api/v1/auth/logout')->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '])
            ->assertStatus(401)->assertExactJson([
                'success' => false,
                'message' => self::GENERIC_MESSAGE,
            ]);
    }

    // 5. Expired access token rejected.
    public function test_expired_access_token_is_rejected_with_generic_response(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $expiredToken = $this->buildToken([
            'sub' => $customer['user_uuid'],
            'sid' => $session['session_uuid'],
            'iat' => now()->subMinutes(30)->getTimestamp(),
            'nbf' => now()->subMinutes(30)->getTimestamp(),
            'exp' => now()->subMinutes(15)->getTimestamp(),
        ]);

        $this->logout($expiredToken)->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);

        $this->assertNull($this->sessionRow($session['session_uuid'])->revoked_at);
    }

    // 6. Invalid signature rejected.
    public function test_invalid_signature_is_rejected_with_generic_response(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $badToken = $this->buildToken([
            'sub' => $customer['user_uuid'],
            'sid' => $session['session_uuid'],
        ], str_repeat('x', 64));

        $this->logout($badToken)->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);

        $this->assertNull($this->sessionRow($session['session_uuid'])->revoked_at);
    }

    // 7. Missing/invalid sid rejected.
    public function test_missing_or_invalid_sid_is_rejected_with_generic_response(): void
    {
        $customer = $this->createCustomer();

        // sid claim present but not a valid UUID.
        $badFormat = $this->buildToken(['sub' => $customer['user_uuid'], 'sid' => 'not-a-uuid']);
        $this->logout($badFormat)->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);

        // sid well-formed but references no known session.
        $unknownSession = $this->buildToken(['sub' => $customer['user_uuid'], 'sid' => UuidBinary::generate()]);
        $this->logout($unknownSession)->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);

        // sid claim missing entirely.
        $now = now();
        $noSid = JWT::encode([
            'sub' => $customer['user_uuid'],
            'role' => 'CUSTOMER',
            'client' => 'MOBILE_IOS',
            'iat' => $now->getTimestamp(),
            'nbf' => $now->getTimestamp(),
            'exp' => $now->copy()->addMinutes(15)->getTimestamp(),
            'jti' => (string) Str::uuid(),
        ], config('jwt.secret'), 'HS256');

        $this->logout($noSid)->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);
    }

    // 8. Session/user mismatch rejected.
    public function test_session_user_mismatch_is_rejected_with_generic_response(): void
    {
        $customerA = $this->createCustomer();
        $customerB = $this->createCustomer();
        $sessionB = $this->loginCustomer($customerB);

        $forgedToken = app(JwtTokenService::class)->issueAccessToken(
            $customerA['user_uuid'],
            $sessionB['session_uuid'],
            'CUSTOMER',
            'MOBILE_IOS'
        )['token'];

        $this->logout($forgedToken)->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);

        $this->assertNull($this->sessionRow($sessionB['session_uuid'])->revoked_at);
    }

    // 9. Already-revoked session handled safely (not reactivated).
    public function test_already_revoked_session_is_rejected_without_reactivation(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->logout($session['access_token'])->assertStatus(200);
        $revokedAtAfterFirst = $this->sessionRow($session['session_uuid'])->revoked_at;
        $this->assertNotNull($revokedAtAfterFirst);

        $second = $this->logout($session['access_token']);
        $second->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);

        $revokedAtAfterSecond = $this->sessionRow($session['session_uuid'])->revoked_at;
        $this->assertSame($revokedAtAfterFirst, $revokedAtAfterSecond);
    }

    // 10. Expired session rejected.
    public function test_expired_session_is_rejected_with_generic_response(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        DB::table('auth_sessions')
            ->where('id', UuidBinary::toBinary($session['session_uuid']))
            ->update([
                'created_at' => now()->subDays(31),
                'expires_at' => now()->subDay(),
            ]);

        $this->logout($session['access_token'])->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);

        $this->assertNull($this->sessionRow($session['session_uuid'])->revoked_at);
    }

    // 11. refresh_token_hash is not modified by logout.
    public function test_logout_does_not_modify_refresh_token_hash(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $hashBefore = $this->sessionRow($session['session_uuid'])->refresh_token_hash;

        $this->logout($session['access_token'])->assertStatus(200);

        $hashAfter = $this->sessionRow($session['session_uuid'])->refresh_token_hash;

        $this->assertSame($hashBefore, $hashAfter);
    }

    // 12. users.last_login_at is not modified by logout.
    public function test_logout_does_not_modify_last_login_at(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $userId = UuidBinary::toBinary($customer['user_uuid']);
        $lastLoginBefore = DB::table('users')->where('id', $userId)->value('last_login_at');

        $this->logout($session['access_token'])->assertStatus(200);

        $lastLoginAfter = DB::table('users')->where('id', $userId)->value('last_login_at');

        $this->assertSame($lastLoginBefore, $lastLoginAfter);
    }

    // 13. No secrets/tokens leaked in the response.
    public function test_response_never_leaks_secrets_or_tokens(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $response = $this->logout($session['access_token']);
        $response->assertStatus(200);

        $body = $response->json();

        $this->assertSame(['success', 'message'], array_keys($body));
        $this->assertStringNotContainsString($session['access_token'], (string) json_encode($body));
        $this->assertStringNotContainsString($session['refresh_token'], (string) json_encode($body));
        $this->assertStringNotContainsString(config('jwt.secret'), (string) json_encode($body));
    }

    // 14. No new session/token created by logout.
    public function test_logout_does_not_create_a_new_session(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $userId = UuidBinary::toBinary($customer['user_uuid']);
        $countBefore = DB::table('auth_sessions')->where('user_id', $userId)->count();

        $this->logout($session['access_token'])->assertStatus(200);

        $countAfter = DB::table('auth_sessions')->where('user_id', $userId)->count();

        $this->assertSame($countBefore, $countAfter);
    }

    /**
     * 15. Concurrent/double logout safety, where practical.
     *
     * As in RefreshTest, true concurrent integration testing of two
     * overlapping transactions racing on the same row is not practical in a
     * single-threaded PHPUnit feature test. Instead this verifies the
     * property that makes it safe: the auth_sessions row is locked FOR
     * UPDATE before it is read for validation, so a second request racing
     * for the same row must wait for the first to commit its revocation
     * before it can even see the row - at which point revoked_at is already
     * set and the second request is rejected. That resulting outcome is
     * verified directly by test_already_revoked_session_is_rejected_without_reactivation.
     */
    public function test_auth_sessions_row_is_locked_for_update_before_revocation(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        DB::enableQueryLog();

        $this->logout($session['access_token'])->assertStatus(200);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $lockingQueries = collect($queries)
            ->filter(fn (array $entry) => str_contains(strtolower($entry['query']), 'for update'))
            ->values();

        $this->assertGreaterThanOrEqual(1, $lockingQueries->count());
        $this->assertStringContainsString('from `auth_sessions`', strtolower($lockingQueries->first()['query']));
    }

    // Extra coverage: non-ACTIVE user's session is rejected (flow step 3).
    public function test_logout_rejects_non_active_user(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        DB::table('users')
            ->where('id', UuidBinary::toBinary($customer['user_uuid']))
            ->update(['account_status_id' => $this->accountStatusId('SUSPENDED')]);

        $this->logout($session['access_token'])->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);

        $this->assertNull($this->sessionRow($session['session_uuid'])->revoked_at);
    }
}
