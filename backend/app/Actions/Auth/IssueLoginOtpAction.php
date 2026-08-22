<?php

namespace App\Actions\Auth;

use App\Models\OtpVerification;
use App\Models\User;
use App\Support\Auth\CustomerLoginEligibility;
use App\Support\Otp\OtpDeliveryChannel;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Issue (or reissue) a LOGIN-purpose OTP for a phone number, without ever
 * revealing whether that phone number belongs to an eligible account.
 *
 * Shared by both RequestLoginOtpController (/v1/auth/login/request-otp) and
 * ResendLoginOtpController (/v1/auth/login/resend-otp): request-otp never
 * returns an otp_verification_uuid (see VerifyLoginOtpRequest), so a
 * "resend" request has nothing to reference except the same phone_number a
 * fresh request would use - the two endpoints are therefore the identical
 * server operation ("ensure a fresh PENDING LOGIN OTP exists for this
 * phone number, respecting the resend cooldown") exposed under two route
 * names for UI clarity, exactly the way OTP box a "Resend" affordance is a
 * distinct user gesture but not a distinct backend concept. This avoids
 * building a second OTP-issuing implementation for what is otherwise
 * identical logic.
 *
 * Every decline path - unknown phone number, ineligible account (wrong
 * role/status/unverified), or an active resend cooldown - returns the
 * identical public response and spends the same bcrypt-cost dummy hash as
 * the issuing path, so neither response shape nor timing discloses account
 * existence or eligibility. This mirrors ForgotPasswordAction exactly,
 * strengthened with LoginAction's full eligibility gate (ACTIVE status +
 * verified phone + active CUSTOMER role) in place of ForgotPasswordAction's
 * looser "not DEACTIVATED" check, since this OTP directly authenticates a
 * session rather than merely unlocking a password reset.
 *
 * Lock order matches every other OTP action in this codebase: user row
 * first, then the user's latest LOGIN OTP row - so this action can never
 * deadlock against LoginAction, VerifyLoginOtpAction, ForgotPasswordAction,
 * ResendPhoneOtpAction, or VerifyPhoneAction, all of which also lock the
 * user row before any OTP row.
 */
class IssueLoginOtpAction
{
    private const RESEND_COOLDOWN_SECONDS = 60;

    private const SAFE_PUBLIC_MESSAGE = 'If an eligible account exists for this phone number, a login code has been sent.';

    public function __construct(
        private readonly OtpDeliveryChannel $otpDelivery,
        private readonly CustomerLoginEligibility $eligibility,
    ) {}

    /**
     * @param  array{phone_number: string}  $data
     * @return array{success: bool, message: string, data: null}
     */
    public function handle(array $data): array
    {
        $issuedOtp = null;

        $result = DB::transaction(function () use ($data, &$issuedOtp) {
            $user = User::where('phone_number', $data['phone_number'])
                ->lockForUpdate()
                ->first();

            if ($user === null || ! $this->eligibility->isEligible($user)) {
                return $this->declineWithUniformTiming();
            }

            $loginPurposeId = $this->lookupId('otp_verification_purposes', 'LOGIN');
            $pendingOtpStatusId = $this->lookupId('otp_verification_statuses', 'PENDING');

            $latestOtp = OtpVerification::where('user_id', UuidBinary::toBinary($user->id))
                ->where('purpose_id', $loginPurposeId)
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

            // SECURITY: the raw code below must never be serialized into a
            // persistent queue payload, cache, or log. It is delivered only
            // after this transaction commits, then discarded.
            $otpCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $otpExpiresAt = $now->copy()->addMinutes(5);
            $otpUuid = UuidBinary::generate();

            OtpVerification::create([
                'id' => $otpUuid,
                'user_id' => $user->id,
                'purpose_id' => $loginPurposeId,
                'status_id' => $pendingOtpStatusId,
                'target_phone_number' => $user->phone_number,
                'code_hash' => Hash::make($otpCode),
                'failed_attempt_count' => 0,
                'max_attempts' => 5,
                'expires_at' => $otpExpiresAt,
            ]);

            $issuedOtp = [
                'phone_number' => $user->phone_number,
                'raw_code' => $otpCode,
                'expires_at' => $otpExpiresAt,
            ];

            return $this->safeResponse();
        });

        if ($issuedOtp !== null) {
            // Delivered only after the transaction above has committed.
            $this->otpDelivery->deliver('LOGIN', $issuedOtp['phone_number'], $issuedOtp['raw_code'], $issuedOtp['expires_at']);
            $issuedOtp = null;
        }

        return $result;
    }

    /**
     * Every non-issuing path spends the same bcrypt-cost dummy hash the
     * issuing path spends on the real OTP code, so response timing does not
     * betray which case occurred.
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
