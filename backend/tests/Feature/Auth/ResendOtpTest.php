<?php

namespace Tests\Feature\Auth;

use App\Models\OtpVerification;
use App\Support\Uuid\UuidBinary;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class ResendOtpTest extends TestCase
{
    use DatabaseTransactions;

    private static int $sequence = 0;

    private function purposeId(string $code): int
    {
        return (int) DB::table('otp_verification_purposes')->where('code', $code)->value('id');
    }

    private function otpStatusId(string $code): int
    {
        return (int) DB::table('otp_verification_statuses')->where('code', $code)->value('id');
    }

    private function accountStatusId(string $code): int
    {
        return (int) DB::table('user_account_statuses')->where('code', $code)->value('id');
    }

    private function createPendingUser(array $overrides = []): array
    {
        self::$sequence++;

        $userUuid = UuidBinary::generate();
        $phoneNumber = '+97151000'.str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT);

        DB::table('users')->insert(array_merge([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => 'resend.otp.'.self::$sequence.'@example.com',
            'password_hash' => Hash::make('Passw0rd123'),
            'account_status_id' => $this->accountStatusId('PENDING_VERIFICATION'),
            'phone_verified_at' => null,
        ], $overrides));

        return ['user_uuid' => $userUuid, 'phone_number' => $phoneNumber];
    }

    private function createOtp(string $userUuid, string $phoneNumber, string $rawCode, array $overrides = []): string
    {
        $otpUuid = UuidBinary::generate();
        $now = now();

        DB::table('otp_verifications')->insert(array_merge([
            'id' => UuidBinary::toBinary($otpUuid),
            'user_id' => UuidBinary::toBinary($userUuid),
            'purpose_id' => $this->purposeId('PHONE_VERIFICATION'),
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

    /**
     * Creates a pending user with an OTP issued far enough in the past that
     * the 60-second resend cooldown has already elapsed.
     */
    private function createPendingUserWithStaleOtp(string $rawCode = '123456', array $otpOverrides = []): array
    {
        $user = $this->createPendingUser();
        $issuedAt = now()->subMinutes(2);

        $otpUuid = $this->createOtp($user['user_uuid'], $user['phone_number'], $rawCode, array_merge([
            'created_at' => $issuedAt,
            'updated_at' => $issuedAt,
        ], $otpOverrides));

        return [
            'user_uuid' => $user['user_uuid'],
            'phone_number' => $user['phone_number'],
            'otp_uuid' => $otpUuid,
        ];
    }

    public function test_successful_resend_returns_200_with_expected_payload(): void
    {
        $fixture = $this->createPendingUserWithStaleOtp();

        $response = $this->postJson('/api/v1/auth/resend-otp', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'phone_number' => $fixture['phone_number'],
                ],
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'otp_verification_uuid',
                    'otp_expires_at',
                    'resend_available_at',
                    'phone_number',
                ],
            ]);

        $newOtpUuid = $response->json('data.otp_verification_uuid');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $newOtpUuid
        );
    }

    public function test_malformed_otp_uuid_fails_field_validation(): void
    {
        $response = $this->postJson('/api/v1/auth/resend-otp', [
            'otp_verification_uuid' => 'not-a-uuid',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['otp_verification_uuid']);
    }

    public function test_unknown_otp_uuid_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/auth/resend-otp', [
            'otp_verification_uuid' => '11111111-1111-1111-1111-111111111111',
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_wrong_purpose_otp_is_rejected(): void
    {
        $fixture = $this->createPendingUserWithStaleOtp('123456', [
            'purpose_id' => $this->purposeId('PASSWORD_RESET'),
        ]);

        $response = $this->postJson('/api/v1/auth/resend-otp', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);

        $this->assertDatabaseCount('otp_verifications', 1);
    }

    public function test_already_verified_user_is_rejected(): void
    {
        $fixture = $this->createPendingUserWithStaleOtp('123456', [
            'status_id' => $this->otpStatusId('VERIFIED'),
            'verified_at' => now()->subMinutes(2),
        ]);

        DB::table('users')->where('id', UuidBinary::toBinary($fixture['user_uuid']))->update([
            'account_status_id' => $this->accountStatusId('ACTIVE'),
            'phone_verified_at' => now()->subMinutes(2),
        ]);

        $response = $this->postJson('/api/v1/auth/resend-otp', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);

        $this->assertDatabaseCount('otp_verifications', 1);
    }

    public function test_resend_before_60_seconds_is_rejected(): void
    {
        $user = $this->createPendingUser();
        $otpUuid = $this->createOtp($user['user_uuid'], $user['phone_number'], '123456');

        $response = $this->postJson('/api/v1/auth/resend-otp', [
            'otp_verification_uuid' => $otpUuid,
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_cooldown_failure_creates_no_new_otp_and_does_not_invalidate_current_otp(): void
    {
        $user = $this->createPendingUser();
        $otpUuid = $this->createOtp($user['user_uuid'], $user['phone_number'], '123456');

        $this->postJson('/api/v1/auth/resend-otp', [
            'otp_verification_uuid' => $otpUuid,
        ])->assertStatus(422);

        $this->assertDatabaseCount('otp_verifications', 1);

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($otpUuid))->first();
        $this->assertSame($this->otpStatusId('PENDING'), (int) $otp->status_id);
        $this->assertNull($otp->invalidated_at);
    }

    public function test_old_active_otp_becomes_invalidated_after_successful_resend(): void
    {
        $fixture = $this->createPendingUserWithStaleOtp();

        $this->postJson('/api/v1/auth/resend-otp', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
        ])->assertStatus(200);

        $oldOtp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($fixture['otp_uuid']))->first();
        $this->assertSame($this->otpStatusId('INVALIDATED'), (int) $oldOtp->status_id);
        $this->assertNotNull($oldOtp->invalidated_at);
    }

    public function test_new_otp_is_pending_with_expected_defaults(): void
    {
        $fixture = $this->createPendingUserWithStaleOtp();

        $response = $this->postJson('/api/v1/auth/resend-otp', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
        ])->assertStatus(200);

        $newOtpUuid = $response->json('data.otp_verification_uuid');
        $newOtp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($newOtpUuid))->first();

        $this->assertSame($this->otpStatusId('PENDING'), (int) $newOtp->status_id);
        $this->assertSame($this->purposeId('PHONE_VERIFICATION'), (int) $newOtp->purpose_id);
        $this->assertSame(0, (int) $newOtp->failed_attempt_count);
        $this->assertSame(5, (int) $newOtp->max_attempts);
        $this->assertSame($fixture['phone_number'], $newOtp->target_phone_number);

        $expiresAt = Carbon::parse($newOtp->expires_at);
        $createdAt = Carbon::parse($newOtp->created_at);
        $this->assertEqualsWithDelta(300, $createdAt->diffInSeconds($expiresAt), 5);
    }

    public function test_new_otp_uuid_differs_from_old(): void
    {
        $fixture = $this->createPendingUserWithStaleOtp();

        $response = $this->postJson('/api/v1/auth/resend-otp', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
        ])->assertStatus(200);

        $newOtpUuid = $response->json('data.otp_verification_uuid');

        $this->assertNotSame($fixture['otp_uuid'], $newOtpUuid);
    }

    public function test_response_never_leaks_raw_otp_hash_or_secrets(): void
    {
        $fixture = $this->createPendingUserWithStaleOtp();

        $response = $this->postJson('/api/v1/auth/resend-otp', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
        ]);

        $response->assertStatus(200);

        $json = strtolower(json_encode($response->json()));

        $this->assertStringNotContainsString('code_hash', $json);
        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString('"code"', $json);

        $data = $response->json('data');
        $this->assertSame(
            ['otp_verification_uuid', 'otp_expires_at', 'resend_available_at', 'phone_number'],
            array_keys($data)
        );
    }

    public function test_only_one_active_pending_otp_remains_after_resend(): void
    {
        $fixture = $this->createPendingUserWithStaleOtp();

        $this->postJson('/api/v1/auth/resend-otp', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
        ])->assertStatus(200);

        $pendingCount = DB::table('otp_verifications')
            ->where('user_id', UuidBinary::toBinary($fixture['user_uuid']))
            ->where('purpose_id', $this->purposeId('PHONE_VERIFICATION'))
            ->where('status_id', $this->otpStatusId('PENDING'))
            ->count();

        $this->assertSame(1, $pendingCount);
    }

    public function test_transaction_rollback_leaves_old_otp_valid_if_replacement_creation_fails(): void
    {
        $fixture = $this->createPendingUserWithStaleOtp();

        OtpVerification::creating(function () {
            throw new RuntimeException('Simulated failure while creating the replacement OTP.');
        });

        $this->postJson('/api/v1/auth/resend-otp', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
        ])->assertStatus(500);

        $this->assertDatabaseCount('otp_verifications', 1);

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($fixture['otp_uuid']))->first();
        $this->assertSame($this->otpStatusId('PENDING'), (int) $otp->status_id);
        $this->assertNull($otp->invalidated_at);
    }

    public function test_concurrent_resend_requests_lock_rows_for_update(): void
    {
        $fixture = $this->createPendingUserWithStaleOtp();

        DB::enableQueryLog();

        $this->postJson('/api/v1/auth/resend-otp', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
        ])->assertStatus(200);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $lockingQueries = collect($queries)->filter(
            fn (array $entry) => str_contains(strtolower($entry['query']), 'for update')
        );

        $this->assertGreaterThanOrEqual(3, $lockingQueries->count());
    }

    /**
     * Deterministic lock-order regression test for the deadlock fix.
     *
     * True concurrent integration testing (two real overlapping MySQL
     * transactions racing for the same locks) is not practical inside a
     * single-threaded PHPUnit/Laravel feature test: a second postJson()
     * call on the same process/connection cannot run interleaved with a
     * first request's still-open transaction without a second real
     * connection and process-level concurrency (e.g. pcntl fork), which
     * this project's test setup does not provide. Instead, this test
     * deterministically verifies the property that prevents the deadlock:
     * the `users` row is always the first `FOR UPDATE` lock acquired,
     * before any `otp_verifications` row is locked. Since every concurrent
     * resend request for the same user always attempts the user lock
     * first, two such requests can only ever serialize on that single
     * shared lock and never form a cross-wait cycle over two different
     * OTP rows.
     */
    public function test_user_row_is_locked_for_update_before_any_otp_row(): void
    {
        $fixture = $this->createPendingUserWithStaleOtp();

        DB::enableQueryLog();

        $this->postJson('/api/v1/auth/resend-otp', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
        ])->assertStatus(200);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $lockingQueries = collect($queries)
            ->filter(fn (array $entry) => str_contains(strtolower($entry['query']), 'for update'))
            ->values();

        $this->assertGreaterThanOrEqual(3, $lockingQueries->count());

        $this->assertStringContainsString(
            'from `users`',
            strtolower($lockingQueries->first()['query']),
            'The users row must be the first FOR UPDATE lock acquired in a resend transaction.'
        );

        $lockingQueries->skip(1)->each(function (array $entry) {
            $this->assertStringContainsString('from `otp_verifications`', strtolower($entry['query']));
        });
    }

    /**
     * Reproduces the setup that previously enabled the deadlock: the client
     * calls resend again using an OTP UUID that has since been superseded
     * by a prior resend (i.e. no longer the "latest" row for the user).
     * The fixed lock order (user first) plus re-validation of the
     * referenced OTP under lock must still resolve this correctly, acting
     * on the current latest OTP rather than the stale referenced one.
     *
     * The two rows' created_at values are deliberately pinned minutes apart
     * from a single captured reference instant (rather than two independent
     * now() calls). This environment's datetime(6) writes have been
     * observed to round to whole seconds, so two now() calls made
     * milliseconds apart can land on the identical second and make
     * `ORDER BY created_at DESC LIMIT 1` tie-break arbitrarily; a wide,
     * single-reference gap removes that ambiguity entirely.
     */
    public function test_resend_succeeds_when_referencing_a_superseded_older_otp_uuid(): void
    {
        $referenceNow = now();

        $user = $this->createPendingUser();
        $originalOtpUuid = $this->createOtp($user['user_uuid'], $user['phone_number'], '123456', [
            'created_at' => $referenceNow->copy()->subMinutes(5),
            'updated_at' => $referenceNow->copy()->subMinutes(5),
        ]);

        $first = $this->postJson('/api/v1/auth/resend-otp', [
            'otp_verification_uuid' => $originalOtpUuid,
        ])->assertStatus(200);

        $latestOtpUuid = $first->json('data.otp_verification_uuid');

        DB::table('otp_verifications')
            ->where('id', UuidBinary::toBinary($latestOtpUuid))
            ->update([
                'created_at' => $referenceNow->copy()->subMinutes(2),
                'updated_at' => $referenceNow->copy()->subMinutes(2),
            ]);

        $second = $this->postJson('/api/v1/auth/resend-otp', [
            'otp_verification_uuid' => $originalOtpUuid,
        ]);

        $second->assertStatus(200);
        $newestOtpUuid = $second->json('data.otp_verification_uuid');

        $this->assertNotSame($originalOtpUuid, $newestOtpUuid);
        $this->assertNotSame($latestOtpUuid, $newestOtpUuid);

        $original = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($originalOtpUuid))->first();
        $this->assertSame($this->otpStatusId('INVALIDATED'), (int) $original->status_id);

        $middle = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($latestOtpUuid))->first();
        $this->assertSame($this->otpStatusId('INVALIDATED'), (int) $middle->status_id);

        $newest = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($newestOtpUuid))->first();
        $this->assertSame($this->otpStatusId('PENDING'), (int) $newest->status_id);

        $pendingCount = DB::table('otp_verifications')
            ->where('user_id', UuidBinary::toBinary($user['user_uuid']))
            ->where('purpose_id', $this->purposeId('PHONE_VERIFICATION'))
            ->where('status_id', $this->otpStatusId('PENDING'))
            ->count();

        $this->assertSame(1, $pendingCount);
    }

    public function test_no_auth_token_or_session_is_created(): void
    {
        $fixture = $this->createPendingUserWithStaleOtp();

        $response = $this->postJson('/api/v1/auth/resend-otp', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
        ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertArrayNotHasKey('token', $data);
        $this->assertArrayNotHasKey('access_token', $data);
        $this->assertArrayNotHasKey('session', $data);
        $response->assertHeaderMissing('Set-Cookie');

        $user = DB::table('users')->where('id', UuidBinary::toBinary($fixture['user_uuid']))->first();
        $this->assertSame($this->accountStatusId('PENDING_VERIFICATION'), (int) $user->account_status_id);
    }
}
