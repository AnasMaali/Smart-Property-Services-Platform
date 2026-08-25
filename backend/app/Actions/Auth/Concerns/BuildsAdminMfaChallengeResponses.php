<?php

namespace App\Actions\Auth\Concerns;

use App\Support\Admin\WebAuthn\AdminWebAuthnAssertionOptionsResult;
use App\Support\Admin\WebAuthn\AdminWebAuthnOptionsPresenter;
use App\Support\Admin\WebAuthn\AdminWebAuthnRegistrationOptionsResult;

/**
 * The two "not yet a session" success response shapes shared by
 * AdminLoginAction (Stage 1) and AdminMfaEnrollAction (first-credential
 * bootstrap) - both can lead to `MFA_REQUIRED` (AdminMfaEnrollAction does,
 * immediately after a successful registration, per the stricter
 * registration -> assertion -> session model), so this trait keeps the
 * exact response shape in one place rather than duplicating it (BLUE V1
 * Phase A2.3).
 */
trait BuildsAdminMfaChallengeResponses
{
    /**
     * @return array{success: bool, message: string, data: array}
     */
    private function enrollmentRequiredResponse(AdminWebAuthnRegistrationOptionsResult $result): array
    {
        return [
            'success' => true,
            'message' => 'WebAuthn credential enrollment required.',
            'data' => [
                'state' => 'MFA_ENROLLMENT_REQUIRED',
                'login_ticket' => $result->ticket,
                'webauthn' => AdminWebAuthnOptionsPresenter::presentCreationOptions($result->options),
            ],
        ];
    }

    /**
     * @return array{success: bool, message: string, data: array}
     */
    private function mfaRequiredResponse(AdminWebAuthnAssertionOptionsResult $result): array
    {
        return [
            'success' => true,
            'message' => 'WebAuthn verification required.',
            'data' => [
                'state' => 'MFA_REQUIRED',
                'login_ticket' => $result->ticket,
                'webauthn' => AdminWebAuthnOptionsPresenter::presentRequestOptions($result->options),
            ],
        ];
    }
}
