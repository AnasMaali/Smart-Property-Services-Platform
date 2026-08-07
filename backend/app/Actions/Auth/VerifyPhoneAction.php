<?php

namespace App\Actions\Auth;

use App\Models\OtpVerification;
use App\Models\User;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class VerifyPhoneAction
{
    private const GENERIC_INVALID_MESSAGE = 'This verification request is invalid or no longer active.';

    /**
     * Verify a PHONE_VERIFICATION OTP and activate the account atomically.
     *
     * Lock order is fixed as: user row, then referenced OTP row - the same
     * order ResendPhoneOtpAction uses. The user row is always locked first,
     * before any OTP row, so that a verify-phone request and a resend-otp
     * request racing for the same user can only ever serialize on that one
     * shared lock. Locking the OTP row first here (while resend locks the
     * user row first) would let the two actions deadlock on each other:
     * verify holding the OTP lock while waiting for the user lock, and
     * resend holding the user lock while waiting for the OTP lock.
     *
     * @param  array{otp_verification_uuid: string, otp_code: string}  $data
     * @return array{success: bool, message: string, data: array|null}
     */
    public function handle(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $otpId = UuidBinary::toBinary($data['otp_verification_uuid']);

            // Unlocked read, used only to discover which user this flow
            // belongs to so the user row can be locked first. Never trusted
            // for validation decisions - it is re-fetched under lock below.
            $initialOtp = OtpVerification::where('id', $otpId)->first();

            if ($initialOtp === null) {
                return $this->failure(self::GENERIC_INVALID_MESSAGE);
            }

            $user = User::where('id', UuidBinary::toBinary($initialOtp->user_id))
                ->lockForUpdate()
                ->first();

            // Re-fetch and re-validate the referenced OTP now that the user
            // row is locked; the unlocked read above cannot be trusted.
            $otp = OtpVerification::where('id', $otpId)->lockForUpdate()->first();

            $phoneVerificationPurposeId = $this->lookupId('otp_verification_purposes', 'PHONE_VERIFICATION');

            if ($otp === null || $user === null || $otp->user_id !== $user->id || $otp->purpose_id !== $phoneVerificationPurposeId) {
                return $this->failure(self::GENERIC_INVALID_MESSAGE);
            }

            $pendingAccountStatusId = $this->lookupId('user_account_statuses', 'PENDING_VERIFICATION');

            if ($user->account_status_id !== $pendingAccountStatusId) {
                return $this->failure(self::GENERIC_INVALID_MESSAGE);
            }

            $pendingOtpStatusId = $this->lookupId('otp_verification_statuses', 'PENDING');

            if ($otp->status_id !== $pendingOtpStatusId) {
                return $this->failure(self::GENERIC_INVALID_MESSAGE);
            }

            $now = now();

            if ($now->greaterThanOrEqualTo($otp->expires_at)) {
                $otp->status_id = $this->lookupId('otp_verification_statuses', 'EXPIRED');
                $otp->save();

                return $this->failure('This verification code has expired. Please request a new one.');
            }

            $attemptsExceededStatusId = $this->lookupId('otp_verification_statuses', 'ATTEMPTS_EXCEEDED');

            if ($otp->failed_attempt_count >= $otp->max_attempts) {
                if ($otp->status_id !== $attemptsExceededStatusId) {
                    $otp->status_id = $attemptsExceededStatusId;
                    $otp->save();
                }

                return $this->failure('Maximum verification attempts exceeded. Please request a new verification code.');
            }

            if (! Hash::check($data['otp_code'], $otp->code_hash)) {
                $otp->failed_attempt_count++;
                $otp->last_attempt_at = $now;

                if ($otp->failed_attempt_count >= $otp->max_attempts) {
                    $otp->status_id = $attemptsExceededStatusId;
                }

                $otp->save();

                return $this->failure('The verification code you entered is incorrect.');
            }

            $otp->status_id = $this->lookupId('otp_verification_statuses', 'VERIFIED');
            $otp->verified_at = $now;
            $otp->last_attempt_at = $now;
            $otp->save();

            $user->account_status_id = $this->lookupId('user_account_statuses', 'ACTIVE');
            $user->phone_verified_at = $now;
            $user->save();

            return [
                'success' => true,
                'message' => 'Phone number verified successfully.',
                'data' => [
                    'user_uuid' => $user->id,
                    'phone_number' => $user->phone_number,
                    'account_status' => 'ACTIVE',
                    'phone_verified' => true,
                    'phone_verified_at' => $user->phone_verified_at->toIso8601String(),
                ],
            ];
        });
    }

    private function failure(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'data' => null,
        ];
    }

    private function lookupId(string $table, string $code): int
    {
        $id = DB::table($table)->where('code', $code)->value('id');

        if ($id === null) {
            throw new RuntimeException("Missing required reference row: {$table}.code = {$code}");
        }

        return (int) $id;
    }
}
