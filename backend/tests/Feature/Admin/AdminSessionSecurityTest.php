<?php

namespace Tests\Feature\Admin;

use App\Models\AuthSession;
use App\Support\Admin\AdminSessionPolicy;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AuthenticatesAdminsForTests;
use Tests\Support\AuthenticatesCustomersForTests;
use Tests\TestCase;

/**
 * BLUE V1 Phase A2.4 - Admin Session Security.
 *
 * Covers the ADMIN_WEB-only absolute session lifetime (12h), idle timeout
 * (20min), and throttled activity-touch (~5min) enforced identically by
 * AuthenticateAdmin (Bearer requests) and AdminRefreshTokenAction (refresh)
 * via the shared App\Support\Admin\AdminSessionPolicy - and proves Customer/
 * mobile sessions are completely unaffected.
 *
 * Session/request timing is controlled via Carbon::setTestNow(), always
 * anchored to the REAL wall-clock instant captured at the start of each test
 * (`Carbon::now()`, taken before any setTestNow() call) rather than an
 * arbitrary fixed date. This matters for any assertion that exercises a
 * Bearer access token: Firebase\JWT\JWT validates the token's `exp` claim
 * against the REAL system clock, never Carbon's mocked time (the same
 * documented constraint AdminAuthorizationTest::test_expired_access_token_is_denied
 * already relies on) - an arbitrary past/future mocked base date would make
 * every issued token appear already expired (or not-yet-valid) by the real
 * clock, independent of the business-logic scenario under test. Anchoring
 * to real "now" keeps the JWT's own real-time exp comfortably valid for the
 * short duration of a test run, while still letting Carbon::setTestNow()
 * freely move the *business-logic* clock forward by hours for idle/absolute
 * -TTL scenarios - AuthenticateAdmin's/AdminRefreshTokenAction's own expiry
 * checks operate on Laravel's now() (which honors setTestNow), a completely
 * separate mechanism from the JWT library's internal exp check.
 *
 * Session creation timing never back-dates expires_at/last_used_at directly
 * with a value that would violate the auth_sessions CHECK constraints - so
 * created_at <= last_used_at always holds naturally, exactly as it would in
 * production.
 */
class AdminSessionSecurityTest extends TestCase
{
    use AuthenticatesAdminsForTests;
    use AuthenticatesCustomersForTests;
    use DatabaseTransactions;

    private static int $sequence = 0;

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    private function sessionPolicy(): AdminSessionPolicy
    {
        return app(AdminSessionPolicy::class);
    }

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
     */
    private function createUser(array $roleCodes, array $overrides = []): string
    {
        self::$sequence++;
        $userUuid = UuidBinary::generate();
        $now = now();

        DB::table('users')->insert(array_merge([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => '+97155'.str_pad((string) self::$sequence, 7, '0', STR_PAD_LEFT),
            'email' => 'admin.session.'.self::$sequence.'@example.com',
            'password_hash' => Hash::make('Passw0rd123'),
            'account_status_id' => $this->accountStatusId('ACTIVE'),
            'phone_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        DB::table('user_profiles')->insert([
            'user_id' => UuidBinary::toBinary($userUuid),
            'full_name' => 'Session Security Admin '.self::$sequence,
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

        return $userUuid;
    }

    /**
     * @return array<string, string>
     */
    private function bearer(string $accessToken): array
    {
        return ['Authorization' => 'Bearer '.$accessToken];
    }

    private function lastUsedAt(string $sessionUuid): ?Carbon
    {
        $raw = DB::table('auth_sessions')->where('id', UuidBinary::toBinary($sessionUuid))->value('last_used_at');

        return $raw === null ? null : Carbon::parse($raw);
    }

    private function revokedAt(string $sessionUuid): ?string
    {
        return DB::table('auth_sessions')->where('id', UuidBinary::toBinary($sessionUuid))->value('revoked_at');
    }

    // -----------------------------------------------------------------
    // ABSOLUTE TTL (1-4)
    // -----------------------------------------------------------------

    public function test_new_mfa_issued_admin_session_expires_in_twelve_hours_not_thirty_days(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);

        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);

        $expiresAt = DB::table('auth_sessions')->where('id', UuidBinary::toBinary($session['session_uuid']))->value('expires_at');

        $this->assertSame(
            $created->copy()->addHours(12)->format('Y-m-d H:i:s'),
            Carbon::parse($expiresAt)->format('Y-m-d H:i:s')
        );
    }

    public function test_customer_session_ttl_remains_unchanged_at_thirty_days(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);

        $customerUuid = $this->createUser(['CUSTOMER']);
        $session = $this->issueCustomerSession($customerUuid);

        $expiresAt = DB::table('auth_sessions')->where('id', UuidBinary::toBinary($session['session_uuid']))->value('expires_at');

        $this->assertSame($created->copy()->addDays(30)->format('Y-m-d H:i:s'), Carbon::parse($expiresAt)->format('Y-m-d H:i:s'));
    }

    public function test_admin_refresh_does_not_extend_absolute_expires_at(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);
        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);
        $originalExpiresAt = DB::table('auth_sessions')->where('id', UuidBinary::toBinary($session['session_uuid']))->value('expires_at');

        // Well within the 20-minute idle window, so the refresh itself
        // succeeds - the point of this test is proving expires_at is
        // unchanged by a successful refresh, not idle behavior.
        Carbon::setTestNow($created->copy()->addMinutes(10));
        $this->postJson('/api/v1/admin/auth/refresh', ['refresh_token' => $session['refresh_token']])->assertStatus(200);

        $afterRefreshExpiresAt = DB::table('auth_sessions')->where('id', UuidBinary::toBinary($session['session_uuid']))->value('expires_at');

        $this->assertSame($originalExpiresAt, $afterRefreshExpiresAt);
    }

    public function test_session_beyond_absolute_expiry_is_rejected(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);
        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);

        Carbon::setTestNow($created->copy()->addHours(12)->addSecond());
        $this->getJson('/api/v1/admin/me', $this->bearer($session['access_token']))->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // IDLE (5-12)
    // -----------------------------------------------------------------

    public function test_newly_created_admin_session_has_last_used_at(): void
    {
        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);

        $this->assertNotNull($this->lastUsedAt($session['session_uuid']));
    }

    public function test_active_admin_session_below_twenty_minutes_idle_succeeds(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);
        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);

        Carbon::setTestNow($created->copy()->addMinutes(20)->subSecond());
        $this->getJson('/api/v1/admin/me', $this->bearer($session['access_token']))->assertStatus(200);
    }

    public function test_session_at_exact_idle_boundary_is_rejected(): void
    {
        // Documented convention (see AdminSessionPolicy): the boundary
        // instant itself (last_used_at + idle timeout, exactly) is already
        // the first invalid instant - mirrors this codebase's existing
        // absolute-expiry convention (expires_at->lessThanOrEqualTo($now)).
        $created = Carbon::now();
        Carbon::setTestNow($created);
        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);

        Carbon::setTestNow($created->copy()->addMinutes(20));
        $this->getJson('/api/v1/admin/me', $this->bearer($session['access_token']))->assertStatus(401);
    }

    public function test_session_beyond_idle_timeout_is_rejected(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);
        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);

        Carbon::setTestNow($created->copy()->addMinutes(21));
        $response = $this->getJson('/api/v1/admin/me', $this->bearer($session['access_token']));

        $response->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => 'This session is invalid or has expired.',
        ]);
    }

    public function test_idle_expired_admin_refresh_is_rejected(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);
        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);

        Carbon::setTestNow($created->copy()->addMinutes(21));
        $response = $this->postJson('/api/v1/admin/auth/refresh', ['refresh_token' => $session['refresh_token']]);

        $response->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => 'This refresh token is invalid or has expired.',
            'data' => null,
        ]);
    }

    public function test_refresh_before_idle_expiry_succeeds(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);
        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);

        Carbon::setTestNow($created->copy()->addMinutes(19));
        $this->postJson('/api/v1/admin/auth/refresh', ['refresh_token' => $session['refresh_token']])->assertStatus(200);
    }

    public function test_successful_admin_refresh_does_not_update_last_used_at(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);
        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);

        Carbon::setTestNow($created->copy()->addMinutes(10));
        $this->postJson('/api/v1/admin/auth/refresh', ['refresh_token' => $session['refresh_token']])->assertStatus(200);

        $this->assertSame(
            $created->format('Y-m-d H:i:s'),
            $this->lastUsedAt($session['session_uuid'])->format('Y-m-d H:i:s'),
            'A silent refresh must never update last_used_at.'
        );
    }

    public function test_refresh_cannot_resurrect_an_idle_expired_session(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);
        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);

        // First, let it idle-expire.
        Carbon::setTestNow($created->copy()->addMinutes(25));
        $this->postJson('/api/v1/admin/auth/refresh', ['refresh_token' => $session['refresh_token']])->assertStatus(422);
        $this->assertNotNull($this->revokedAt($session['session_uuid']));

        // A later refresh attempt with the same (still cryptographically
        // valid, never rotated) raw token must also fail - the session is
        // permanently dead, not merely "currently idle".
        Carbon::setTestNow($created->copy()->addMinutes(26));
        $this->postJson('/api/v1/admin/auth/refresh', ['refresh_token' => $session['refresh_token']])->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // ACTIVITY TOUCH (13-17)
    // -----------------------------------------------------------------

    public function test_authenticated_admin_request_updates_stale_last_used_at(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);
        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);

        $requestTime = $created->copy()->addMinutes(10);
        Carbon::setTestNow($requestTime);
        $this->getJson('/api/v1/admin/me', $this->bearer($session['access_token']))->assertStatus(200);

        $this->assertSame($requestTime->format('Y-m-d H:i:s'), $this->lastUsedAt($session['session_uuid'])->format('Y-m-d H:i:s'));
    }

    public function test_request_within_touch_window_does_not_update_last_used_at(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);
        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);

        Carbon::setTestNow($created->copy()->addMinutes(3));
        $this->getJson('/api/v1/admin/me', $this->bearer($session['access_token']))->assertStatus(200);

        $this->assertSame(
            $created->format('Y-m-d H:i:s'),
            $this->lastUsedAt($session['session_uuid'])->format('Y-m-d H:i:s'),
            'A request inside the 5-minute touch window must not rewrite last_used_at.'
        );
    }

    public function test_request_after_touch_interval_updates_last_used_at(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);
        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);

        $requestTime = $created->copy()->addMinutes(5);
        Carbon::setTestNow($requestTime);
        $this->getJson('/api/v1/admin/me', $this->bearer($session['access_token']))->assertStatus(200);

        $this->assertSame($requestTime->format('Y-m-d H:i:s'), $this->lastUsedAt($session['session_uuid'])->format('Y-m-d H:i:s'));
    }

    public function test_touch_if_due_can_never_move_last_used_at_backward(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);
        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);

        $sessionModel = AuthSession::where('id', UuidBinary::toBinary($session['session_uuid']))->firstOrFail();

        // A later touch first...
        $later = $created->copy()->addMinutes(10);
        $this->sessionPolicy()->touchIfDue($sessionModel, $later);
        $this->assertSame($later->format('Y-m-d H:i:s'), $this->lastUsedAt($session['session_uuid'])->format('Y-m-d H:i:s'));

        // ...then a stale/out-of-order touch attempt with an EARLIER instant
        // (simulating a racing request that started before $later but is
        // only now reaching this code) must never regress the stored value.
        $earlier = $created->copy()->addMinutes(1);
        $this->sessionPolicy()->touchIfDue($sessionModel, $earlier);

        $this->assertSame(
            $later->format('Y-m-d H:i:s'),
            $this->lastUsedAt($session['session_uuid'])->format('Y-m-d H:i:s'),
            'last_used_at must never move backward.'
        );
    }

    public function test_rejected_request_does_not_count_as_activity(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);
        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);

        // Push well past idle expiry - the request will be rejected.
        Carbon::setTestNow($created->copy()->addMinutes(30));
        $this->getJson('/api/v1/admin/me', $this->bearer($session['access_token']))->assertStatus(401);

        // last_used_at must remain exactly what it was at creation - the
        // rejected request never touched it (only revoked_at changes).
        $this->assertSame($created->format('Y-m-d H:i:s'), $this->lastUsedAt($session['session_uuid'])->format('Y-m-d H:i:s'));
    }

    // -----------------------------------------------------------------
    // SECURITY (18-22)
    // -----------------------------------------------------------------

    public function test_idle_expiry_revokes_the_session_server_side(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);
        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);

        $this->assertNull($this->revokedAt($session['session_uuid']));

        Carbon::setTestNow($created->copy()->addMinutes(25));
        $this->getJson('/api/v1/admin/me', $this->bearer($session['access_token']))->assertStatus(401);

        $this->assertNotNull($this->revokedAt($session['session_uuid']));
    }

    public function test_revoked_session_remains_rejected(): void
    {
        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);

        $this->postJson('/api/v1/auth/logout', [], $this->bearer($session['access_token']))->assertStatus(200);

        $this->getJson('/api/v1/admin/me', $this->bearer($session['access_token']))->assertStatus(401);
    }

    public function test_deactivated_admin_remains_rejected_within_idle_window(): void
    {
        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);

        DB::table('users')->where('id', UuidBinary::toBinary($userUuid))->update([
            'account_status_id' => $this->accountStatusId('DEACTIVATED'),
        ]);

        $this->getJson('/api/v1/admin/me', $this->bearer($session['access_token']))->assertStatus(401);
    }

    public function test_removed_admin_role_remains_rejected_within_idle_window(): void
    {
        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);

        DB::table('user_roles')
            ->where('user_id', UuidBinary::toBinary($userUuid))
            ->where('role_id', $this->roleId('ADMIN'))
            ->delete();

        $this->getJson('/api/v1/admin/me', $this->bearer($session['access_token']))->assertStatus(401);
    }

    public function test_wrong_client_type_remains_rejected(): void
    {
        $customerUuid = $this->createUser(['CUSTOMER', 'ADMIN']);
        $session = $this->issueCustomerSession($customerUuid, 'MOBILE_IOS');

        $this->getJson('/api/v1/admin/me', $this->bearer($session['access_token']))->assertStatus(401);
    }

    public function test_customer_session_is_immune_to_admin_idle_timeout(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);
        $customerUuid = $this->createUser(['CUSTOMER']);
        $session = $this->issueCustomerSession($customerUuid);

        // Push well past what would be an Admin idle timeout (20 min) -
        // Customer sessions must never consult AdminSessionPolicy at all.
        Carbon::setTestNow($created->copy()->addMinutes(25));
        $this->getJson('/api/v1/bookings', $this->bearer($session['access_token']))->assertStatus(200);
    }

    // -----------------------------------------------------------------
    // CUSTOMER REGRESSION (23-25)
    // -----------------------------------------------------------------

    public function test_customer_refresh_still_updates_last_used_at(): void
    {
        $created = Carbon::now();
        Carbon::setTestNow($created);
        $customerUuid = $this->createUser(['CUSTOMER']);
        $session = $this->issueCustomerSession($customerUuid);

        $requestTime = $created->copy()->addMinutes(10);
        Carbon::setTestNow($requestTime);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $session['refresh_token']])->assertStatus(200);

        $this->assertSame(
            $requestTime->format('Y-m-d H:i:s'),
            $this->lastUsedAt($session['session_uuid'])->format('Y-m-d H:i:s'),
            'Customer refresh must keep updating last_used_at exactly as before Phase A2.4.'
        );
    }

    public function test_customer_session_lifetime_remains_default_thirty_days(): void
    {
        $this->assertSame(30, (int) config('jwt.session_ttl_days'));
    }

    public function test_customer_authentication_middleware_is_unaffected(): void
    {
        $customerUuid = $this->createUser(['CUSTOMER']);
        $session = $this->issueCustomerSession($customerUuid);

        $this->getJson('/api/v1/bookings', $this->bearer($session['access_token']))->assertStatus(200);
    }

    // -----------------------------------------------------------------
    // MFA REGRESSION (26-29)
    // -----------------------------------------------------------------

    public function test_stage_one_still_creates_no_session(): void
    {
        $userUuid = $this->createUser(['ADMIN']);
        $phoneNumber = DB::table('users')->where('id', UuidBinary::toBinary($userUuid))->value('phone_number');

        $this->postJson('/api/v1/admin/auth/login', [
            'phone_number' => $phoneNumber,
            'password' => 'Passw0rd123',
        ])->assertStatus(200);

        $this->assertSame(0, DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($userUuid))->count());
    }

    public function test_mfa_issued_session_still_exactly_one_session(): void
    {
        $userUuid = $this->createUser(['ADMIN']);
        $this->issueAdminSession($userUuid, ['ADMIN']);

        $this->assertSame(1, DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($userUuid))->count());
    }

    public function test_refreshed_admin_token_still_works_before_idle_timeout(): void
    {
        // Deliberately does NOT mock time forward before refreshing: a
        // token minted by JwtTokenService::issueAccessToken() while the
        // business-logic clock is mocked ahead of the real wall-clock bakes
        // in a `nbf`/`iat` that is genuinely in the future by real-clock
        // standards - Firebase\JWT\JWT validates those against the REAL
        // system clock (see class docblock) and would reject such a token
        // forever, regardless of what Carbon::setTestNow() is set to at
        // verification time (the claim is already baked into the signed
        // token). "Before idle timeout" (the 20-minute window) for the
        // refresh call itself is already proven by
        // test_refresh_before_idle_expiry_succeeds above; this test's own
        // job is only to prove the token refresh *hands back* is genuinely
        // usable for a subsequent authenticated request.
        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);

        $refreshed = $this->postJson('/api/v1/admin/auth/refresh', ['refresh_token' => $session['refresh_token']])->assertStatus(200);

        $this->getJson('/api/v1/admin/me', $this->bearer($refreshed->json('data.access_token')))->assertStatus(200);
    }

    public function test_admin_capability_middleware_still_works_before_idle_timeout(): void
    {
        $userUuid = $this->createUser(['ADMIN']);
        $session = $this->issueAdminSession($userUuid, ['ADMIN']);

        $this->getJson('/api/v1/admin/technicians', $this->bearer($session['access_token']))->assertStatus(200);
    }
}
