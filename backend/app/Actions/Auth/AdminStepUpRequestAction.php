<?php

namespace App\Actions\Auth;

use App\Models\AuthSession;
use App\Models\User;
use App\Support\Admin\WebAuthn\AdminWebAuthnAssertionService;
use App\Support\Admin\WebAuthn\AdminWebAuthnChallengePurpose;
use App\Support\Admin\WebAuthn\AdminWebAuthnCredentialRepository;
use App\Support\Admin\WebAuthn\AdminWebAuthnOptionsPresenter;
use App\Support\Uuid\UuidBinary;

/**
 * POST /v1/admin/auth/step-up/request (BLUE V1 Phase A2.5) - issues a
 * STEP_UP WebAuthn assertion challenge for the CURRENT authenticated Admin
 * session. Sits entirely behind `auth.admin`: the caller is already a
 * verified, currently-eligible ADMIN/SUPER_ADMIN with a non-idle session by
 * the time this runs (AuthenticateAdmin re-checks all of that fresh on
 * every request, this one included) - this Action performs no additional
 * account/role eligibility re-check of its own, unlike the pre-authentication
 * login flow (AdminMfaVerifyAction), which must, since nothing has
 * authenticated the caller there yet.
 *
 * Creates no auth_sessions row and issues no access/refresh token - a
 * step-up request never touches session/token state, only a short-lived
 * admin_webauthn_challenges row, bound to this exact session
 * (see AdminWebAuthnChallengeService's SESSION BINDING docblock).
 */
class AdminStepUpRequestAction
{
    private const GENERIC_FAILURE_MESSAGE = 'This WebAuthn verification could not be completed.';

    public function __construct(
        private readonly AdminWebAuthnAssertionService $assertionService,
        private readonly AdminWebAuthnCredentialRepository $credentialRepository,
    ) {}

    /**
     * @return array{success: bool, message: string, data: array|null}
     */
    public function handle(User $user, AuthSession $session): array
    {
        $userIdBinary = UuidBinary::toBinary($user->id);

        if ($this->credentialRepository->activeCount($userIdBinary) === 0) {
            return $this->failure();
        }

        $result = $this->assertionService->options(
            $user,
            AdminWebAuthnChallengePurpose::STEP_UP,
            UuidBinary::toBinary($session->id),
        );

        return [
            'success' => true,
            'message' => 'WebAuthn step-up verification required.',
            'data' => [
                'step_up_ticket' => $result->ticket,
                'webauthn' => AdminWebAuthnOptionsPresenter::presentRequestOptions($result->options),
            ],
        ];
    }

    /**
     * @return array{success: bool, message: string, data: null}
     */
    private function failure(): array
    {
        return [
            'success' => false,
            'message' => self::GENERIC_FAILURE_MESSAGE,
            'data' => null,
        ];
    }
}
