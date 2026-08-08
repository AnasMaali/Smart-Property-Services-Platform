<?php

namespace App\Actions\Auth;

use App\Models\OtpVerification;
use App\Models\PasswordResetSession;
use App\Models\User;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class VerifyPasswordResetOtpAction
{
    private const GENERIC_INVALID_MESSAGE = 'This verification request is invalid or no longer active.';

    private const RESET_SESSION_TTL_MINUTES = 15;

    /**
     * Verify a PASSWORD_RESET OTP for the given phone number and, on
     * success, create the password_reset_sessions row that authorizes a
     * subsequent reset-password call.
     *
     * forgot-password never returns an OTP UUID (to avoid revealing account
     * existence), so the relevant OTP is located here the same way
     * ForgotPasswordAction issues it: the user's latest PASSWORD_RESET OTP.
     *
     * Lock order is fixed as: user row, then that OTP row - the same order
     * ForgotPasswordAction uses, so this action can never deadlock against
     * it (or Login/LogoutAll/ResendPhoneOtpAction/VerifyPhoneAction, all of
     * which also lock the user row before any OTP row).
     *
     * @param  array{phone_number: string, otp_code: string}  $data
     * @return array{success: bool, message: string, data: array|null}
     */
    public function handle(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = User::where('phone_number', $data['phone_number'])
                ->lockForUpdate()
                ->first();

            $deactivatedStatusId = $this->lookupId('user_account_statuses', 'DEACTIVATED');

            if ($user === null || $user->account_status_id === $deactivatedStatusId) {
                return $this->failure(self::GENERIC_INVALID_MESSAGE);
            }

            $passwordResetPurposeId = $this->lookupId('otp_verification_purposes', 'PASSWORD_RESET');

            $otp = OtpVerification::where('user_id', UuidBinary::toBinary($user->id))
                ->where('purpose_id', $passwordResetPurposeId)
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            $pendingOtpStatusId = $this->lookupId('otp_verification_statuses', 'PENDING');

            if ($otp === null || $otp->status_id !== $pendingOtpStatusId) {
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

            // SECURITY: the raw reset token below is returned to the client
            // exactly once, in this response. Only its SHA-256 hash is ever
            // persisted (password_reset_sessions.reset_token_hash); it must
            // never be logged.
            $rawResetToken = random_bytes(32);
            $resetSessionUuid = UuidBinary::generate();
            $resetSessionExpiresAt = $now->copy()->addMinutes(self::RESET_SESSION_TTL_MINUTES);

            PasswordResetSession::create([
                'id' => $resetSessionUuid,
                'otp_verification_id' => $otp->id,
                'reset_token_hash' => hash('sha256', $rawResetToken, true),
                'expires_at' => $resetSessionExpiresAt,
            ]);

            return [
                'success' => true,
                'message' => 'Password reset code verified successfully.',
                'data' => [
                    'reset_token' => bin2hex($rawResetToken),
                    'reset_token_expires_at' => $resetSessionExpiresAt->toIso8601String(),
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
