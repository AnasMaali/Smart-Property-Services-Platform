<?php

namespace App\Actions\Auth;

use App\Models\OtpVerification;
use App\Models\User;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class ResendPhoneNumberChangeOtpAction
{
    private const GENERIC_INVALID_MESSAGE = 'This verification request is invalid or no longer active.';

    private const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Reissue a PHONE_NUMBER_CHANGE OTP for an already-authenticated
     * customer, subject to the same 60-second resend cooldown as every
     * other OTP flow in this codebase.
     *
     * Lock order is fixed as: user row, then the referenced OTP row, then
     * the user's latest PHONE_NUMBER_CHANGE OTP row - mirroring
     * ResendPhoneOtpAction exactly, with the user row locked first because
     * it is already known from the authenticated request rather than
     * discovered through the OTP row.
     *
     * @param  array{otp_verification_uuid: string}  $data
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

            $otpId = UuidBinary::toBinary($data['otp_verification_uuid']);
            $otp = OtpVerification::where('id', $otpId)->lockForUpdate()->first();

            $phoneChangePurposeId = $this->lookupId('otp_verification_purposes', 'PHONE_NUMBER_CHANGE');

            if ($otp === null || $otp->user_id !== $user->id || $otp->purpose_id !== $phoneChangePurposeId) {
                return $this->failure(self::GENERIC_INVALID_MESSAGE);
            }

            // The latest PHONE_NUMBER_CHANGE OTP row for this user is the
            // source of truth for the cooldown and for what to invalidate,
            // since it may differ from the OTP referenced above (e.g. a
            // prior resend already superseded it).
            $latestOtp = OtpVerification::where('user_id', UuidBinary::toBinary($user->id))
                ->where('purpose_id', $phoneChangePurposeId)
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            $resendAvailableAt = $latestOtp->created_at->copy()->addSeconds(self::RESEND_COOLDOWN_SECONDS);
            $now = now();

            if ($now->lessThan($resendAvailableAt)) {
                return $this->failure('Please wait before requesting another verification code.');
            }

            $pendingOtpStatusId = $this->lookupId('otp_verification_statuses', 'PENDING');

            if ($latestOtp->status_id === $pendingOtpStatusId) {
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
            $newOtpUuid = UuidBinary::generate();

            OtpVerification::create([
                'id' => $newOtpUuid,
                'user_id' => $user->id,
                'purpose_id' => $phoneChangePurposeId,
                'status_id' => $pendingOtpStatusId,
                'target_phone_number' => $latestOtp->target_phone_number,
                'code_hash' => Hash::make($otpCode),
                'failed_attempt_count' => 0,
                'max_attempts' => 5,
                'expires_at' => $otpExpiresAt,
            ]);
            unset($otpCode);

            return [
                'success' => true,
                'message' => 'A new verification code has been sent.',
                'data' => [
                    'otp_verification_uuid' => $newOtpUuid,
                    'new_phone_number' => $latestOtp->target_phone_number,
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
