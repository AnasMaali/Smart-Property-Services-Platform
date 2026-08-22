<?php

namespace Tests\Feature\Auth;

use App\Services\Auth\JwtTokenService;
use App\Support\Uuid\UuidBinary;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\AuthenticatesCustomersForTests;
use Tests\TestCase;

class LogoutAllTest extends TestCase
{
    use DatabaseTransactions;
    use AuthenticatesCustomersForTests;

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
        $phoneNumber = '+97154300'.str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT);
        $fullName = 'Layla Haddad';
        $now = now();

        DB::table('users')->insert(array_merge([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => 'logoutall.customer.'.self::$sequence.'@example.com',
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
     * Mints a genuine auth_sessions row and access token for the given
     * customer via AuthenticatesCustomersForTests, without HTTP or a
     * password check.
     *
     * @return array{session_uuid: string, access_token: string, refresh_token: string}
     */
    private function loginCustomer(array $customer, string $clientType = 'MOBILE_IOS'): array
    {
        return $this->issueCustomerSession($customer['user_uuid'], $clientType);
    }

    private function sessionRow(string $sessionUuid): ?object
    {
        return DB::table('auth_sessions')->where('id', UuidBinary::toBinary($sessionUuid))->first();
    }

    private function logoutAll(?string $accessToken)
    {
        if ($accessToken === null) {
            return $this->postJson('/api/v1/auth/logout-all');
        }

        return $this->postJson('/api/v1/auth/logout-all', [], ['Authorization' => 'Bearer '.$accessToken]);
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

    // 1. Successful logout-all.
    public function test_successful_logout_all_returns_200_with_expected_payload(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $response = $this->logoutAll($session['access_token']);

        $response->assertStatus(200)->assertExactJson([
            'success' => true,
            'message' => 'Logged out from all sessions successfully.',
        ]);
    }

    // 2. Current session revoked.
    public function test_logout_all_revokes_the_current_session(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->assertNull($this->sessionRow($session['session_uuid'])->revoked_at);

        $this->logoutAll($session['access_token'])->assertStatus(200);

        $this->assertNotNull($this->sessionRow($session['session_uuid'])->revoked_at);
    }

    // 3. Other sessions for the same user revoked, including MOBILE_IOS and MOBILE_ANDROID.
    public function test_logout_all_revokes_every_session_for_the_same_user(): void
    {
        $customer = $this->createCustomer();
        $iosSession = $this->loginCustomer($customer, 'MOBILE_IOS');
        $androidSession = $this->loginCustomer($customer, 'MOBILE_ANDROID');
        $thirdSession = $this->loginCustomer($customer, 'MOBILE_IOS');

        $this->logoutAll($iosSession['access_token'])->assertStatus(200);

        $this->assertNotNull($this->sessionRow($iosSession['session_uuid'])->revoked_at);
        $this->assertNotNull($this->sessionRow($androidSession['session_uuid'])->revoked_at);
        $this->assertNotNull($this->sessionRow($thirdSession['session_uuid'])->revoked_at);
    }

    // 4. Sessions belonging to other users untouched.
    public function test_logout_all_does_not_affect_other_users_sessions(): void
    {
        $customerA = $this->createCustomer();
        $customerB = $this->createCustomer();
        $sessionA = $this->loginCustomer($customerA);
        $sessionB = $this->loginCustomer($customerB);

        $this->logoutAll($sessionA['access_token'])->assertStatus(200);

        $this->assertNotNull($this->sessionRow($sessionA['session_uuid'])->revoked_at);
        $this->assertNull($this->sessionRow($sessionB['session_uuid'])->revoked_at);
    }

    // 5. Already-revoked sessions remain revoked (their revoked_at is not overwritten).
    public function test_already_revoked_sessions_remain_unchanged(): void
    {
        $customer = $this->createCustomer();
        $currentSession = $this->loginCustomer($customer);
        $otherSession = $this->loginCustomer($customer);

        $preRevokedAt = now();
        DB::table('auth_sessions')
            ->where('id', UuidBinary::toBinary($otherSession['session_uuid']))
            ->update(['revoked_at' => $preRevokedAt]);

        $this->logoutAll($currentSession['access_token'])->assertStatus(200);

        $otherRevokedAtAfter = $this->sessionRow($otherSession['session_uuid'])->revoked_at;

        $this->assertSame($preRevokedAt->toDateTimeString(), Carbon::parse($otherRevokedAtAfter)->toDateTimeString());
    }

    // 6. Session rows are not deleted.
    public function test_logout_all_does_not_delete_session_rows(): void
    {
        $customer = $this->createCustomer();
        $session1 = $this->loginCustomer($customer);
        $session2 = $this->loginCustomer($customer);

        $userId = UuidBinary::toBinary($customer['user_uuid']);
        $countBefore = DB::table('auth_sessions')->where('user_id', $userId)->count();

        $this->logoutAll($session1['access_token'])->assertStatus(200);

        $countAfter = DB::table('auth_sessions')->where('user_id', $userId)->count();

        $this->assertSame($countBefore, $countAfter);
        $this->assertNotNull($this->sessionRow($session1['session_uuid']));
        $this->assertNotNull($this->sessionRow($session2['session_uuid']));
    }

    // 7. refresh_token_hash values unchanged.
    public function test_logout_all_does_not_modify_refresh_token_hashes(): void
    {
        $customer = $this->createCustomer();
        $session1 = $this->loginCustomer($customer);
        $session2 = $this->loginCustomer($customer);

        $hash1Before = $this->sessionRow($session1['session_uuid'])->refresh_token_hash;
        $hash2Before = $this->sessionRow($session2['session_uuid'])->refresh_token_hash;

        $this->logoutAll($session1['access_token'])->assertStatus(200);

        $this->assertSame($hash1Before, $this->sessionRow($session1['session_uuid'])->refresh_token_hash);
        $this->assertSame($hash2Before, $this->sessionRow($session2['session_uuid'])->refresh_token_hash);
    }

    // 8. expires_at unchanged.
    public function test_logout_all_does_not_modify_expires_at(): void
    {
        $customer = $this->createCustomer();
        $session1 = $this->loginCustomer($customer);
        $session2 = $this->loginCustomer($customer);

        $expires1Before = $this->sessionRow($session1['session_uuid'])->expires_at;
        $expires2Before = $this->sessionRow($session2['session_uuid'])->expires_at;

        $this->logoutAll($session1['access_token'])->assertStatus(200);

        $this->assertSame($expires1Before, $this->sessionRow($session1['session_uuid'])->expires_at);
        $this->assertSame($expires2Before, $this->sessionRow($session2['session_uuid'])->expires_at);
    }

    // 9. users.last_login_at unchanged.
    public function test_logout_all_does_not_modify_last_login_at(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $userId = UuidBinary::toBinary($customer['user_uuid']);
        $lastLoginBefore = DB::table('users')->where('id', $userId)->value('last_login_at');

        $this->logoutAll($session['access_token'])->assertStatus(200);

        $lastLoginAfter = DB::table('users')->where('id', $userId)->value('last_login_at');

        $this->assertSame($lastLoginBefore, $lastLoginAfter);
    }

    // 10. Malformed / missing / expired / bad-signature JWT rejected.
    public function test_malformed_or_missing_token_is_rejected_with_generic_response(): void
    {
        $this->logoutAll('not-a-jwt')->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);

        $this->postJson('/api/v1/auth/logout-all')->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);

        $this->postJson('/api/v1/auth/logout-all', [], ['Authorization' => 'Bearer '])
            ->assertStatus(401)->assertExactJson([
                'success' => false,
                'message' => self::GENERIC_MESSAGE,
            ]);
    }

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

        $this->logoutAll($expiredToken)->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);

        $this->assertNull($this->sessionRow($session['session_uuid'])->revoked_at);
    }

    public function test_invalid_signature_is_rejected_with_generic_response(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $badToken = $this->buildToken([
            'sub' => $customer['user_uuid'],
            'sid' => $session['session_uuid'],
        ], str_repeat('x', 64));

        $this->logoutAll($badToken)->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);

        $this->assertNull($this->sessionRow($session['session_uuid'])->revoked_at);
    }

    // 11. Unknown / invalid sid rejected.
    public function test_missing_or_invalid_sid_is_rejected_with_generic_response(): void
    {
        $customer = $this->createCustomer();

        $badFormat = $this->buildToken(['sub' => $customer['user_uuid'], 'sid' => 'not-a-uuid']);
        $this->logoutAll($badFormat)->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);

        $unknownSession = $this->buildToken(['sub' => $customer['user_uuid'], 'sid' => UuidBinary::generate()]);
        $this->logoutAll($unknownSession)->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);

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

        $this->logoutAll($noSid)->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);
    }

    // 12. sid/user mismatch rejected, and no session is revoked as a side effect.
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

        $this->logoutAll($forgedToken)->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);

        $this->assertNull($this->sessionRow($sessionB['session_uuid'])->revoked_at);
    }

    // 13. Already-revoked current session rejected safely, without revoking sibling sessions.
    public function test_already_revoked_current_session_is_rejected_without_affecting_other_sessions(): void
    {
        $customer = $this->createCustomer();
        $currentSession = $this->loginCustomer($customer);
        $otherSession = $this->loginCustomer($customer);

        $this->logoutAll($currentSession['access_token'])->assertStatus(200);
        $revokedAtAfterFirst = $this->sessionRow($currentSession['session_uuid'])->revoked_at;
        $this->assertNotNull($revokedAtAfterFirst);
        $this->assertNotNull($this->sessionRow($otherSession['session_uuid'])->revoked_at);

        // Re-revoking an already fully-logged-out account must reject safely.
        $second = $this->logoutAll($currentSession['access_token']);
        $second->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);

        $this->assertSame($revokedAtAfterFirst, $this->sessionRow($currentSession['session_uuid'])->revoked_at);
    }

    // 14. Expired current session rejected.
    public function test_expired_current_session_is_rejected_with_generic_response(): void
    {
        $customer = $this->createCustomer();
        $currentSession = $this->loginCustomer($customer);
        $otherSession = $this->loginCustomer($customer);

        DB::table('auth_sessions')
            ->where('id', UuidBinary::toBinary($currentSession['session_uuid']))
            ->update([
                'created_at' => now()->subDays(31),
                'expires_at' => now()->subDay(),
            ]);

        $this->logoutAll($currentSession['access_token'])->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);

        $this->assertNull($this->sessionRow($currentSession['session_uuid'])->revoked_at);
        $this->assertNull($this->sessionRow($otherSession['session_uuid'])->revoked_at);
    }

    // 15. No tokens/secrets returned.
    public function test_response_never_leaks_secrets_or_tokens(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $response = $this->logoutAll($session['access_token']);
        $response->assertStatus(200);

        $body = $response->json();

        $this->assertSame(['success', 'message'], array_keys($body));
        $this->assertStringNotContainsString($session['access_token'], (string) json_encode($body));
        $this->assertStringNotContainsString($session['refresh_token'], (string) json_encode($body));
        $this->assertStringNotContainsString(config('jwt.secret'), (string) json_encode($body));
        $this->assertStringNotContainsString($session['session_uuid'], (string) json_encode($body));
    }

    // 16. No new sessions created.
    public function test_logout_all_does_not_create_a_new_session(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $userId = UuidBinary::toBinary($customer['user_uuid']);
        $countBefore = DB::table('auth_sessions')->where('user_id', $userId)->count();

        $this->logoutAll($session['access_token'])->assertStatus(200);

        $countAfter = DB::table('auth_sessions')->where('user_id', $userId)->count();

        $this->assertSame($countBefore, $countAfter);
    }

    // 17. The users row is locked FOR UPDATE before any auth_sessions row is locked.
    public function test_user_row_is_locked_before_session_rows(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        DB::enableQueryLog();

        $this->logoutAll($session['access_token'])->assertStatus(200);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $lockingQueries = collect($queries)
            ->filter(fn (array $entry) => str_contains(strtolower($entry['query']), 'for update'))
            ->values();

        $this->assertGreaterThanOrEqual(3, $lockingQueries->count());

        $firstLockTable = strtolower($lockingQueries->first()['query']);
        $this->assertStringContainsString('from `users`', $firstLockTable);

        $userLockIndex = $lockingQueries->search(fn (array $entry) => str_contains(strtolower($entry['query']), 'from `users`'));
        $sessionLockIndexes = $lockingQueries->filter(fn (array $entry) => str_contains(strtolower($entry['query']), 'from `auth_sessions`'))->keys();

        foreach ($sessionLockIndexes as $sessionLockIndex) {
            $this->assertLessThan($sessionLockIndex, $userLockIndex);
        }
    }

    // 18. Deterministic session locking where practical (ordered by id).
    public function test_session_rows_are_locked_in_deterministic_order(): void
    {
        $customer = $this->createCustomer();
        $session1 = $this->loginCustomer($customer);
        $this->loginCustomer($customer);

        DB::enableQueryLog();

        $this->logoutAll($session1['access_token'])->assertStatus(200);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $bulkLockQuery = collect($queries)->first(fn (array $entry) => str_contains(strtolower($entry['query']), 'for update')
            && str_contains(strtolower($entry['query']), 'from `auth_sessions`')
            && str_contains(strtolower($entry['query']), 'order by')
        );

        $this->assertNotNull($bulkLockQuery);
        $this->assertStringContainsString('order by `id`', strtolower($bulkLockQuery['query']));
    }

    // Extra coverage: non-ACTIVE user's sessions are rejected (flow step "linked user is valid").
    public function test_logout_all_rejects_non_active_user(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        DB::table('users')
            ->where('id', UuidBinary::toBinary($customer['user_uuid']))
            ->update(['account_status_id' => $this->accountStatusId('SUSPENDED')]);

        $this->logoutAll($session['access_token'])->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
        ]);

        $this->assertNull($this->sessionRow($session['session_uuid'])->revoked_at);
    }
}
