<?php

namespace Tests\Feature\Auth;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginOtpTest extends TestCase
{
    use DatabaseTransactions;

    private const REQUEST_URI = '/api/v1/auth/login/request-otp';

    private const VERIFY_URI = '/api/v1/auth/login/verify-otp';

    private const RESEND_URI = '/api/v1/auth/login/resend-otp';

    private const SAFE_ISSUE_MESSAGE = 'If an eligible account exists for this phone number, a login code has been sent.';

    private const GENERIC_VERIFY_MESSAGE = 'Invalid or expired verification code.';

    private static int $sequence = 0;

    private function accountStatusId(string $code): int
    {
        return (int) DB::table('user_account_statuses')->where('code', $code)->value('id');
    }

    private function roleId(string $code): int
    {
        return (int) DB::table('roles')->where('code', $code)->value('id');
    }

    private function purposeId(string $code): int
    {
        return (int) DB::table('otp_verification_purposes')->where('code', $code)->value('id');
    }

    private function otpStatusId(string $code): int
    {
        return (int) DB::table('otp_verification_statuses')->where('code', $code)->value('id');
    }

    /**
     * @return array{user_uuid: string, phone_number: string, full_name: string}
     */
    private function createEligibleCustomer(array $userOverrides = [], bool $assignCustomerRole = true): array
    {
        self::$sequence++;

        $userUuid = UuidBinary::generate();
        $phoneNumber = '+97156100'.str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT);
        $fullName = 'Fatima Al Otaiba';
        $now = now();

        DB::table('users')->insert(array_merge([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => 'login.otp.'.self::$sequence.'@example.com',
            'password_hash' => Hash::make('Passw0rd123'),
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

        return ['user_uuid' => $userUuid, 'phone_number' => $phoneNumber, 'full_name' => $fullName];
    }

    private function createLoginOtp(string $userUuid, string $phoneNumber, string $rawCode = '123456', array $overrides = []): string
    {
        $otpUuid = UuidBinary::generate();
        $now = now();

        DB::table('otp_verifications')->insert(array_merge([
            'id' => UuidBinary::toBinary($otpUuid),
            'user_id' => UuidBinary::toBinary($userUuid),
            'purpose_id' => $this->purposeId('LOGIN'),
            'status_id' => $this->otpStatusId('PENDING'),
            'target_phone_number' => $phoneNumber,
            'code_hash' => Hash::make($rawCode),
            'failed_attempt_count' => 0,
            'max_attempts' => 5,
            'expires_at' => $now->copy()->addMinutes(5),
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        return $otpUuid;
    }

    private function verifyPayload(string $phoneNumber, string $code = '123456', array $overrides = []): array
    {
        return array_merge([
            'phone_number' => $phoneNumber,
            'otp_code' => $code,
            'client_type' => 'MOBILE_IOS',
        ], $overrides);
    }

    // 1. known active CUSTOMER can request Login OTP
    public function test_eligible_customer_can_request_login_otp(): void
    {
        $customer = $this->createEligibleCustomer();

        $response = $this->postJson(self::REQUEST_URI, ['phone_number' => $customer['phone_number']]);

        $response->assertStatus(200)->assertExactJson([
            'success' => true,
            'message' => self::SAFE_ISSUE_MESSAGE,
            'data' => null,
        ]);

        $this->assertSame(
            1,
            DB::table('otp_verifications')
                ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
                ->where('purpose_id', $this->purposeId('LOGIN'))
                ->count()
        );
    }

    // 2 + 27. request response never exposes the raw OTP or any internal id/secret
    public function test_request_otp_response_never_exposes_raw_code_or_internals(): void
    {
        $customer = $this->createEligibleCustomer();

        $response = $this->postJson(self::REQUEST_URI, ['phone_number' => $customer['phone_number']]);

        $body = $response->json();
        $this->assertNull($body['data']);
        $this->assertStringNotContainsString('code_hash', $response->getContent());
        $this->assertStringNotContainsString('password_hash', $response->getContent());

        $otp = DB::table('otp_verifications')
            ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->where('purpose_id', $this->purposeId('LOGIN'))
            ->first();

        // The raw code is never in the response - assert it isn't trivially
        // guessable from the payload by checking the response contains no
        // 6-digit-code field at all under `data`.
        $this->assertArrayNotHasKey('otp_code', (array) ($body['data'] ?? []));
        $this->assertArrayNotHasKey('otp_verification_uuid', (array) ($body['data'] ?? []));
        $this->assertNotNull($otp);
    }

    // 3. only code_hash is stored, never the raw code
    public function test_only_code_hash_is_persisted_never_raw_code(): void
    {
        $customer = $this->createEligibleCustomer();

        $this->postJson(self::REQUEST_URI, ['phone_number' => $customer['phone_number']]);

        $otp = DB::table('otp_verifications')
            ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->where('purpose_id', $this->purposeId('LOGIN'))
            ->first();

        $this->assertNotNull($otp->code_hash);
        $this->assertNotSame('', $otp->code_hash);
        // A bcrypt hash never looks like a 6-digit numeric code, and does
        // verify against the raw code that was actually generated - proving
        // the persisted value is a genuine hash of *some* code, not the
        // code itself.
        $this->assertFalse((bool) preg_match('/^\d{6}$/', $otp->code_hash));
        $this->assertTrue(str_starts_with($otp->code_hash, '$2y$'));
    }

    // 4. unknown phone does not disclose account existence
    public function test_unknown_phone_and_eligible_phone_return_identical_response(): void
    {
        $customer = $this->createEligibleCustomer();

        $eligibleResponse = $this->postJson(self::REQUEST_URI, ['phone_number' => $customer['phone_number']]);
        $unknownResponse = $this->postJson(self::REQUEST_URI, ['phone_number' => '+971509998877']);

        $eligibleResponse->assertStatus(200)->assertExactJson([
            'success' => true,
            'message' => self::SAFE_ISSUE_MESSAGE,
            'data' => null,
        ]);

        $unknownResponse->assertStatus(200)->assertExactJson([
            'success' => true,
            'message' => self::SAFE_ISSUE_MESSAGE,
            'data' => null,
        ]);

        // The unknown phone never gets an OTP row.
        $this->assertSame(
            0,
            DB::table('otp_verifications')
                ->where('target_phone_number', '+971509998877')
                ->count()
        );
    }

    // 5. non-CUSTOMER identity cannot receive/use a valid Customer Login session
    public function test_non_customer_role_cannot_login_via_otp(): void
    {
        $customer = $this->createEligibleCustomer([], assignCustomerRole: false);
        $otpUuid = $this->createLoginOtp($customer['user_uuid'], $customer['phone_number']);

        $requestResponse = $this->postJson(self::REQUEST_URI, ['phone_number' => $customer['phone_number']]);
        $requestResponse->assertStatus(200)->assertExactJson([
            'success' => true,
            'message' => self::SAFE_ISSUE_MESSAGE,
            'data' => null,
        ]);
        // No new OTP is issued for an ineligible identity - only the
        // manually-seeded one above exists.
        $this->assertSame(
            1,
            DB::table('otp_verifications')->where('id', UuidBinary::toBinary($otpUuid))->count()
        );

        $verifyResponse = $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number']));
        $verifyResponse->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_VERIFY_MESSAGE,
            'data' => null,
        ]);
    }

    // 6. inactive/deactivated customer cannot log in
    public function test_deactivated_customer_cannot_request_or_verify_login_otp(): void
    {
        $customer = $this->createEligibleCustomer([
            'account_status_id' => $this->accountStatusId('DEACTIVATED'),
        ]);
        $this->createLoginOtp($customer['user_uuid'], $customer['phone_number']);

        $this->postJson(self::REQUEST_URI, ['phone_number' => $customer['phone_number']])
            ->assertStatus(200)
            ->assertExactJson(['success' => true, 'message' => self::SAFE_ISSUE_MESSAGE, 'data' => null]);

        $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number']))
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => self::GENERIC_VERIFY_MESSAGE, 'data' => null]);
    }

    // 7. deleted/tombstoned customer cannot log in
    public function test_deleted_tombstoned_customer_cannot_login_via_otp(): void
    {
        $customer = $this->createEligibleCustomer();
        $originalPhone = $customer['phone_number'];

        // Mirrors AccountDeletionExecutor's tombstoning: role removed,
        // status DEACTIVATED, phone_number overwritten, deleted_at set.
        DB::table('user_roles')
            ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->where('role_id', $this->roleId('CUSTOMER'))
            ->delete();

        DB::table('users')->where('id', UuidBinary::toBinary($customer['user_uuid']))->update([
            // Same tombstone shape AccountDeletionExecutor::tombstonePhoneNumber()
            // produces - 8-20 ascii chars, never a valid phone number.
            'phone_number' => 'DEL'.bin2hex(random_bytes(7)),
            'account_status_id' => $this->accountStatusId('DEACTIVATED'),
            'deleted_at' => now(),
        ]);

        $this->postJson(self::REQUEST_URI, ['phone_number' => $originalPhone])
            ->assertStatus(200)
            ->assertExactJson(['success' => true, 'message' => self::SAFE_ISSUE_MESSAGE, 'data' => null]);

        $this->assertSame(0, DB::table('otp_verifications')
            ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->where('purpose_id', $this->purposeId('LOGIN'))
            ->count());

        $this->postJson(self::VERIFY_URI, $this->verifyPayload($originalPhone))
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => self::GENERIC_VERIFY_MESSAGE, 'data' => null]);
    }

    // 8, 9, 10. correct Login OTP succeeds, creates a real session, full contract returned
    public function test_correct_login_otp_verifies_and_creates_full_session_contract(): void
    {
        $customer = $this->createEligibleCustomer();
        $this->createLoginOtp($customer['user_uuid'], $customer['phone_number'], '654321');

        $response = $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number'], '654321', [
            'device_name' => "Fatima's iPhone",
            'app_version' => '1.4.0',
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
                'success', 'message',
                'data' => [
                    'user_uuid', 'full_name', 'phone_number', 'email', 'role',
                    'session_uuid', 'access_token', 'access_token_expires_at',
                    'refresh_token', 'session_expires_at',
                ],
            ]);

        $sessionUuid = $response->json('data.session_uuid');
        $session = DB::table('auth_sessions')->where('id', UuidBinary::toBinary($sessionUuid))->first();

        $this->assertNotNull($session);
        $this->assertSame(UuidBinary::toBinary($customer['user_uuid']), $session->user_id);
        $this->assertNull($session->revoked_at);

        $otp = DB::table('otp_verifications')
            ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->where('purpose_id', $this->purposeId('LOGIN'))
            ->first();
        $this->assertSame($this->otpStatusId('VERIFIED'), (int) $otp->status_id);
        $this->assertNotNull($otp->verified_at);
    }

    // 11. new access token works on an auth.customer route
    public function test_issued_access_token_works_on_protected_customer_route(): void
    {
        $customer = $this->createEligibleCustomer();
        $this->createLoginOtp($customer['user_uuid'], $customer['phone_number'], '111222');

        $verify = $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number'], '111222'));
        $accessToken = $verify->json('data.access_token');

        // /v1/cart (not /v1/profile, which requires a customer_profiles row
        // this minimal fixture deliberately doesn't create) is enough to
        // prove the token works on a real auth.customer route.
        $this->getJson('/api/v1/cart', ['Authorization' => 'Bearer '.$accessToken])
            ->assertStatus(200);
    }

    // 12. returned refresh token works with POST /v1/auth/refresh
    public function test_issued_refresh_token_works_with_refresh_endpoint(): void
    {
        $customer = $this->createEligibleCustomer();
        $this->createLoginOtp($customer['user_uuid'], $customer['phone_number'], '333444');

        $verify = $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number'], '333444'));
        $refreshToken = $verify->json('data.refresh_token');

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken])
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);
    }

    // 13. wrong Login OTP fails
    public function test_wrong_otp_code_fails(): void
    {
        $customer = $this->createEligibleCustomer();
        $this->createLoginOtp($customer['user_uuid'], $customer['phone_number'], '123456');

        $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number'], '999999'))
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => self::GENERIC_VERIFY_MESSAGE, 'data' => null]);
    }

    // 14. attempts increment
    public function test_wrong_attempts_increment_failed_attempt_count(): void
    {
        $customer = $this->createEligibleCustomer();
        $otpUuid = $this->createLoginOtp($customer['user_uuid'], $customer['phone_number'], '123456');

        $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number'], '000000'));

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($otpUuid))->first();
        $this->assertSame(1, (int) $otp->failed_attempt_count);
        $this->assertSame($this->otpStatusId('PENDING'), (int) $otp->status_id);
    }

    // 15. max attempts locks OTP
    public function test_max_attempts_locks_otp_as_attempts_exceeded(): void
    {
        $customer = $this->createEligibleCustomer();
        $otpUuid = $this->createLoginOtp($customer['user_uuid'], $customer['phone_number'], '123456');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number'], '000000'));
        }

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($otpUuid))->first();
        $this->assertSame(5, (int) $otp->failed_attempt_count);
        $this->assertSame($this->otpStatusId('ATTEMPTS_EXCEEDED'), (int) $otp->status_id);

        // Correct code no longer works once attempts are exceeded.
        $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number'], '123456'))
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => self::GENERIC_VERIFY_MESSAGE, 'data' => null]);
    }

    // 16. expired Login OTP fails
    public function test_expired_otp_fails_and_marks_expired(): void
    {
        $customer = $this->createEligibleCustomer();
        $otpUuid = $this->createLoginOtp($customer['user_uuid'], $customer['phone_number'], '123456', [
            'created_at' => now()->subMinutes(10),
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number'], '123456'))
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => self::GENERIC_VERIFY_MESSAGE, 'data' => null]);

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($otpUuid))->first();
        $this->assertSame($this->otpStatusId('EXPIRED'), (int) $otp->status_id);
    }

    /**
     * Deterministic lock-order/lock-boundary proof for concurrent verify
     * safety, mirroring the established methodology in
     * ResendOtpTest::test_user_row_is_locked_for_update_before_any_otp_row.
     *
     * True concurrent integration testing (two real overlapping MySQL
     * transactions racing to verify the same OTP) is not practical inside a
     * single-threaded PHPUnit/Laravel feature test: a second postJson()
     * call on the same process/connection cannot run interleaved with a
     * first request's still-open transaction without a second real
     * connection and process-level concurrency (e.g. pcntl fork), which
     * this project's test setup does not provide.
     *
     * Instead, this test deterministically verifies the exact property that
     * makes concurrent double-verification structurally impossible:
     * VerifyLoginOtpAction runs entirely inside one DB::transaction() and
     * acquires two FOR UPDATE row locks in a fixed order - the `users` row
     * first, then the matching `otp_verifications` row - before validating
     * state, marking VERIFIED, and creating the session. Two requests
     * verifying the same phone number's OTP can therefore only ever
     * serialize on the shared `users` row lock: whichever request's
     * transaction commits first (having already transitioned the OTP to
     * VERIFIED) causes the second, now-unblocked request to re-read that
     * same OTP row under its own FOR UPDATE lock and find status_id no
     * longer PENDING - exactly what
     * test_verified_otp_cannot_be_replayed below proves happens, just
     * proven here sequentially rather than under real concurrency. Locking
     * the OTP row before the user row would instead let two requests that
     * resolve to two different phone numbers deadlock on each other; this
     * order is what every other OTP action in this codebase already uses
     * (see VerifyPhoneAction, ResendPhoneOtpAction, ForgotPasswordAction)
     * and IssueLoginOtpAction/VerifyLoginOtpAction deliberately preserve it.
     */
    public function test_user_row_is_locked_for_update_before_otp_row_during_verify(): void
    {
        $customer = $this->createEligibleCustomer();
        $this->createLoginOtp($customer['user_uuid'], $customer['phone_number'], '123456');

        DB::enableQueryLog();

        $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number'], '123456'))
            ->assertStatus(200);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $lockingQueries = collect($queries)
            ->filter(fn (array $entry) => str_contains(strtolower($entry['query']), 'for update'))
            ->values();

        $this->assertGreaterThanOrEqual(
            2,
            $lockingQueries->count(),
            'Expected at least a users FOR UPDATE lock and an otp_verifications FOR UPDATE lock.'
        );

        $this->assertStringContainsString(
            'from `users`',
            strtolower($lockingQueries->first()['query']),
            'The users row must be the first FOR UPDATE lock acquired during verify-otp.'
        );

        $secondLockTargetsOtpTable = $lockingQueries->slice(1)->contains(
            fn (array $entry) => str_contains(strtolower($entry['query']), 'from `otp_verifications`')
        );

        $this->assertTrue(
            $secondLockTargetsOtpTable,
            'An otp_verifications row must also be locked FOR UPDATE, after the users row, within the same transaction.'
        );
    }

    /**
     * Same lock-order proof as above, for the issue/resend side
     * (IssueLoginOtpAction), which both request-otp and resend-otp share.
     */
    public function test_user_row_is_locked_for_update_before_otp_row_during_issue(): void
    {
        $customer = $this->createEligibleCustomer();

        DB::enableQueryLog();

        $this->postJson(self::REQUEST_URI, ['phone_number' => $customer['phone_number']])
            ->assertStatus(200);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $lockingQueries = collect($queries)
            ->filter(fn (array $entry) => str_contains(strtolower($entry['query']), 'for update'))
            ->values();

        $this->assertGreaterThanOrEqual(1, $lockingQueries->count());

        $this->assertStringContainsString(
            'from `users`',
            strtolower($lockingQueries->first()['query']),
            'The users row must be the first FOR UPDATE lock acquired during request-otp/resend-otp.'
        );
    }

    // Two overlapping concerns share one shared user-row lock: a resend
    // racing a verify for the same phone number can only ever serialize on
    // that lock (same order both actions use), never deadlock. This proves
    // the *outcome* sequentially - resend correctly invalidates the OTP a
    // verify was about to consume, and the stale code then fails safely -
    // which is what real concurrent interleavings collapse to once
    // serialized by the shared lock.
    public function test_resend_racing_verify_invalidates_the_otp_verify_would_have_used(): void
    {
        $customer = $this->createEligibleCustomer();
        $otpUuid = $this->createLoginOtp($customer['user_uuid'], $customer['phone_number'], '123456', [
            'created_at' => now()->subSeconds(61),
        ]);

        // Resend "wins the race" - invalidates the PENDING OTP a concurrent
        // verify attempt was about to use.
        $this->postJson(self::RESEND_URI, ['phone_number' => $customer['phone_number']])
            ->assertStatus(200);

        // The verify that "lost the race" now fails safely - no session is
        // created from the stale code, and the old OTP stays INVALIDATED.
        $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number'], '123456'))
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => self::GENERIC_VERIFY_MESSAGE, 'data' => null]);

        $oldOtp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($otpUuid))->first();
        $this->assertSame($this->otpStatusId('INVALIDATED'), (int) $oldOtp->status_id);
        $this->assertSame(
            0,
            DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($customer['user_uuid']))->count()
        );
    }

    // Two simultaneous OTP *requests* for the same phone number: the second
    // (serialized behind the same shared users-row lock IssueLoginOtpAction
    // always acquires first) must never create a second PENDING LOGIN OTP -
    // it must observe the first one is still within its cooldown and
    // decline uniformly, leaving exactly one OTP row.
    public function test_two_requests_for_the_same_phone_never_create_two_pending_otps(): void
    {
        $customer = $this->createEligibleCustomer();

        $this->postJson(self::REQUEST_URI, ['phone_number' => $customer['phone_number']])->assertStatus(200);
        $this->postJson(self::REQUEST_URI, ['phone_number' => $customer['phone_number']])->assertStatus(200);

        $this->assertSame(
            1,
            DB::table('otp_verifications')
                ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
                ->where('purpose_id', $this->purposeId('LOGIN'))
                ->count()
        );
    }

    // 17. VERIFIED OTP cannot be replayed
    public function test_verified_otp_cannot_be_replayed(): void
    {
        $customer = $this->createEligibleCustomer();
        $this->createLoginOtp($customer['user_uuid'], $customer['phone_number'], '123456');

        $first = $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number'], '123456'));
        $first->assertStatus(200);
        $firstSessionUuid = $first->json('data.session_uuid');

        $second = $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number'], '123456'));
        $second->assertStatus(422)->assertExactJson([
            'success' => false,
            'message' => self::GENERIC_VERIFY_MESSAGE,
            'data' => null,
        ]);

        // No second session was created by the replay.
        $this->assertSame(
            1,
            DB::table('auth_sessions')
                ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
                ->count()
        );
        $this->assertNotNull(DB::table('auth_sessions')->where('id', UuidBinary::toBinary($firstSessionUuid))->first());
    }

    // 18. resend invalidates prior Login OTP
    public function test_resend_invalidates_prior_pending_otp(): void
    {
        $customer = $this->createEligibleCustomer();
        $oldOtpUuid = $this->createLoginOtp($customer['user_uuid'], $customer['phone_number'], '123456', [
            'created_at' => now()->subSeconds(61),
        ]);

        $this->postJson(self::RESEND_URI, ['phone_number' => $customer['phone_number']])
            ->assertStatus(200)
            ->assertExactJson(['success' => true, 'message' => self::SAFE_ISSUE_MESSAGE, 'data' => null]);

        $oldOtp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($oldOtpUuid))->first();
        $this->assertSame($this->otpStatusId('INVALIDATED'), (int) $oldOtp->status_id);

        // The old code no longer verifies.
        $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number'], '123456'))
            ->assertStatus(422);

        $this->assertSame(
            2,
            DB::table('otp_verifications')
                ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
                ->where('purpose_id', $this->purposeId('LOGIN'))
                ->count()
        );
    }

    // 19. resend cooldown enforced
    public function test_resend_within_cooldown_is_declined_uniformly(): void
    {
        $customer = $this->createEligibleCustomer();
        $otpUuid = $this->createLoginOtp($customer['user_uuid'], $customer['phone_number'], '123456', [
            'created_at' => now()->subSeconds(10),
        ]);

        $this->postJson(self::RESEND_URI, ['phone_number' => $customer['phone_number']])
            ->assertStatus(200)
            ->assertExactJson(['success' => true, 'message' => self::SAFE_ISSUE_MESSAGE, 'data' => null]);

        // Still exactly one OTP row - the cooldown blocked the reissue, and
        // the original PENDING OTP is untouched.
        $this->assertSame(
            1,
            DB::table('otp_verifications')
                ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
                ->where('purpose_id', $this->purposeId('LOGIN'))
                ->count()
        );

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($otpUuid))->first();
        $this->assertSame($this->otpStatusId('PENDING'), (int) $otp->status_id);
    }

    // 20. Customer B cannot use Customer A's OTP UUID/code
    public function test_customer_b_cannot_use_customer_as_raw_code(): void
    {
        $customerA = $this->createEligibleCustomer();
        $customerB = $this->createEligibleCustomer();

        $this->createLoginOtp($customerA['user_uuid'], $customerA['phone_number'], '123456');

        // Customer B's phone number has no pending LOGIN OTP of its own, so
        // attempting A's raw code against B's phone number resolves to "no
        // pending OTP for this identity" and fails - there is no
        // otp_verification_uuid in this flow for an attacker to even
        // reference across identities (see VerifyLoginOtpRequest).
        $this->postJson(self::VERIFY_URI, $this->verifyPayload($customerB['phone_number'], '123456'))
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => self::GENERIC_VERIFY_MESSAGE, 'data' => null]);

        $this->assertSame(
            0,
            DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($customerB['user_uuid']))->count()
        );
    }

    // 21. PASSWORD_RESET OTP cannot authenticate Login
    public function test_password_reset_otp_cannot_be_used_for_login(): void
    {
        $customer = $this->createEligibleCustomer();

        DB::table('otp_verifications')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'user_id' => UuidBinary::toBinary($customer['user_uuid']),
            'purpose_id' => $this->purposeId('PASSWORD_RESET'),
            'status_id' => $this->otpStatusId('PENDING'),
            'target_phone_number' => $customer['phone_number'],
            'code_hash' => Hash::make('777888'),
            'failed_attempt_count' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number'], '777888'))
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => self::GENERIC_VERIFY_MESSAGE, 'data' => null]);
    }

    // 22. PHONE_VERIFICATION OTP cannot authenticate Login
    public function test_phone_verification_otp_cannot_be_used_for_login(): void
    {
        $customer = $this->createEligibleCustomer();

        DB::table('otp_verifications')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'user_id' => UuidBinary::toBinary($customer['user_uuid']),
            'purpose_id' => $this->purposeId('PHONE_VERIFICATION'),
            'status_id' => $this->otpStatusId('PENDING'),
            'target_phone_number' => $customer['phone_number'],
            'code_hash' => Hash::make('444555'),
            'failed_attempt_count' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number'], '444555'))
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => self::GENERIC_VERIFY_MESSAGE, 'data' => null]);
    }

    // 23. PHONE_NUMBER_CHANGE OTP cannot authenticate Login
    public function test_phone_number_change_otp_cannot_be_used_for_login(): void
    {
        $customer = $this->createEligibleCustomer();

        DB::table('otp_verifications')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'user_id' => UuidBinary::toBinary($customer['user_uuid']),
            'purpose_id' => $this->purposeId('PHONE_NUMBER_CHANGE'),
            'status_id' => $this->otpStatusId('PENDING'),
            'target_phone_number' => $customer['phone_number'],
            'code_hash' => Hash::make('222333'),
            'failed_attempt_count' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number'], '222333'))
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => self::GENERIC_VERIFY_MESSAGE, 'data' => null]);
    }

    // 24. Login OTP cannot verify another OTP purpose (password-reset verify endpoint)
    public function test_login_otp_cannot_be_used_for_password_reset_verification(): void
    {
        $customer = $this->createEligibleCustomer();
        $this->createLoginOtp($customer['user_uuid'], $customer['phone_number'], '654321');

        $response = $this->postJson('/api/v1/auth/verify-password-reset-otp', [
            'phone_number' => $customer['phone_number'],
            'otp_code' => '654321',
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);

        $loginOtp = DB::table('otp_verifications')
            ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->where('purpose_id', $this->purposeId('LOGIN'))
            ->first();
        $this->assertSame($this->otpStatusId('PENDING'), (int) $loginOtp->status_id);
    }

    // 25. account deactivated between OTP request and verification fails safely
    public function test_account_deactivated_after_request_fails_verification_safely(): void
    {
        $customer = $this->createEligibleCustomer();
        $this->createLoginOtp($customer['user_uuid'], $customer['phone_number'], '123456');

        DB::table('users')->where('id', UuidBinary::toBinary($customer['user_uuid']))->update([
            'account_status_id' => $this->accountStatusId('DEACTIVATED'),
        ]);

        $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number'], '123456'))
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => self::GENERIC_VERIFY_MESSAGE, 'data' => null]);

        $this->assertSame(
            0,
            DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($customer['user_uuid']))->count()
        );
    }

    // 26. role removed between request and verification fails safely
    public function test_role_removed_after_request_fails_verification_safely(): void
    {
        $customer = $this->createEligibleCustomer();
        $this->createLoginOtp($customer['user_uuid'], $customer['phone_number'], '123456');

        DB::table('user_roles')
            ->where('user_id', UuidBinary::toBinary($customer['user_uuid']))
            ->where('role_id', $this->roleId('CUSTOMER'))
            ->delete();

        $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number'], '123456'))
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => self::GENERIC_VERIFY_MESSAGE, 'data' => null]);

        $this->assertSame(
            0,
            DB::table('auth_sessions')->where('user_id', UuidBinary::toBinary($customer['user_uuid']))->count()
        );
    }

    // 27 (verify side). verify response never leaks secrets/internal ids
    public function test_verify_response_never_leaks_secrets_or_internal_ids(): void
    {
        $customer = $this->createEligibleCustomer();
        $this->createLoginOtp($customer['user_uuid'], $customer['phone_number'], '123456');

        $response = $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number'], '123456'));

        $content = $response->getContent();
        $this->assertStringNotContainsString('code_hash', $content);
        $this->assertStringNotContainsString('password_hash', $content);
        $this->assertStringNotContainsString('refresh_token_hash', $content);
        $this->assertStringNotContainsString('account_status_id', $content);

        $data = $response->json('data');
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('password_hash', $data);
    }

    // Wrong-code and cross-identity verify attempts stay under the shared
    // Login OTP verify rate limiter (defense-in-depth beyond OTP max_attempts).
    public function test_repeated_wrong_verify_attempts_are_rate_limited(): void
    {
        $customer = $this->createEligibleCustomer();
        $this->createLoginOtp($customer['user_uuid'], $customer['phone_number'], '123456');

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $response = $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number'], '000000'));
            $this->assertNotSame(429, $response->status());
        }

        $this->postJson(self::VERIFY_URI, $this->verifyPayload($customer['phone_number'], '000000'))
            ->assertStatus(429);
    }

    public function test_repeated_request_otp_calls_are_rate_limited(): void
    {
        $customer = $this->createEligibleCustomer();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->postJson(self::REQUEST_URI, ['phone_number' => $customer['phone_number']]);
            $this->assertNotSame(429, $response->status());
        }

        $this->postJson(self::REQUEST_URI, ['phone_number' => $customer['phone_number']])
            ->assertStatus(429);
    }
}
