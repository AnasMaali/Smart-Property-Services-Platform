<?php

namespace Tests\Feature\Auth;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Legacy customer password auth tests.
 *
 * Customer password routes were removed in favour of OTP-only auth.
 * Run explicitly with: php artisan test --group=legacy
 *
 * @group legacy
 */
class VerifyPasswordResetOtpTest extends TestCase
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

    private function createUser(string $statusCode = 'ACTIVE', array $overrides = []): array
    {
        self::$sequence++;

        $userUuid = UuidBinary::generate();
        $phoneNumber = '+97155000'.str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT);

        DB::table('users')->insert(array_merge([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => 'verify.reset.otp.'.self::$sequence.'@example.com',
            'password_hash' => Hash::make('OldPassw0rd'),
            'account_status_id' => $this->accountStatusId($statusCode),
            'phone_verified_at' => now()->subDay(),
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
            'purpose_id' => $this->purposeId('PASSWORD_RESET'),
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

    private function createUserWithOtp(string $rawCode = '123456', array $otpOverrides = []): array
    {
        $user = $this->createUser();
        $otpUuid = $this->createOtp($user['user_uuid'], $user['phone_number'], $rawCode, $otpOverrides);

        return [
            'user_uuid' => $user['user_uuid'],
            'phone_number' => $user['phone_number'],
            'otp_uuid' => $otpUuid,
        ];
    }

    private function verify(string $phoneNumber, string $code)
    {
        return $this->postJson('/api/v1/auth/verify-password-reset-otp', [
            'phone_number' => $phoneNumber,
            'otp_code' => $code,
        ]);
    }

    // Success: correct code returns a reset token and creates the reset session.
    public function test_successful_verification_returns_reset_token_and_expected_payload(): void
    {
        $fixture = $this->createUserWithOtp('123456');

        $response = $this->verify($fixture['phone_number'], '123456');

        $response->assertStatus(200)->assertJson(['success' => true]);
        $response->assertJsonStructure(['success', 'message', 'data' => ['reset_token', 'reset_token_expires_at']]);

        $resetToken = $response->json('data.reset_token');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $resetToken);

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($fixture['otp_uuid']))->first();
        $this->assertSame($this->otpStatusId('VERIFIED'), (int) $otp->status_id);
        $this->assertNotNull($otp->verified_at);

        $session = DB::table('password_reset_sessions')
            ->where('otp_verification_id', UuidBinary::toBinary($fixture['otp_uuid']))
            ->first();

        $this->assertNotNull($session);
        $this->assertSame(hash('sha256', hex2bin($resetToken), true), $session->reset_token_hash);
        $this->assertNull($session->used_at);
        $this->assertNull($session->revoked_at);
    }

    // Wrong code increments failed_attempt_count and last_attempt_at, without issuing a token.
    public function test_wrong_code_increments_failed_attempts_and_is_rejected(): void
    {
        $fixture = $this->createUserWithOtp('123456');

        $response = $this->verify($fixture['phone_number'], '000000');

        $response->assertStatus(422)->assertJson(['success' => false, 'data' => null]);

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($fixture['otp_uuid']))->first();
        $this->assertSame(1, (int) $otp->failed_attempt_count);
        $this->assertNotNull($otp->last_attempt_at);
        $this->assertDatabaseCount('password_reset_sessions', 0);
    }

    // Fifth failed attempt sets ATTEMPTS_EXCEEDED, and even the correct code is then rejected.
    public function test_fifth_failed_attempt_sets_attempts_exceeded(): void
    {
        $fixture = $this->createUserWithOtp('123456', ['max_attempts' => 5]);

        for ($i = 0; $i < 5; $i++) {
            $this->verify($fixture['phone_number'], '000000')->assertStatus(422);
        }

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($fixture['otp_uuid']))->first();
        $this->assertSame(5, (int) $otp->failed_attempt_count);
        $this->assertSame($this->otpStatusId('ATTEMPTS_EXCEEDED'), (int) $otp->status_id);

        $this->verify($fixture['phone_number'], '123456')->assertStatus(422)->assertJson(['success' => false]);
        $this->assertDatabaseCount('password_reset_sessions', 0);
    }

    // Expired OTP is marked EXPIRED and rejected.
    public function test_expired_otp_is_marked_expired_and_rejected(): void
    {
        $now = now();
        $fixture = $this->createUserWithOtp('123456', [
            'created_at' => $now->copy()->subMinutes(10),
            'updated_at' => $now->copy()->subMinutes(10),
            'expires_at' => $now->copy()->subMinutes(5),
        ]);

        $response = $this->verify($fixture['phone_number'], '123456');

        $response->assertStatus(422)->assertJson(['success' => false]);

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($fixture['otp_uuid']))->first();
        $this->assertSame($this->otpStatusId('EXPIRED'), (int) $otp->status_id);
        $this->assertDatabaseCount('password_reset_sessions', 0);
    }

    // A verified OTP cannot be reused to obtain a second reset token.
    public function test_verified_otp_cannot_be_reused(): void
    {
        $fixture = $this->createUserWithOtp('123456');

        $this->verify($fixture['phone_number'], '123456')->assertStatus(200);
        $this->assertDatabaseCount('password_reset_sessions', 1);

        $second = $this->verify($fixture['phone_number'], '123456');
        $second->assertStatus(422)->assertJson(['success' => false]);

        $this->assertDatabaseCount('password_reset_sessions', 1);
    }

    // Unknown phone number is rejected without leaking account existence.
    public function test_unknown_phone_number_is_rejected(): void
    {
        $this->verify('+971599999999', '123456')
            ->assertStatus(422)
            ->assertJson(['success' => false, 'data' => null]);

        $this->assertDatabaseCount('password_reset_sessions', 0);
    }

    // DEACTIVATED account is rejected even with the correct code.
    public function test_deactivated_account_is_rejected(): void
    {
        $user = $this->createUser('DEACTIVATED');
        $otpUuid = $this->createOtp($user['user_uuid'], $user['phone_number'], '123456');

        $this->verify($user['phone_number'], '123456')->assertStatus(422)->assertJson(['success' => false]);

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($otpUuid))->first();
        $this->assertSame($this->otpStatusId('PENDING'), (int) $otp->status_id);
    }

    // A wrong-purpose OTP (e.g. PHONE_VERIFICATION) for the same phone is not matched.
    public function test_non_password_reset_purpose_otp_is_ignored(): void
    {
        $user = $this->createUser();
        $this->createOtp($user['user_uuid'], $user['phone_number'], '123456', [
            'purpose_id' => $this->purposeId('PHONE_VERIFICATION'),
        ]);

        $this->verify($user['phone_number'], '123456')->assertStatus(422)->assertJson(['success' => false]);
    }

    // Response never leaks the OTP code/hash or reset token hash.
    public function test_response_never_leaks_secrets(): void
    {
        $fixture = $this->createUserWithOtp('123456');

        $response = $this->verify($fixture['phone_number'], '123456');
        $response->assertStatus(200);

        $json = strtolower(json_encode($response->json()));

        $this->assertStringNotContainsString('code_hash', $json);
        $this->assertStringNotContainsString('reset_token_hash', $json);
        $this->assertStringNotContainsString('"otp_code"', $json);
    }

    // Field validation.
    public function test_missing_fields_fail_validation(): void
    {
        $this->postJson('/api/v1/auth/verify-password-reset-otp', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number', 'otp_code']);
    }

    public function test_malformed_otp_code_fails_validation(): void
    {
        $fixture = $this->createUserWithOtp('123456');

        $this->verify($fixture['phone_number'], '12a45')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['otp_code']);
    }

    // SECURITY REGRESSION: every rejection branch must be externally
    // indistinguishable - same status code, same message, same response
    // shape - so a caller cannot use this endpoint to learn whether a
    // phone number belongs to a real, active account. This directly
    // covers the enumeration gap where "unknown/no request" previously
    // returned a different message than "wrong code for a real pending
    // OTP" (and other real-account states also differed).
    public function test_all_rejection_branches_return_identical_external_response(): void
    {
        // 1. Unknown phone number entirely.
        $unknown = $this->verify('+971599999998', '123456');

        // 2. Deactivated account (with a pending OTP that would otherwise verify).
        $deactivatedUser = $this->createUser('DEACTIVATED');
        $this->createOtp($deactivatedUser['user_uuid'], $deactivatedUser['phone_number'], '123456');
        $deactivated = $this->verify($deactivatedUser['phone_number'], '123456');

        // 3. Real, active account with no PASSWORD_RESET OTP ever requested.
        $neverRequestedUser = $this->createUser();
        $neverRequested = $this->verify($neverRequestedUser['phone_number'], '123456');

        // 4. Real, active account, OTP expired.
        $expiredNow = now();
        $expiredFixture = $this->createUserWithOtp('123456', [
            'created_at' => $expiredNow->copy()->subMinutes(10),
            'updated_at' => $expiredNow->copy()->subMinutes(10),
            'expires_at' => $expiredNow->copy()->subMinutes(5),
        ]);
        $expired = $this->verify($expiredFixture['phone_number'], '123456');

        // 5. Real, active account, OTP attempts exhausted.
        $exhaustedFixture = $this->createUserWithOtp('123456', ['max_attempts' => 1, 'failed_attempt_count' => 1]);
        $exhausted = $this->verify($exhaustedFixture['phone_number'], '123456');

        // 6. Real, active account, real pending OTP, simply wrong code.
        $wrongCodeFixture = $this->createUserWithOtp('123456');
        $wrongCode = $this->verify($wrongCodeFixture['phone_number'], '000000');

        $responses = [
            'unknown_phone_number' => $unknown,
            'deactivated_account' => $deactivated,
            'no_pending_otp' => $neverRequested,
            'expired_otp' => $expired,
            'attempts_exceeded' => $exhausted,
            'wrong_code' => $wrongCode,
        ];

        foreach ($responses as $scenario => $response) {
            $response->assertStatus(422);
            $this->assertSame(
                ['success', 'message', 'data'],
                array_keys($response->json()),
                "Response shape differed for scenario [{$scenario}]."
            );
            $this->assertFalse($response->json('success'), "success flag differed for scenario [{$scenario}].");
            $this->assertNull($response->json('data'), "data differed for scenario [{$scenario}].");
        }

        $messages = collect($responses)->map(fn ($response) => $response->json('message'))->unique();

        $this->assertCount(
            1,
            $messages,
            'All rejection branches must return the exact same message. Got: '.$messages->values()->implode(' | ')
        );

        $this->assertSame('Invalid or expired verification code.', $messages->first());

        // Internal behavior must be unaffected by the external message unification.
        $this->assertSame(
            $this->otpStatusId('EXPIRED'),
            (int) DB::table('otp_verifications')->where('id', UuidBinary::toBinary($expiredFixture['otp_uuid']))->value('status_id')
        );
        $this->assertSame(
            $this->otpStatusId('ATTEMPTS_EXCEEDED'),
            (int) DB::table('otp_verifications')->where('id', UuidBinary::toBinary($exhaustedFixture['otp_uuid']))->value('status_id'),
            'The attempts-exceeded branch must still transition the OTP status internally.'
        );
        $this->assertSame(
            1,
            (int) DB::table('otp_verifications')->where('id', UuidBinary::toBinary($wrongCodeFixture['otp_uuid']))->value('failed_attempt_count')
        );
        $this->assertDatabaseCount('password_reset_sessions', 0);
    }

    // Lock ordering: the users row is always the first FOR UPDATE lock, before any otp_verifications row.
    public function test_user_row_is_locked_for_update_before_otp_row(): void
    {
        $fixture = $this->createUserWithOtp('123456');

        DB::enableQueryLog();

        $this->verify($fixture['phone_number'], '123456')->assertStatus(200);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $lockingQueries = collect($queries)
            ->filter(fn (array $entry) => str_contains(strtolower($entry['query']), 'for update'))
            ->values();

        $this->assertGreaterThanOrEqual(2, $lockingQueries->count());

        $this->assertStringContainsString(
            'from `users`',
            strtolower($lockingQueries->first()['query']),
            'The users row must be the first FOR UPDATE lock acquired.'
        );

        $lockingQueries->skip(1)->each(function (array $entry) {
            $this->assertStringContainsString('from `otp_verifications`', strtolower($entry['query']));
        });
    }
}
