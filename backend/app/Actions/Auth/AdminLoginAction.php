<?php

namespace App\Actions\Auth;

use App\Actions\Auth\Concerns\BuildsAdminMfaChallengeResponses;
use App\Actions\Auth\Concerns\ChecksActiveAdminEligibility;
use App\Models\User;
use App\Support\Admin\WebAuthn\AdminWebAuthnAssertionService;
use App\Support\Admin\WebAuthn\AdminWebAuthnChallengePurpose;
use App\Support\Admin\WebAuthn\AdminWebAuthnCredentialRepository;
use App\Support\Admin\WebAuthn\AdminWebAuthnRegistrationOutcome;
use App\Support\Admin\WebAuthn\AdminWebAuthnRegistrationService;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\Hash;

/**
 * STAGE 1 of the two-stage Admin login flow (BLUE V1 Phase A2.3):
 * phone number + password only. Deliberately never creates an
 * auth_sessions row, never issues an access or refresh token - the
 * non-negotiable rule for this phase is that a correct password alone can
 * never produce a usable Admin session. See AdminMfaVerifyAction for Stage
 * 2 (WebAuthn assertion -> session) and AdminMfaEnrollAction for the
 * first-credential bootstrap path.
 *
 * On success (valid password, ACTIVE account, active ADMIN/SUPER_ADMIN
 * role, active ADMIN_WEB client type) this determines the caller's current
 * WebAuthn credential state and returns exactly one of two product-safe
 * states, each carrying a short-lived, single-use `login_ticket` (an
 * admin_webauthn_challenges row's own uuid - see
 * AdminWebAuthnChallengeIssued) and the WebAuthn options the browser needs:
 *
 * - Zero active credentials -> `MFA_ENROLLMENT_REQUIRED` with WebAuthn
 *   REGISTRATION options (see AdminMfaEnrollAction for what completes it).
 * - >=1 active credential -> `MFA_REQUIRED` with WebAuthn LOGIN_ASSERTION
 *   options (see AdminMfaVerifyAction for what completes it).
 *
 * Every rejection reason - unknown phone number, wrong password,
 * non-ACTIVE account, missing/inactive ADMIN/SUPER_ADMIN role, or an
 * inactive/missing ADMIN_WEB client type - returns the exact same generic
 * message, exactly as before this phase. It is deliberately acceptable
 * (and unavoidable) for the two success states themselves to be
 * distinguishable from each other: that information is only ever reached
 * after a genuine password proof, so it reveals nothing to an attacker who
 * has not already proven the password.
 */
class AdminLoginAction
{
    use BuildsAdminMfaChallengeResponses;
    use ChecksActiveAdminEligibility;

    private const GENERIC_INVALID_MESSAGE = 'The phone number or password you entered is incorrect.';

    public function __construct(
        private readonly AdminWebAuthnRegistrationService $registrationService,
        private readonly AdminWebAuthnAssertionService $assertionService,
        private readonly AdminWebAuthnCredentialRepository $credentialRepository,
    ) {}

    /**
     * @param  array{phone_number: string, password: string}  $data
     * @return array{success: bool, message: string, data: array|null}
     */
    public function handle(array $data): array
    {
        $user = User::where('phone_number', $data['phone_number'])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password_hash)) {
            return $this->failure();
        }

        $activeAdminRoleCodes = $this->activeAdminRoleCodesFor($user);

        if ($activeAdminRoleCodes === null) {
            return $this->failure();
        }

        if (! $this->adminWebClientTypeIsActive()) {
            return $this->failure();
        }

        $activeCredentialCount = $this->credentialRepository->activeCount(UuidBinary::toBinary($user->id));

        if ($activeCredentialCount === 0) {
            $optionsResult = $this->registrationService->options($user, stepUpVerified: false);

            if ($optionsResult->outcome !== AdminWebAuthnRegistrationOutcome::ELIGIBLE) {
                // Unreachable in practice: activeAdminRoleCodesFor() above
                // already proved eligibility fresh, and $activeCredentialCount
                // === 0 already rules out STEP_UP_REQUIRED. Fail generically
                // rather than assume, exactly like every other branch here.
                return $this->failure();
            }

            return $this->enrollmentRequiredResponse($optionsResult);
        }

        return $this->mfaRequiredResponse(
            $this->assertionService->options($user, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION)
        );
    }

    /**
     * @return array{success: bool, message: string, data: null}
     */
    private function failure(): array
    {
        return [
            'success' => false,
            'message' => self::GENERIC_INVALID_MESSAGE,
            'data' => null,
        ];
    }
}
