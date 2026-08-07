<?php

namespace Tests\Feature\Auth;

use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VerifyPhoneTest extends TestCase
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
        $phoneNumber = '+97150000'.str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT);

        DB::table('users')->insert(array_merge([
            'id' => UuidBinary::toBinary($userUuid),
            'phone_number' => $phoneNumber,
            'email' => 'verify.phone.'.self::$sequence.'@example.com',
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

    private function createPendingUserWithOtp(string $rawCode = '123456', array $otpOverrides = []): array
    {
        $user = $this->createPendingUser();
        $otpUuid = $this->createOtp($user['user_uuid'], $user['phone_number'], $rawCode, $otpOverrides);

        return [
            'user_uuid' => $user['user_uuid'],
            'phone_number' => $user['phone_number'],
            'otp_uuid' => $otpUuid,
        ];
    }

    public function test_successful_verification_returns_200_with_expected_payload(): void
    {
        $fixture = $this->createPendingUserWithOtp('123456');

        $response = $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
            'otp_code' => '123456',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user_uuid' => $fixture['user_uuid'],
                    'phone_number' => $fixture['phone_number'],
                    'account_status' => 'ACTIVE',
                    'phone_verified' => true,
                ],
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user_uuid',
                    'phone_number',
                    'account_status',
                    'phone_verified',
                    'phone_verified_at',
                ],
            ]);
    }

    public function test_invalid_otp_uuid_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => '11111111-1111-1111-1111-111111111111',
            'otp_code' => '123456',
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_malformed_otp_uuid_fails_field_validation(): void
    {
        $response = $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => 'not-a-uuid',
            'otp_code' => '123456',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['otp_verification_uuid']);
    }

    public function test_invalid_code_format_is_rejected(): void
    {
        $fixture = $this->createPendingUserWithOtp('123456');

        $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
            'otp_code' => '12a45',
        ])->assertStatus(422)->assertJsonValidationErrors(['otp_code']);

        $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
            'otp_code' => '12345',
        ])->assertStatus(422)->assertJsonValidationErrors(['otp_code']);

        $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
            'otp_code' => '1234567',
        ])->assertStatus(422)->assertJsonValidationErrors(['otp_code']);
    }

    public function test_wrong_code_increments_failed_attempt_count(): void
    {
        $fixture = $this->createPendingUserWithOtp('123456');

        $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
            'otp_code' => '000000',
        ])->assertStatus(422)->assertJson(['success' => false]);

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($fixture['otp_uuid']))->first();
        $this->assertSame(1, (int) $otp->failed_attempt_count);
    }

    public function test_last_attempt_at_is_updated_on_a_failed_attempt(): void
    {
        $fixture = $this->createPendingUserWithOtp('123456');

        $otpBefore = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($fixture['otp_uuid']))->first();
        $this->assertNull($otpBefore->last_attempt_at);

        $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
            'otp_code' => '000000',
        ])->assertStatus(422);

        $otpAfter = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($fixture['otp_uuid']))->first();
        $this->assertNotNull($otpAfter->last_attempt_at);
    }

    public function test_fifth_failed_attempt_sets_attempts_exceeded(): void
    {
        $fixture = $this->createPendingUserWithOtp('123456', ['max_attempts' => 5]);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/verify-phone', [
                'otp_verification_uuid' => $fixture['otp_uuid'],
                'otp_code' => '000000',
            ])->assertStatus(422);
        }

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($fixture['otp_uuid']))->first();
        $this->assertSame(4, (int) $otp->failed_attempt_count);
        $this->assertSame($this->otpStatusId('PENDING'), (int) $otp->status_id);

        // Fifth failed attempt reaches max_attempts.
        $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
            'otp_code' => '000000',
        ])->assertStatus(422);

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($fixture['otp_uuid']))->first();
        $this->assertSame(5, (int) $otp->failed_attempt_count);
        $this->assertSame($this->otpStatusId('ATTEMPTS_EXCEEDED'), (int) $otp->status_id);

        // Even the correct code is now rejected.
        $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
            'otp_code' => '123456',
        ])->assertStatus(422)->assertJson(['success' => false]);

        $user = DB::table('users')->where('id', UuidBinary::toBinary($fixture['user_uuid']))->first();
        $this->assertSame($this->accountStatusId('PENDING_VERIFICATION'), (int) $user->account_status_id);
    }

    public function test_expired_otp_is_marked_expired_and_rejected(): void
    {
        $now = now();
        $fixture = $this->createPendingUserWithOtp('123456', [
            'created_at' => $now->copy()->subMinutes(10),
            'updated_at' => $now->copy()->subMinutes(10),
            'expires_at' => $now->copy()->subMinutes(5),
        ]);

        $response = $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
            'otp_code' => '123456',
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($fixture['otp_uuid']))->first();
        $this->assertSame($this->otpStatusId('EXPIRED'), (int) $otp->status_id);

        $user = DB::table('users')->where('id', UuidBinary::toBinary($fixture['user_uuid']))->first();
        $this->assertSame($this->accountStatusId('PENDING_VERIFICATION'), (int) $user->account_status_id);
        $this->assertNull($user->phone_verified_at);
    }

    public function test_verified_otp_cannot_be_reused(): void
    {
        $fixture = $this->createPendingUserWithOtp('123456');

        $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
            'otp_code' => '123456',
        ])->assertStatus(200);

        $second = $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
            'otp_code' => '123456',
        ]);

        $second->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_invalidated_otp_cannot_be_used(): void
    {
        $fixture = $this->createPendingUserWithOtp('123456', [
            'status_id' => $this->otpStatusId('INVALIDATED'),
            'invalidated_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
            'otp_code' => '123456',
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);

        $user = DB::table('users')->where('id', UuidBinary::toBinary($fixture['user_uuid']))->first();
        $this->assertSame($this->accountStatusId('PENDING_VERIFICATION'), (int) $user->account_status_id);
    }

    public function test_wrong_purpose_otp_is_rejected(): void
    {
        $fixture = $this->createPendingUserWithOtp('123456', [
            'purpose_id' => $this->purposeId('PASSWORD_RESET'),
        ]);

        $response = $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
            'otp_code' => '123456',
        ]);

        $response->assertStatus(422)->assertJson(['success' => false]);

        $user = DB::table('users')->where('id', UuidBinary::toBinary($fixture['user_uuid']))->first();
        $this->assertSame($this->accountStatusId('PENDING_VERIFICATION'), (int) $user->account_status_id);
    }

    public function test_correct_otp_sets_verified_at(): void
    {
        $fixture = $this->createPendingUserWithOtp('123456');

        $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
            'otp_code' => '123456',
        ])->assertStatus(200);

        $otp = DB::table('otp_verifications')->where('id', UuidBinary::toBinary($fixture['otp_uuid']))->first();
        $this->assertNotNull($otp->verified_at);
        $this->assertSame($this->otpStatusId('VERIFIED'), (int) $otp->status_id);
    }

    public function test_correct_otp_sets_user_phone_verified_at(): void
    {
        $fixture = $this->createPendingUserWithOtp('123456');

        $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
            'otp_code' => '123456',
        ])->assertStatus(200);

        $user = DB::table('users')->where('id', UuidBinary::toBinary($fixture['user_uuid']))->first();
        $this->assertNotNull($user->phone_verified_at);
    }

    public function test_correct_otp_activates_the_account(): void
    {
        $fixture = $this->createPendingUserWithOtp('123456');

        $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
            'otp_code' => '123456',
        ])->assertStatus(200);

        $user = DB::table('users')->where('id', UuidBinary::toBinary($fixture['user_uuid']))->first();
        $this->assertSame($this->accountStatusId('ACTIVE'), (int) $user->account_status_id);
    }

    public function test_no_auth_token_or_session_is_created(): void
    {
        $fixture = $this->createPendingUserWithOtp('123456');

        $response = $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
            'otp_code' => '123456',
        ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertSame(
            ['user_uuid', 'phone_number', 'account_status', 'phone_verified', 'phone_verified_at'],
            array_keys($data)
        );
        $this->assertArrayNotHasKey('token', $data);
        $this->assertArrayNotHasKey('access_token', $data);
        $this->assertArrayNotHasKey('session', $data);
        $response->assertHeaderMissing('Set-Cookie');
    }

    public function test_response_never_leaks_otp_hash_or_secrets(): void
    {
        $fixture = $this->createPendingUserWithOtp('123456');

        $response = $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
            'otp_code' => '000000',
        ]);

        $response->assertStatus(422);

        $json = strtolower(json_encode($response->json()));

        $this->assertStringNotContainsString('code_hash', $json);
        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString('000000', $json);
        $this->assertStringNotContainsString('123456', $json);
    }

    public function test_otp_row_is_locked_for_update_during_verification(): void
    {
        $fixture = $this->createPendingUserWithOtp('123456');

        DB::enableQueryLog();

        $this->postJson('/api/v1/auth/verify-phone', [
            'otp_verification_uuid' => $fixture['otp_uuid'],
            'otp_code' => '123456',
        ])->assertStatus(200);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $lockingQueries = collect($queries)->filter(
            fn (array $entry) => str_contains(strtolower($entry['query']), 'for update')
        );

        $this->assertGreaterThanOrEqual(2, $lockingQueries->count());
    }
}
