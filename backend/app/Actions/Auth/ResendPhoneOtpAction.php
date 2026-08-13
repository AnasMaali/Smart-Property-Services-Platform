<?php

namespace App\Actions\Auth;

use App\Models\OtpVerification;
use App\Models\User;
use App\Support\Otp\OtpDeliveryChannel;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class ResendPhoneOtpAction
{
    public function __construct(private readonly OtpDeliveryChannel $otpDelivery) {}

    private const GENERIC_INVALID_MESSAGE = 'This verification request is invalid or no longer active.';

    private const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Reissue a PHONE_VERIFICATION OTP for the flow identified by the given
     * OTP verification UUID, subject to a 60-second resend cooldown.
     *
     * Lock order is fixed as: user row, then referenced OTP row, then the
     * user's latest PHONE_VERIFICATION OTP row. The user row is locked
     * first, before any OTP row, because it is the one resource every
     * concurrent resend request for the same user is guaranteed to share
     * regardless of which OTP UUID each request references. Locking OTP
     * rows before the user row would let two requests that reference two
     * different (but both currently valid) OTP rows for the same user
     * acquire their own OTP lock first and then deadlock on each other
     * while both wait for the shared user lock.
     *
     * @param  array{otp_verification_uuid: string}  $data
     * @return array{success: bool, message: string, data: array|null}
     */
    public function handle(array $data): array
    {
        $result = DB::transaction(function () use ($data) {
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

            $pendingAccountStatusId = $this->lookupId('user_account_statuses', 'PENDING_VERIFICATION');

            if ($user === null || $user->account_status_id !== $pendingAccountStatusId || $user->phone_verified_at !== null) {
                return $this->failure('This account no longer requires phone verification.');
            }

            // Re-fetch and re-validate the referenced OTP now that the user
            // row is locked; the unlocked read above cannot be trusted.
            $otp = OtpVerification::where('id', $otpId)->lockForUpdate()->first();

            $phoneVerificationPurposeId = $this->lookupId('otp_verification_purposes', 'PHONE_VERIFICATION');

            if ($otp === null || $otp->user_id !== $user->id || $otp->purpose_id !== $phoneVerificationPurposeId) {
                return $this->failure(self::GENERIC_INVALID_MESSAGE);
            }

            // The latest PHONE_VERIFICATION OTP row for this user is the source
            // of truth for the cooldown and for what to invalidate, since it
            // may differ from the OTP referenced above (e.g. a prior resend
            // already superseded it).
            $latestOtp = OtpVerification::where('user_id', UuidBinary::toBinary($user->id))
                ->where('purpose_id', $phoneVerificationPurposeId)
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
                'purpose_id' => $phoneVerificationPurposeId,
                'status_id' => $pendingOtpStatusId,
                'target_phone_number' => $latestOtp->target_phone_number,
                'code_hash' => Hash::make($otpCode),
                'failed_attempt_count' => 0,
                'max_attempts' => 5,
                'expires_at' => $otpExpiresAt,
            ]);

            return [
                'success' => true,
                'message' => 'A new verification code has been sent.',
                'data' => [
                    'otp_verification_uuid' => $newOtpUuid,
                    'otp_expires_at' => $otpExpiresAt->toIso8601String(),
                    'resend_available_at' => $now->copy()->addSeconds(self::RESEND_COOLDOWN_SECONDS)->toIso8601String(),
                    'phone_number' => $latestOtp->target_phone_number,
                ],
                'otp_raw_code' => $otpCode,
                'otp_raw_expires_at' => $otpExpiresAt,
            ];
        });

        if ($result['success']) {
            $rawOtp = $result['otp_raw_code'];
            $rawOtpExpiresAt = $result['otp_raw_expires_at'];

            // Delivered only after the transaction above has committed.
            $this->otpDelivery->deliver('PHONE_VERIFICATION', $result['data']['phone_number'], $rawOtp, $rawOtpExpiresAt);
            unset($rawOtp);
        }

        unset($result['otp_raw_code'], $result['otp_raw_expires_at']);

        return $result;
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
