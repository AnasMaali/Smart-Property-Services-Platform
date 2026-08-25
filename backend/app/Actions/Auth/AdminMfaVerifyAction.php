<?php

namespace App\Actions\Auth;

use App\Actions\Auth\Concerns\ChecksActiveAdminEligibility;
use App\Actions\Auth\Concerns\IssuesAdminAuthSession;
use App\Services\Auth\JwtTokenService;
use App\Support\Admin\WebAuthn\AdminWebAuthnAssertionOutcome;
use App\Support\Admin\WebAuthn\AdminWebAuthnAssertionService;
use App\Support\Admin\WebAuthn\AdminWebAuthnChallengePurpose;
use App\Support\Admin\WebAuthn\AdminWebAuthnChallengeService;
use App\Support\Admin\WebAuthn\AdminWebAuthnConfig;

/**
 * STAGE 2 of the two-stage Admin login flow (BLUE V1 Phase A2.3): WebAuthn
 * LOGIN_ASSERTION verification. This is the ONLY place an Admin
 * auth_sessions row / access / refresh token is ever created as a result of
 * a login attempt - see IssuesAdminAuthSession, the exact same production
 * session-issuance mechanism Stage 1 (AdminLoginAction) used to call
 * directly before this phase, now reached only after a successful WebAuthn
 * assertion.
 *
 * RE-CHECK AFTER MFA: password (Stage 1) and this step may be separated by
 * minutes, and the WebAuthn ceremony itself takes real wall-clock time
 * (network round trip + user interaction). Nothing from Stage 1 is trusted
 * here - account/role eligibility is re-read fresh from the database both
 * before AND immediately after the assertion is verified, right before
 * session issuance. A revocation landing in that window is never missed.
 *
 * Every rejection reason - unknown/expired/consumed ticket, wrong
 * credential, revoked credential, wrong origin/RP ID, missing user
 * verification, bad signature, a sign-counter clone signal, or an
 * account/role that is no longer eligible - returns the exact same generic
 * message and creates nothing. There is no code path that issues a session
 * before AdminWebAuthnAssertionOutcome::VERIFIED is confirmed.
 */
class AdminMfaVerifyAction
{
    use ChecksActiveAdminEligibility;
    use IssuesAdminAuthSession;

    private const GENERIC_INVALID_MESSAGE = 'This WebAuthn verification could not be completed.';

    public function __construct(
        private readonly AdminWebAuthnConfig $config,
        private readonly AdminWebAuthnChallengeService $challengeService,
        private readonly AdminWebAuthnAssertionService $assertionService,
        private readonly JwtTokenService $jwtTokenService,
    ) {}

    /**
     * @param  array{
     *     login_ticket: string,
     *     credential: array<string, mixed>,
     *     device_name: ?string,
     *     app_version: ?string,
     *     ip_address: ?string,
     *     user_agent: ?string,
     * }  $data
     * @return array{success: bool, message: string, data: array|null}
     */
    public function handle(array $data): array
    {
        $user = $this->challengeService->resolvePendingTicket($data['login_ticket'], AdminWebAuthnChallengePurpose::LOGIN_ASSERTION);

        if ($user === null) {
            return $this->failure();
        }

        if ($this->activeAdminRoleCodesFor($user) === null) {
            return $this->failure();
        }

        if (! $this->adminWebClientTypeIsActive()) {
            return $this->failure();
        }

        $rawResponseJson = json_encode($data['credential'], JSON_THROW_ON_ERROR);

        $result = $this->assertionService->verify($user, AdminWebAuthnChallengePurpose::LOGIN_ASSERTION, $rawResponseJson, $this->config->rpId());

        if ($result->outcome !== AdminWebAuthnAssertionOutcome::VERIFIED) {
            return $this->failure();
        }

        // Immediately-before-session-issuance re-check - see class docblock.
        $activeAdminRoleCodes = $this->activeAdminRoleCodesFor($user);

        if ($activeAdminRoleCodes === null) {
            return $this->failure();
        }

        $session = $this->issueAdminAuthSession(
            $user,
            $activeAdminRoleCodes,
            $data['device_name'] ?? null,
            $data['app_version'] ?? null,
            $data['ip_address'] ?? null,
            $data['user_agent'] ?? null,
            now(),
        );

        return [
            'success' => true,
            'message' => 'Login successful.',
            'data' => $session,
        ];
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
