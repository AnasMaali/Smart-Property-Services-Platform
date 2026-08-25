<?php

namespace App\Actions\Auth;

use App\Actions\Auth\Concerns\BuildsAdminMfaChallengeResponses;
use App\Actions\Auth\Concerns\ChecksActiveAdminEligibility;
use App\Support\Admin\WebAuthn\AdminWebAuthnAssertionService;
use App\Support\Admin\WebAuthn\AdminWebAuthnChallengePurpose;
use App\Support\Admin\WebAuthn\AdminWebAuthnChallengeService;
use App\Support\Admin\WebAuthn\AdminWebAuthnConfig;
use App\Support\Admin\WebAuthn\AdminWebAuthnRegistrationOutcome;
use App\Support\Admin\WebAuthn\AdminWebAuthnRegistrationService;

/**
 * FIRST-CREDENTIAL BOOTSTRAP (BLUE V1 Phase A2.3) - the one and only
 * password-authenticated WebAuthn credential registration path, reachable
 * only via the short-lived `login_ticket` AdminLoginAction issued for an
 * Admin with zero active credentials (`MFA_ENROLLMENT_REQUIRED`).
 *
 * This is deliberately NOT a general "register a new WebAuthn credential"
 * endpoint. If the caller already holds >=1 active credential by the time
 * this runs (e.g. a stale/replayed enrollment ticket, or a race with a
 * separately-completed enrollment), AdminWebAuthnRegistrationService::verify()
 * itself rejects with STEP_UP_REQUIRED - the exact same rule Phase A2.2
 * already built and tested, reused here for free, never re-implemented.
 * Adding further credentials to an Admin who already has one belongs to a
 * later phase (step-up protected, Phase A2.5+) and has no route here.
 *
 * STRICTER MODEL: a successful registration alone never issues a session.
 * Per the Phase A2.3 architecture ("prefer the stricter model if there is
 * ambiguity: registration -> assertion -> session"), this action responds
 * with a fresh `MFA_REQUIRED` challenge for the credential just registered,
 * exactly like AdminLoginAction would for an Admin who already had one -
 * AdminMfaVerifyAction is what actually creates the session, unconditionally,
 * regardless of how the credential came to exist.
 */
class AdminMfaEnrollAction
{
    use BuildsAdminMfaChallengeResponses;
    use ChecksActiveAdminEligibility;

    private const GENERIC_INVALID_MESSAGE = 'This WebAuthn verification could not be completed.';

    public function __construct(
        private readonly AdminWebAuthnConfig $config,
        private readonly AdminWebAuthnChallengeService $challengeService,
        private readonly AdminWebAuthnRegistrationService $registrationService,
        private readonly AdminWebAuthnAssertionService $assertionService,
    ) {}

    /**
     * @param  array{login_ticket: string, credential: array<string, mixed>}  $data
     * @return array{success: bool, message: string, data: array|null}
     */
    public function handle(array $data): array
    {
        $user = $this->challengeService->resolvePendingTicket($data['login_ticket'], AdminWebAuthnChallengePurpose::REGISTRATION);

        if ($user === null) {
            return $this->failure();
        }

        // Re-check eligibility fresh - the ticket alone proves a valid
        // enrollment challenge was issued, never that the Admin is still
        // eligible right now.
        if ($this->activeAdminRoleCodesFor($user) === null) {
            return $this->failure();
        }

        $rawResponseJson = json_encode($data['credential'], JSON_THROW_ON_ERROR);

        $result = $this->registrationService->verify($user, stepUpVerified: false, rawResponseJson: $rawResponseJson, host: $this->config->rpId());

        if ($result->outcome !== AdminWebAuthnRegistrationOutcome::REGISTERED) {
            return $this->failure();
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
