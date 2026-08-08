<?php

namespace App\Actions\Auth;

use App\Actions\Auth\Concerns\RevokesAuthSessions;
use App\Models\OtpVerification;
use App\Models\PasswordResetSession;
use App\Models\User;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class ResetPasswordAction
{
    use RevokesAuthSessions;

    private const GENERIC_INVALID_MESSAGE = 'This password reset link is invalid or has expired.';

    /**
     * Redeem a verified PASSWORD_RESET flow's reset token to set a new
     * password, then revoke every auth_sessions row for that user so the
     * customer must sign in again with the new password.
     *
     * The reset token is located by SHA-256(raw token) first (unlocked),
     * purely to discover which user this flow belongs to so the user row
     * can be locked first - the same "unlocked read, then locked
     * re-validate" pattern used by VerifyPhoneAction/ResendPhoneOtpAction.
     * Lock order is then fixed as: user row, OTP row, password_reset_
     * sessions row, auth_sessions rows - preserving the project-wide
     * USER-before-OTP ordering and extending it exactly as LogoutAllAction
     * extends user-before-session.
     *
     * @param  array{reset_token: string, password: string}  $data
     * @return array{success: bool, message: string, data: null}
     */
    public function handle(array $data): array
    {
        $rawResetToken = hex2bin($data['reset_token']);

        if ($rawResetToken === false || strlen($rawResetToken) !== 32) {
            return $this->failure(self::GENERIC_INVALID_MESSAGE);
        }

        $resetTokenHash = hash('sha256', $rawResetToken, true);

        return DB::transaction(function () use ($resetTokenHash, $data) {
            // Unlocked reads, used only to discover which user this flow
            // belongs to so the user row can be locked first. Never trusted
            // for validation decisions - both rows are re-fetched under
            // lock below.
            $initialSession = PasswordResetSession::where('reset_token_hash', $resetTokenHash)->first();

            if ($initialSession === null) {
                return $this->failure(self::GENERIC_INVALID_MESSAGE);
            }

            $initialOtp = OtpVerification::where('id', UuidBinary::toBinary($initialSession->otp_verification_id))->first();

            if ($initialOtp === null) {
                return $this->failure(self::GENERIC_INVALID_MESSAGE);
            }

            $user = User::where('id', UuidBinary::toBinary($initialOtp->user_id))
                ->lockForUpdate()
                ->first();

            $deactivatedStatusId = $this->lookupId('user_account_statuses', 'DEACTIVATED');

            if ($user === null || $user->account_status_id === $deactivatedStatusId) {
                return $this->failure(self::GENERIC_INVALID_MESSAGE);
            }

            $otp = OtpVerification::where('id', UuidBinary::toBinary($initialOtp->id))
                ->lockForUpdate()
                ->first();

            $passwordResetPurposeId = $this->lookupId('otp_verification_purposes', 'PASSWORD_RESET');
            $verifiedOtpStatusId = $this->lookupId('otp_verification_statuses', 'VERIFIED');

            if ($otp === null
                || $otp->user_id !== $user->id
                || $otp->purpose_id !== $passwordResetPurposeId
                || $otp->status_id !== $verifiedOtpStatusId
            ) {
                return $this->failure(self::GENERIC_INVALID_MESSAGE);
            }

            $session = PasswordResetSession::where('id', UuidBinary::toBinary($initialSession->id))
                ->lockForUpdate()
                ->first();

            $now = now();

            if ($session === null
                || $session->otp_verification_id !== $otp->id
                || $session->used_at !== null
                || $session->revoked_at !== null
                || $session->expires_at->lessThanOrEqualTo($now)
            ) {
                return $this->failure(self::GENERIC_INVALID_MESSAGE);
            }

            $user->password_hash = Hash::make($data['password']);
            $user->save();

            $session->used_at = $now;
            $session->save();

            // SECURITY DECISION: every existing session is revoked so the
            // customer must log in again with the new password.
            $this->revokeOtherSessions(UuidBinary::toBinary($user->id), $now);

            return [
                'success' => true,
                'message' => 'Your password has been reset successfully. Please log in again.',
                'data' => null,
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
