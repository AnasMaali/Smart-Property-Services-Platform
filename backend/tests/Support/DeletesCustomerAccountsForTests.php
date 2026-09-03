<?php

namespace Tests\Support;

use App\Services\Auth\JwtTokenService;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * OTP-based account deletion helpers for feature tests.
 */
trait DeletesCustomerAccountsForTests
{
    protected function seedAccountDeletionOtp(string $accessToken, string $otpCode = '123456'): void
    {
        $claims = app(JwtTokenService::class)->decodeAccessToken($accessToken);
        $userUuid = is_object($claims) ? ($claims->sub ?? null) : null;
        if (! is_string($userUuid) || $userUuid === '') {
            throw new RuntimeException('Could not resolve user UUID from access token.');
        }

        $purposeId = (int) DB::table('otp_verification_purposes')->where('code', 'ACCOUNT_DELETION')->value('id');
        if ($purposeId === 0) {
            DB::table('otp_verification_purposes')->insert([
                'code' => 'ACCOUNT_DELETION',
                'name' => 'Account Deletion',
                'description' => 'Used to confirm account deletion via OTP before erasing customer data.',
                'is_active' => 1,
            ]);
            $purposeId = (int) DB::table('otp_verification_purposes')->where('code', 'ACCOUNT_DELETION')->value('id');
        }

        $userIdBinary = UuidBinary::toBinary($userUuid);

        DB::table('otp_verifications')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'user_id' => $userIdBinary,
            'purpose_id' => $purposeId,
            'status_id' => DB::table('otp_verification_statuses')->where('code', 'PENDING')->value('id'),
            'target_phone_number' => DB::table('users')->where('id', $userIdBinary)->value('phone_number'),
            'code_hash' => Hash::make($otpCode),
            'failed_attempt_count' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function deleteAccount(
        string $accessToken,
        string $otpCode = '123456',
        ?string $seedCode = null,
    ): TestResponse {
        /** @var TestCase $this */
        $this->postJson('/api/v1/auth/account/request-otp', [], [
            'Authorization' => 'Bearer '.$accessToken,
        ])->assertStatus(200);

        $resolvedSeed = $seedCode ?? $otpCode;
        $claims = app(JwtTokenService::class)->decodeAccessToken($accessToken);
        $userUuid = is_object($claims) ? ($claims->sub ?? null) : null;
        if (! is_string($userUuid) || $userUuid === '') {
            throw new RuntimeException('Could not resolve user UUID from access token.');
        }

        $purposeId = (int) DB::table('otp_verification_purposes')->where('code', 'ACCOUNT_DELETION')->value('id');
        $pendingStatusId = (int) DB::table('otp_verification_statuses')->where('code', 'PENDING')->value('id');
        $otpRow = DB::table('otp_verifications')
            ->where('user_id', UuidBinary::toBinary($userUuid))
            ->where('purpose_id', $purposeId)
            ->where('status_id', $pendingStatusId)
            ->orderByDesc('created_at')
            ->first();

        if ($otpRow === null) {
            $this->seedAccountDeletionOtp($accessToken, $resolvedSeed);
        } else {
            DB::table('otp_verifications')
                ->where('id', $otpRow->id)
                ->update([
                    'code_hash' => Hash::make($resolvedSeed),
                    'failed_attempt_count' => 0,
                    'expires_at' => now()->addMinutes(5),
                    'updated_at' => now(),
                ]);
        }

        return $this->deleteJson('/api/v1/auth/account', [
            'otp_code' => $otpCode,
        ], ['Authorization' => 'Bearer '.$accessToken]);
    }
}
