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

class ForgotPasswordTest extends TestCase
{
    use DatabaseTransactions;

    private static int $sequence = 0;

    private const SAFE_MESSAGE = 'If an account exists for this phone number, a password reset code has been sent.';

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
        $phoneNumber = '+97153000'.str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT);

        DB::table('users')->insert(array_merge([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => 'forgot.password.'.self::$sequence.'@example.com',
            'password_hash' => Hash::make('Passw0rd123'),
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

    private function unknownPhoneNumber(): string
    {
        self::$sequence++;

        return '+97159999'.str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT);
    }

    // 1. existing eligible user creates a PASSWORD_RESET OTP.
    public function test_existing_eligible_user_creates_password_reset_otp(): void
    {
        $user = $this->createUser();

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'phone_number' => $user['phone_number'],
        ]);

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'message' => self::SAFE_MESSAGE,
            'data' => null,
        ]);

        $otp = DB::table('otp_verifications')
            ->where('user_id', UuidBinary::toBinary($user['user_uuid']))
            ->where('purpose_id', $this->purposeId('PASSWORD_RESET'))
            ->first();

        $this->assertNotNull($otp);
        $this->assertSame($this->otpStatusId('PENDING'), (int) $otp->status_id);
    }

    // 2 & 4. unknown phone returns the identical public response (body + status).
    public function test_unknown_phone_returns_same_public_response_as_existing_user(): void
    {
        $user = $this->createUser();

        $existingResponse = $this->postJson('/api/v1/auth/forgot-password', [
            'phone_number' => $user['phone_number'],
        ]);

        $unknownResponse = $this->postJson('/api/v1/auth/forgot-password', [
            'phone_number' => $this->unknownPhoneNumber(),
        ]);

        $existingResponse->assertStatus(200);
        $unknownResponse->assertStatus(200);

        $this->assertSame($existingResponse->status(), $unknownResponse->status());
        $this->assertSame($existingResponse->json(), $unknownResponse->json());
        $this->assertSame(
            ['success' => true, 'message' => self::SAFE_MESSAGE, 'data' => null],
            $unknownResponse->json()
        );
    }

    // 3. unknown phone creates no OTP.
    public function test_unknown_phone_creates_no_otp(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', [
            'phone_number' => $this->unknownPhoneNumber(),
        ])->assertStatus(200);

        $this->assertDatabaseCount('otp_verifications', 0);
    }

    // DEACTIVATED accounts are ineligible and must behave like an unknown phone.
    public function test_deactivated_account_returns_same_public_response_and_creates_no_otp(): void
    {
        $user = $this->createUser('DEACTIVATED');

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'phone_number' => $user['phone_number'],
        ]);

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'message' => self::SAFE_MESSAGE,
            'data' => null,
        ]);

        $this->assertDatabaseCount('otp_verifications', 0);
    }

    // 5, 6, 7, 8, 9. purpose/status/expiry/max_attempts/failed_attempt_count defaults.
    public function test_new_otp_has_expected_defaults(): void
    {
        $user = $this->createUser();

        $this->postJson('/api/v1/auth/forgot-password', [
            'phone_number' => $user['phone_number'],
        ])->assertStatus(200);

        $otp = DB::table('otp_verifications')
            ->where('user_id', UuidBinary::toBinary($user['user_uuid']))
            ->first();

        $this->assertSame($this->purposeId('PASSWORD_RESET'), (int) $otp->purpose_id);
        $this->assertSame($this->otpStatusId('PENDING'), (int) $otp->status_id);
        $this->assertSame(0, (int) $otp->failed_attempt_count);
        $this->assertSame(5, (int) $otp->max_attempts);
        $this->assertSame($user['phone_number'], $otp->target_phone_number);

        $expiresAt = Carbon::parse($otp->expires_at);
        $createdAt = Carbon::parse($otp->created_at);
        $this->assertEqualsWithDelta(300, $createdAt->diffInSeconds($expiresAt), 5);
    }

    // 10. raw OTP/hash never leaked in the response.
    public function test_response_never_leaks_raw_otp_or_hash(): void
    {
        $user = $this->createUser();

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'phone_number' => $user['phone_number'],
        ]);

        $response->assertStatus(200);

        $json = strtolower(json_encode($response->json()));

        $this->assertStringNotContainsString('code_hash', $json);
        $this->assertStringNotContainsString('"code"', $json);
        $this->assertStringNotContainsString('uuid', $json);
        $this->assertNull($response->json('data'));
    }

    // 11, 12, 13. password_hash / account status / phone_verified_at unchanged.
    public function test_sensitive_user_fields_are_unchanged(): void
    {
        $user = $this->createUser();

        $before = DB::table('users')->where('id', UuidBinary::toBinary($user['user_uuid']))->first();

        $this->postJson('/api/v1/auth/forgot-password', [
            'phone_number' => $user['phone_number'],
        ])->assertStatus(200);

        $after = DB::table('users')->where('id', UuidBinary::toBinary($user['user_uuid']))->first();

        $this->assertSame($before->password_hash, $after->password_hash);
        $this->assertSame($before->account_status_id, $after->account_status_id);
        $this->assertSame($before->phone_verified_at, $after->phone_verified_at);
    }

    // 14. auth_sessions unaffected.
    public function test_auth_sessions_are_unaffected(): void
    {
        $user = $this->createUser();

        $this->postJson('/api/v1/auth/forgot-password', [
            'phone_number' => $user['phone_number'],
        ])->assertStatus(200);

        $this->assertDatabaseCount('auth_sessions', 0);
    }

    // 15. cooldown < 60s does not create another OTP.
    public function test_cooldown_before_60_seconds_does_not_create_another_otp(): void
    {
        $user = $this->createUser();
        $otpUuid = $this->createOtp($user['user_uuid'], $user['phone_number'], '123456');

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'phone_number' => $user['phone_number'],
        ]);

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'message' => self::SAFE_MESSAGE,
            'data' => null,
        ]);

        $this->assertDatabaseCount('otp_verifications', 1);

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($otpUuid))->first();
        $this->assertSame($this->otpStatusId('PENDING'), (int) $otp->status_id);
        $this->assertNull($otp->invalidated_at);
    }

    // 16 & 17. successful resend after cooldown invalidates the old OTP, leaving exactly one PENDING row.
    public function test_resend_after_cooldown_invalidates_old_otp_and_leaves_exactly_one_pending(): void
    {
        $user = $this->createUser();
        $issuedAt = now()->subMinutes(2);
        $oldOtpUuid = $this->createOtp($user['user_uuid'], $user['phone_number'], '123456', [
            'created_at' => $issuedAt,
            'updated_at' => $issuedAt,
        ]);

        $this->postJson('/api/v1/auth/forgot-password', [
            'phone_number' => $user['phone_number'],
        ])->assertStatus(200);

        $oldOtp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($oldOtpUuid))->first();
        $this->assertSame($this->otpStatusId('INVALIDATED'), (int) $oldOtp->status_id);
        $this->assertNotNull($oldOtp->invalidated_at);

        $pendingCount = DB::table('otp_verifications')
            ->where('user_id', UuidBinary::toBinary($user['user_uuid']))
            ->where('purpose_id', $this->purposeId('PASSWORD_RESET'))
            ->where('status_id', $this->otpStatusId('PENDING'))
            ->count();

        $this->assertSame(1, $pendingCount);
        $this->assertDatabaseCount('otp_verifications', 2);
    }

    // 18. registration PHONE_VERIFICATION OTPs are not touched by a password-reset request.
    public function test_phone_verification_otp_is_not_invalidated(): void
    {
        $user = $this->createUser();
        $issuedAt = now()->subMinutes(2);
        $phoneVerificationOtpUuid = $this->createOtp($user['user_uuid'], $user['phone_number'], '654321', [
            'purpose_id' => $this->purposeId('PHONE_VERIFICATION'),
            'created_at' => $issuedAt,
            'updated_at' => $issuedAt,
        ]);

        $this->postJson('/api/v1/auth/forgot-password', [
            'phone_number' => $user['phone_number'],
        ])->assertStatus(200);

        $phoneVerificationOtp = DB::table('otp_verifications')
            ->where('id', UuidBinary::toBinary($phoneVerificationOtpUuid))
            ->first();

        $this->assertSame($this->otpStatusId('PENDING'), (int) $phoneVerificationOtp->status_id);
        $this->assertNull($phoneVerificationOtp->invalidated_at);
    }

    // 19. transaction rollback preserves the previous OTP if replacement creation fails.
    public function test_transaction_rollback_leaves_old_otp_valid_if_replacement_creation_fails(): void
    {
        $user = $this->createUser();
        $issuedAt = now()->subMinutes(2);
        $oldOtpUuid = $this->createOtp($user['user_uuid'], $user['phone_number'], '123456', [
            'created_at' => $issuedAt,
            'updated_at' => $issuedAt,
        ]);

        OtpVerification::creating(function () {
            throw new RuntimeException('Simulated failure while creating the replacement OTP.');
        });

        $this->postJson('/api/v1/auth/forgot-password', [
            'phone_number' => $user['phone_number'],
        ])->assertStatus(500);

        $this->assertDatabaseCount('otp_verifications', 1);

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($oldOtpUuid))->first();
        $this->assertSame($this->otpStatusId('PENDING'), (int) $otp->status_id);
        $this->assertNull($otp->invalidated_at);
    }

    // 20. lock ordering: the users row is always the first FOR UPDATE lock, before any otp_verifications row.
    public function test_user_row_is_locked_for_update_before_any_otp_row(): void
    {
        $user = $this->createUser();
        $issuedAt = now()->subMinutes(2);
        $this->createOtp($user['user_uuid'], $user['phone_number'], '123456', [
            'created_at' => $issuedAt,
            'updated_at' => $issuedAt,
        ]);

        DB::enableQueryLog();

        $this->postJson('/api/v1/auth/forgot-password', [
            'phone_number' => $user['phone_number'],
        ])->assertStatus(200);

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

    // Field validation still applies and is not itself an enumeration channel.
    public function test_missing_phone_number_fails_validation(): void
    {
        $response = $this->postJson('/api/v1/auth/forgot-password', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['phone_number']);
    }

    public function test_malformed_phone_number_fails_validation(): void
    {
        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'phone_number' => 'not-a-phone-number',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['phone_number']);
    }
}
