<?php

namespace App\Actions\Auth;

use App\Actions\Auth\Concerns\IssuesAuthSession;
use App\Models\OtpVerification;
use App\Models\User;
use App\Services\Auth\JwtTokenService;
use App\Support\Auth\CustomerLoginEligibility;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Verify a LOGIN-purpose OTP and, on success, issue the exact same
 * authenticated CUSTOMER session LoginAction (password) issues, via the
 * shared IssuesAuthSession trait.
 *
 * SECURITY: every rejection branch below - unknown phone number, an
 * account that is no longer eligible to log in, no pending LOGIN OTP, an
 * expired OTP, an attempts-exceeded OTP, or simply a wrong code - returns
 * this exact same message, mirroring VerifyPasswordResetOtpAction exactly
 * for the same reason: any branch-specific message would let a caller
 * distinguish "this phone number belongs to a real, eligible account" from
 * "it doesn't", the same account-enumeration leak the request-otp endpoint
 * already goes out of its way to prevent one step earlier. Internal state
 * transitions (OTP status, failed_attempt_count, last_attempt_at) still
 * happen normally - only the external message is unified.
 *
 * Eligibility (CustomerLoginEligibility) is re-checked here, immediately
 * before session issuance, in addition to whatever IssueLoginOtpAction
 * already checked at issue time - OTP possession is not permanent
 * authorization. The account may have been deactivated, role-changed, or
 * tombstoned in the time between issuing and verifying the OTP.
 *
 * There is no otp_verification_uuid anywhere in this flow (see
 * VerifyLoginOtpRequest) - the relevant OTP is always the caller's own
 * phone number's latest PENDING LOGIN OTP. This makes cross-user replay
 * structurally impossible rather than merely checked: there is no token an
 * attacker who knows Customer A's raw OTP code could ever present against
 * Customer B's phone number to reach Customer B's OTP row.
 *
 * Lock order matches every other OTP action in this codebase: user row
 * first, then that user's latest LOGIN OTP row.
 */
class VerifyLoginOtpAction
{
    use IssuesAuthSession;

    private const GENERIC_INVALID_MESSAGE = 'Invalid or expired verification code.';

    public function __construct(
        private readonly JwtTokenService $jwtTokenService,
        private readonly CustomerLoginEligibility $eligibility,
    ) {}

    /**
     * @param  array{
     *     phone_number: string,
     *     otp_code: string,
     *     client_type: string,
     *     device_name: ?string,
     *     app_version: ?string,
     *     ip_address: ?string,
     *     user_agent: ?string,
     * }  $data
     * @return array{success: bool, message: string, data: array|null}
     */
    public function handle(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = User::where('phone_number', $data['phone_number'])
                ->lockForUpdate()
                ->first();

            if ($user === null || ! $this->eligibility->isEligible($user)) {
                return $this->failure();
            }

            $loginPurposeId = $this->lookupId('otp_verification_purposes', 'LOGIN');

            $otp = OtpVerification::where('user_id', UuidBinary::toBinary($user->id))
                ->where('purpose_id', $loginPurposeId)
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            $pendingOtpStatusId = $this->lookupId('otp_verification_statuses', 'PENDING');

            if ($otp === null || $otp->status_id !== $pendingOtpStatusId) {
                return $this->failure();
            }

            $now = now();

            if ($now->greaterThanOrEqualTo($otp->expires_at)) {
                $otp->status_id = $this->lookupId('otp_verification_statuses', 'EXPIRED');
                $otp->save();

                return $this->failure();
            }

            $attemptsExceededStatusId = $this->lookupId('otp_verification_statuses', 'ATTEMPTS_EXCEEDED');

            if ($otp->failed_attempt_count >= $otp->max_attempts) {
                if ($otp->status_id !== $attemptsExceededStatusId) {
                    $otp->status_id = $attemptsExceededStatusId;
                    $otp->save();
                }

                return $this->failure();
            }

            if (! Hash::check($data['otp_code'], $otp->code_hash)) {
                $otp->failed_attempt_count++;
                $otp->last_attempt_at = $now;

                if ($otp->failed_attempt_count >= $otp->max_attempts) {
                    $otp->status_id = $attemptsExceededStatusId;
                }

                $otp->save();

                return $this->failure();
            }

            $otp->status_id = $this->lookupId('otp_verification_statuses', 'VERIFIED');
            $otp->verified_at = $now;
            $otp->last_attempt_at = $now;
            $otp->save();

            $sessionData = $this->issueAuthSession(
                $user,
                $data['client_type'],
                $data['device_name'] ?? null,
                $data['app_version'] ?? null,
                $data['ip_address'] ?? null,
                $data['user_agent'] ?? null,
                $now,
            );

            return [
                'success' => true,
                'message' => 'Login successful.',
                'data' => $sessionData,
            ];
        });
    }

    private function failure(): array
    {
        return [
            'success' => false,
            'message' => self::GENERIC_INVALID_MESSAGE,
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
