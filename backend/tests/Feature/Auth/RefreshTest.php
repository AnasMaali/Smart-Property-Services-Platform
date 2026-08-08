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

class RefreshTest extends TestCase
{
    use DatabaseTransactions;

    private const GENERIC_MESSAGE = 'This refresh token is invalid or has expired.';

    private static int $sequence = 0;

    private function accountStatusId(string $code): int
    {
        return (int) DB::table('user_account_statuses')->where('code', $code)->value('id');
    }

    private function roleId(string $code): int
    {
        return (int) DB::table('roles')->where('code', $code)->value('id');
    }

    private function clientTypeId(string $code): int
    {
        return (int) DB::table('auth_client_types')->where('code', $code)->value('id');
    }

    /**
     * @return array{user_uuid: string, phone_number: string, password: string, full_name: string}
     */
    private function createCustomer(array $userOverrides = [], bool $assignCustomerRole = true, string $rawPassword = 'Passw0rd123'): array
    {
        self::$sequence++;

        $userUuid = UuidBinary::generate();
        $phoneNumber = '+97152300'.str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT);
        $fullName = 'Nadia Farouk';
        $now = now();

        DB::table('users')->insert(array_merge([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => 'refresh.customer.'.self::$sequence.'@example.com',
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
     * exercise a genuine auth_sessions row and raw refresh token, then
     * returns everything needed to drive /refresh against it.
     *
     * @return array{session_uuid: string, refresh_token: string, access_token: string, client_type: string}
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
            'refresh_token' => $response->json('data.refresh_token'),
            'access_token' => $response->json('data.access_token'),
            'client_type' => $clientType,
        ];
    }

    private function sessionRow(string $sessionUuid): ?object
    {
        return DB::table('auth_sessions')->where('id', UuidBinary::toBinary($sessionUuid))->first();
    }

    private function refresh(string $rawRefreshToken)
    {
        return $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $rawRefreshToken]);
    }

    // 1. Successful refresh.
    public function test_successful_refresh_returns_200_with_expected_payload(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $response = $this->refresh($session['refresh_token']);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'session_uuid' => $session['session_uuid'],
                ],
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'access_token',
                    'access_token_expires_at',
                    'refresh_token',
                    'session_uuid',
                    'session_expires_at',
                ],
            ]);
    }

    // 2. Malformed refresh token.
    public function test_malformed_refresh_token_is_rejected_with_validation_error(): void
    {
        $tooShort = $this->refresh(str_repeat('a', 63));
        $tooShort->assertStatus(422)->assertJsonValidationErrors(['refresh_token']);

        $nonHex = $this->refresh(str_repeat('z', 64));
        $nonHex->assertStatus(422)->assertJsonValidationErrors(['refresh_token']);

        $empty = $this->refresh('');
        $empty->assertStatus(422)->assertJsonValidationErrors(['refresh_token']);

        $missing = $this->postJson('/api/v1/auth/refresh', []);
        $missing->assertStatus(422)->assertJsonValidationErrors(['refresh_token']);
    }

    // 3. Unknown refresh token.
    public function test_unknown_refresh_token_is_rejected_with_generic_response(): void
    {
        $response = $this->refresh(bin2hex(random_bytes(32)));

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    // 4. Revoked session rejected.
    public function test_revoked_session_is_rejected_with_generic_response(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        DB::table('auth_sessions')
            ->where('id', UuidBinary::toBinary($session['session_uuid']))
            ->update(['revoked_at' => now()]);

        $response = $this->refresh($session['refresh_token']);

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    // 5. Expired session rejected.
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

        $response = $this->refresh($session['refresh_token']);

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    // 6. Inactive/non-ACTIVE user rejected.
    public function test_non_active_user_is_rejected_with_generic_response(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        DB::table('users')
            ->where('id', UuidBinary::toBinary($customer['user_uuid']))
            ->update(['account_status_id' => $this->accountStatusId('SUSPENDED')]);

        $response = $this->refresh($session['refresh_token']);

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    // 7. Unverified user rejected.
    public function test_unverified_user_is_rejected_with_generic_response(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        DB::table('users')
            ->where('id', UuidBinary::toBinary($customer['user_uuid']))
            ->update(['phone_verified_at' => null]);

        $response = $this->refresh($session['refresh_token']);

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    // 8. Missing/inactive CUSTOMER role rejected.
    public function test_missing_customer_role_is_rejected_with_generic_response(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        DB::table('user_roles')->where('user_id', UuidBinary::toBinary($customer['user_uuid']))->delete();

        $response = $this->refresh($session['refresh_token']);

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    public function test_inactive_customer_role_is_rejected_with_generic_response(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        DB::table('roles')->where('code', 'CUSTOMER')->update(['is_active' => 0]);

        $response = $this->refresh($session['refresh_token']);

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    // 9. Invalid/inactive customer client type rejected.
    public function test_inactive_client_type_is_rejected_with_generic_response(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer, 'MOBILE_ANDROID');

        DB::table('auth_client_types')->where('code', 'MOBILE_ANDROID')->update(['is_active' => 0]);

        $response = $this->refresh($session['refresh_token']);

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    public function test_non_mobile_client_type_is_rejected_with_generic_response(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        DB::table('auth_sessions')
            ->where('id', UuidBinary::toBinary($session['session_uuid']))
            ->update(['client_type_id' => $this->clientTypeId('ADMIN_WEB')]);

        $response = $this->refresh($session['refresh_token']);

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    // 10, 11, 12. New JWT is valid HS256, TTL = 15 minutes, sid unchanged.
    public function test_new_access_token_claims_and_expiry_are_correct(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer, 'MOBILE_ANDROID');

        $response = $this->refresh($session['refresh_token']);
        $response->assertStatus(200);

        $accessToken = $response->json('data.access_token');

        $decoded = JWT::decode($accessToken, new Key(config('jwt.secret'), 'HS256'));

        $this->assertSame($customer['user_uuid'], $decoded->sub);
        $this->assertSame($session['session_uuid'], $decoded->sid);
        $this->assertSame('CUSTOMER', $decoded->role);
        $this->assertSame('MOBILE_ANDROID', $decoded->client);
        $this->assertSame(15 * 60, $decoded->exp - $decoded->iat);
    }

    // 13, 14. New refresh token differs from old and its hash is stored.
    public function test_new_refresh_token_differs_from_old_and_matches_stored_hash(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $response = $this->refresh($session['refresh_token']);
        $response->assertStatus(200);

        $newRawRefreshToken = $response->json('data.refresh_token');

        $this->assertNotSame($session['refresh_token'], $newRawRefreshToken);

        $storedHash = DB::table('auth_sessions')
            ->where('id', UuidBinary::toBinary($session['session_uuid']))
            ->value('refresh_token_hash');

        $this->assertSame(hash('sha256', hex2bin($newRawRefreshToken), true), $storedHash);
    }

    // 15. Old refresh token no longer works after rotation.
    public function test_old_refresh_token_is_rejected_after_rotation(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $this->refresh($session['refresh_token'])->assertStatus(200);

        $secondResponse = $this->refresh($session['refresh_token']);

        $secondResponse->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_MESSAGE,
            'data' => null,
        ]);
    }

    // 16. Session expires_at is not extended.
    public function test_session_expires_at_is_not_extended_by_refresh(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $expiresAtBefore = $this->sessionRow($session['session_uuid'])->expires_at;

        $response = $this->refresh($session['refresh_token']);
        $response->assertStatus(200);

        $expiresAtAfter = $this->sessionRow($session['session_uuid'])->expires_at;

        $this->assertSame($expiresAtBefore, $expiresAtAfter);
        $this->assertTrue(
            Carbon::parse($expiresAtBefore)->eq(Carbon::parse($response->json('data.session_expires_at')))
        );
    }

    // 17. last_used_at updated.
    public function test_last_used_at_is_updated_on_successful_refresh(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $lastUsedAtBefore = $this->sessionRow($session['session_uuid'])->last_used_at;

        usleep(1_100_000);

        $this->refresh($session['refresh_token'])->assertStatus(200);

        $lastUsedAtAfter = $this->sessionRow($session['session_uuid'])->last_used_at;

        $this->assertNotSame($lastUsedAtBefore, $lastUsedAtAfter);
        $this->assertTrue(Carbon::parse($lastUsedAtAfter)->greaterThan(Carbon::parse($lastUsedAtBefore)));
    }

    // 18. No secrets/hashes leaked.
    public function test_response_never_leaks_secrets_or_hashes(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $response = $this->refresh($session['refresh_token']);
        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('password_hash', $data);
        $this->assertArrayNotHasKey('refresh_token_hash', $data);
        $this->assertArrayNotHasKey('jwt_secret', $data);
        $this->assertStringNotContainsString(config('jwt.secret'), (string) json_encode($data));
    }

    // 19. Rotation is atomic: a failed refresh leaves the stored hash untouched.
    public function test_failed_refresh_does_not_rotate_the_stored_hash(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        $hashBefore = $this->sessionRow($session['session_uuid'])->refresh_token_hash;

        DB::table('auth_sessions')
            ->where('id', UuidBinary::toBinary($session['session_uuid']))
            ->update(['revoked_at' => now()]);

        $this->refresh($session['refresh_token'])->assertStatus(422);

        $hashAfter = $this->sessionRow($session['session_uuid'])->refresh_token_hash;

        $this->assertSame($hashBefore, $hashAfter);
    }

    /**
     * 20. Concurrent double-refresh safety, where practical.
     *
     * True concurrent integration testing (two real overlapping MySQL
     * transactions racing to rotate the same auth_sessions row) is not
     * practical inside a single-threaded PHPUnit/Laravel feature test: a
     * second postJson() call on the same process/connection cannot run
     * interleaved with a first request's still-open transaction without a
     * second real connection and process-level concurrency, which this
     * project's test setup does not provide (see the equivalent note in
     * ResendOtpTest). Instead, this test deterministically verifies the
     * property that makes concurrent double-refresh safe: the
     * auth_sessions row is locked FOR UPDATE before it is read for
     * validation, so a second transaction racing for the same row must
     * wait for the first to commit its rotation before it can even see
     * the row - at which point the old token it is holding no longer
     * matches any stored hash. test_old_refresh_token_is_rejected_after_rotation
     * verifies that resulting outcome directly.
     */
    public function test_auth_sessions_row_is_locked_for_update_before_rotation(): void
    {
        $customer = $this->createCustomer();
        $session = $this->loginCustomer($customer);

        DB::enableQueryLog();

        $this->refresh($session['refresh_token'])->assertStatus(200);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $lockingQueries = collect($queries)
            ->filter(fn (array $entry) => str_contains(strtolower($entry['query']), 'for update'))
            ->values();

        $this->assertGreaterThanOrEqual(1, $lockingQueries->count());
        $this->assertStringContainsString('from `auth_sessions`', strtolower($lockingQueries->first()['query']));
    }
}
