<?php

namespace App\Actions\Auth;

use App\Models\OtpVerification;
use App\Models\User;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class ForgotPasswordAction
{
    private const RESEND_COOLDOWN_SECONDS = 60;

    private const SAFE_PUBLIC_MESSAGE = 'If an account exists for this phone number, a password reset code has been sent.';

    /**
     * Start (or reissue) a PASSWORD_RESET OTP flow for the given phone
     * number, without ever revealing whether the phone number belongs to
     * an eligible account. Every path - unknown phone number, a
     * DEACTIVATED account, an active resend cooldown, or an OTP actually
     * issued - returns the identical public response.
     *
     * Lock order matches every other OTP action in this codebase: the
     * user row is locked first, then any OTP row for that user. Unlike
     * ResendPhoneOtpAction/VerifyPhoneAction, this flow starts directly
     * from the user's unique phone_number rather than from an OTP UUID,
     * so the user row is locked in a single query instead of an unlocked
     * lookup followed by a locked re-fetch - there is no indirection to
     * resolve first. This still keeps the user row as the first lock
     * acquired, so this action can never deadlock against Login,
     * LogoutAll, ResendPhoneOtpAction, or VerifyPhoneAction, all of which
     * also lock the user row before any OTP/session row.
     *
     * @param  array{phone_number: string}  $data
     * @return array{success: bool, message: string, data: null}
     */
    public function handle(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = User::where('phone_number', $data['phone_number'])
                ->lockForUpdate()
                ->first();

            $deactivatedStatusId = $this->lookupId('user_account_statuses', 'DEACTIVATED');

            if ($user === null || $user->account_status_id === $deactivatedStatusId) {
                return $this->declineWithUniformTiming();
            }

            $passwordResetPurposeId = $this->lookupId('otp_verification_purposes', 'PASSWORD_RESET');
            $pendingOtpStatusId = $this->lookupId('otp_verification_statuses', 'PENDING');

            $latestOtp = OtpVerification::where('user_id', UuidBinary::toBinary($user->id))
                ->where('purpose_id', $passwordResetPurposeId)
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            $now = now();

            if ($latestOtp !== null && $latestOtp->status_id === $pendingOtpStatusId) {
                $resendAvailableAt = $latestOtp->created_at->copy()->addSeconds(self::RESEND_COOLDOWN_SECONDS);

                if ($now->lessThan($resendAvailableAt)) {
                    return $this->declineWithUniformTiming();
                }

                $latestOtp->status_id = $this->lookupId('otp_verification_statuses', 'INVALIDATED');
                $latestOtp->invalidated_at = $now;
                $latestOtp->save();
            }

            // SECURITY (future OTP delivery): the raw code below must never be
            // serialized into a persistent queue payload, cache, or log. A future
            // SMS sender may only receive it in memory, after this transaction
            // commits, then must discard it immediately after dispatch.
            $otpCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $otpExpiresAt = $now->copy()->addMinutes(5);
            $otpUuid = UuidBinary::generate();

            OtpVerification::create([
                'id' => $otpUuid,
                'user_id' => $user->id,
                'purpose_id' => $passwordResetPurposeId,
                'status_id' => $pendingOtpStatusId,
                'target_phone_number' => $user->phone_number,
                'code_hash' => Hash::make($otpCode),
                'failed_attempt_count' => 0,
                'max_attempts' => 5,
                'expires_at' => $otpExpiresAt,
            ]);
            unset($otpCode);

            return $this->safeResponse();
        });
    }

    /**
     * Every non-issuing path (unknown phone, DEACTIVATED account, active
     * cooldown) performs the same bcrypt-cost dummy hash the issuing path
     * spends on the real OTP code, so response timing does not betray
     * which of those cases occurred.
     */
    private function declineWithUniformTiming(): array
    {
        Hash::make((string) random_int(100000, 999999));

        return $this->safeResponse();
    }

    private function safeResponse(): array
    {
        return [
            'success' => true,
            'message' => self::SAFE_PUBLIC_MESSAGE,
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
