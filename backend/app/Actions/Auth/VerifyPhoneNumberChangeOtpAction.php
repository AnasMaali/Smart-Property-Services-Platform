<?php

namespace App\Actions\Auth;

use App\Actions\Auth\Concerns\RevokesAuthSessions;
use App\Models\OtpVerification;
use App\Models\User;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class VerifyPhoneNumberChangeOtpAction
{
    use RevokesAuthSessions;

    private const GENERIC_INVALID_MESSAGE = 'This verification request is invalid or no longer active.';

    /**
     * Verify a PHONE_NUMBER_CHANGE OTP for an already-authenticated
     * customer and, only on success, update users.phone_number to the
     * verified new number.
     *
     * Lock order is fixed as: user row, then the referenced OTP row - the
     * same order VerifyPhoneAction/ResendPhoneOtpAction use, so this action
     * can never deadlock against them or any other OTP action in this
     * codebase.
     *
     * @param  array{otp_verification_uuid: string, otp_code: string}  $data
     * @return array{success: bool, message: string, data: array|null}
     */
    public function handle(string $userUuid, string $currentSessionUuid, array $data): array
    {
        return DB::transaction(function () use ($userUuid, $currentSessionUuid, $data) {
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

            $newPhoneNumber = $otp->target_phone_number;

            // Defensive re-check: the new number may have been claimed by
            // another account after this OTP was issued. The row is left
            // PENDING so the customer can request a fresh OTP for a
            // different number rather than being stuck on a dead code.
            if ($user->phone_number === $newPhoneNumber) {
                return $this->failure(self::GENERIC_INVALID_MESSAGE);
            }

            $phoneTaken = User::where('phone_number', $newPhoneNumber)
                ->where('id', '!=', $user->id)
                ->exists();

            if ($phoneTaken) {
                return $this->failure('This phone number is no longer available.');
            }

            $otp->status_id = $this->lookupId('otp_verification_statuses', 'VERIFIED');
            $otp->verified_at = $now;
            $otp->last_attempt_at = $now;
            $otp->save();

            $user->phone_number = $newPhoneNumber;
            $user->phone_verified_at = $now;
            $user->save();

            // Every other session is revoked as a security measure; the
            // current session (the one making this request) stays valid.
            $this->revokeOtherSessions(
                UuidBinary::toBinary($user->id),
                $now,
                UuidBinary::toBinary($currentSessionUuid)
            );

            return [
                'success' => true,
                'message' => 'Phone number changed successfully.',
                'data' => [
                    'phone_number' => $user->phone_number,
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
