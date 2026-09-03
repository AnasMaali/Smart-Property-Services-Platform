<?php

namespace App\Actions\Auth;

use App\Models\OtpVerification;
use App\Models\User;
use App\Support\Otp\OtpDeliveryChannel;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Issue (or reissue) an ACCOUNT_DELETION OTP for the authenticated customer.
 * The OTP is delivered to the customer's current phone number.
 *
 * Shared by POST /v1/auth/account/request-otp and
 * POST /v1/auth/account/resend-otp — identical server operation with a
 * resend cooldown, mirroring IssueLoginOtpAction.
 */
class IssueAccountDeletionOtpAction
{
    private const RESEND_COOLDOWN_SECONDS = 60;

    private const GENERIC_INVALID_MESSAGE = 'Your session is no longer valid. Please log in again.';

    public function __construct(private readonly OtpDeliveryChannel $otpDelivery) {}

    /**
     * @return array{success: bool, message: string, data: array<string, mixed>|null}
     */
    public function handle(string $userUuid): array
    {
        $result = DB::transaction(function () use ($userUuid) {
            $user = User::where('id', UuidBinary::toBinary($userUuid))
                ->lockForUpdate()
                ->first();

            $activeAccountStatusId = $this->lookupId('user_account_statuses', 'ACTIVE');

            if ($user === null || $user->account_status_id !== $activeAccountStatusId) {
                return $this->failure(self::GENERIC_INVALID_MESSAGE);
            }

            $purposeId = $this->lookupId('otp_verification_purposes', 'ACCOUNT_DELETION');
            $pendingOtpStatusId = $this->lookupId('otp_verification_statuses', 'PENDING');

            $latestOtp = OtpVerification::where('user_id', UuidBinary::toBinary($user->id))
                ->where('purpose_id', $purposeId)
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            $now = now();

            if ($latestOtp !== null && $latestOtp->status_id === $pendingOtpStatusId) {
                $resendAvailableAt = $latestOtp->created_at->copy()->addSeconds(self::RESEND_COOLDOWN_SECONDS);

                if ($now->lessThan($resendAvailableAt)) {
                    return $this->failure('Please wait before requesting another verification code.');
                }

                $latestOtp->status_id = $this->lookupId('otp_verification_statuses', 'INVALIDATED');
                $latestOtp->invalidated_at = $now;
                $latestOtp->save();
            }

            $otpCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $otpExpiresAt = $now->copy()->addMinutes(5);
            $otpUuid = UuidBinary::generate();

            OtpVerification::create([
                'id' => $otpUuid,
                'user_id' => $user->id,
                'purpose_id' => $purposeId,
                'status_id' => $pendingOtpStatusId,
                'target_phone_number' => $user->phone_number,
                'code_hash' => Hash::make($otpCode),
                'failed_attempt_count' => 0,
                'max_attempts' => 5,
                'expires_at' => $otpExpiresAt,
            ]);

            return [
                'success' => true,
                'message' => 'A verification code has been sent to your phone number.',
                'data' => [
                    'otp_expires_at' => $otpExpiresAt->toIso8601String(),
                    'resend_available_at' => $now->copy()->addSeconds(self::RESEND_COOLDOWN_SECONDS)->toIso8601String(),
                ],
                'otp_raw_code' => $otpCode,
                'otp_phone_number' => $user->phone_number,
                'otp_raw_expires_at' => $otpExpiresAt,
            ];
        });

        if ($result['success']) {
            $this->otpDelivery->deliver(
                'ACCOUNT_DELETION',
                $result['otp_phone_number'],
                $result['otp_raw_code'],
                $result['otp_raw_expires_at']
            );
        }

        unset(
            $result['otp_raw_code'],
            $result['otp_phone_number'],
            $result['otp_raw_expires_at']
        );

        return $result;
    }

    /**
     * @return array{success: bool, message: string, data: null}
     */
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
