<?php

namespace App\Actions\Auth;

use App\Models\OtpVerification;
use App\Models\User;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class RequestPhoneNumberChangeAction
{
    private const RESEND_COOLDOWN_SECONDS = 60;

    private const GENERIC_INVALID_MESSAGE = 'Your session is no longer valid. Please log in again.';

    /**
     * Start (or reissue) a PHONE_NUMBER_CHANGE OTP flow for an already-
     * authenticated customer's requested new phone number. users.phone_number
     * is not touched here - only verify-phone-number-change-otp updates it.
     *
     * Lock order is fixed as: user row, then any PHONE_NUMBER_CHANGE OTP
     * row for that user - the same order every other OTP-issuing action in
     * this codebase uses (ForgotPasswordAction, ResendPhoneOtpAction).
     *
     * @param  array{new_phone_number: string}  $data
     * @return array{success: bool, message: string, data: array|null}
     */
    public function handle(string $userUuid, array $data): array
    {
        return DB::transaction(function () use ($userUuid, $data) {
            $user = User::where('id', UuidBinary::toBinary($userUuid))
                ->lockForUpdate()
                ->first();

            $activeAccountStatusId = $this->lookupId('user_account_statuses', 'ACTIVE');

            if ($user === null || $user->account_status_id !== $activeAccountStatusId) {
                return $this->failure(self::GENERIC_INVALID_MESSAGE);
            }

            if ($user->phone_number === $data['new_phone_number']) {
                return $this->failure('The new phone number must be different from your current phone number.');
            }

            $phoneTaken = User::where('phone_number', $data['new_phone_number'])
                ->where('id', '!=', $user->id)
                ->exists();

            if ($phoneTaken) {
                return $this->failure('This phone number is already associated with another account.');
            }

            $phoneChangePurposeId = $this->lookupId('otp_verification_purposes', 'PHONE_NUMBER_CHANGE');
            $pendingOtpStatusId = $this->lookupId('otp_verification_statuses', 'PENDING');

            $latestOtp = OtpVerification::where('user_id', UuidBinary::toBinary($user->id))
                ->where('purpose_id', $phoneChangePurposeId)
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            $now = now();

            if ($latestOtp !== null && $latestOtp->status_id === $pendingOtpStatusId) {
                $resendAvailableAt = $latestOtp->created_at->copy()->addSeconds(self::RESEND_COOLDOWN_SECONDS);

                if ($now->lessThan($resendAvailableAt)) {
                    return $this->failure('Please wait before requesting another phone number change code.');
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
                'purpose_id' => $phoneChangePurposeId,
                'status_id' => $pendingOtpStatusId,
                'target_phone_number' => $data['new_phone_number'],
                'code_hash' => Hash::make($otpCode),
                'failed_attempt_count' => 0,
                'max_attempts' => 5,
                'expires_at' => $otpExpiresAt,
            ]);
            unset($otpCode);

            return [
                'success' => true,
                'message' => 'A verification code has been sent to the new phone number.',
                'data' => [
                    'otp_verification_uuid' => $otpUuid,
                    'new_phone_number' => $data['new_phone_number'],
                    'otp_expires_at' => $otpExpiresAt->toIso8601String(),
                    'resend_available_at' => $now->copy()->addSeconds(self::RESEND_COOLDOWN_SECONDS)->toIso8601String(),
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
