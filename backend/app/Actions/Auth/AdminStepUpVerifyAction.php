<?php

namespace App\Actions\Auth;

use App\Models\AuthSession;
use App\Models\User;
use App\Support\Admin\AdminSessionPolicy;
use App\Support\Admin\WebAuthn\AdminWebAuthnAssertionOutcome;
use App\Support\Admin\WebAuthn\AdminWebAuthnAssertionService;
use App\Support\Admin\WebAuthn\AdminWebAuthnChallengePurpose;
use App\Support\Admin\WebAuthn\AdminWebAuthnChallengeService;
use App\Support\Admin\WebAuthn\AdminWebAuthnConfig;
use App\Support\Uuid\UuidBinary;

/**
 * POST /v1/admin/auth/step-up/verify (BLUE V1 Phase A2.5) - verifies a
 * WebAuthn STEP_UP assertion for the CURRENT authenticated Admin session
 * and, on success, marks ONLY that session's auth_sessions.step_up_verified_at
 * as fresh (App\Support\Admin\AdminSessionPolicy::markStepUpVerified()) -
 * never any other session belonging to the same Admin.
 *
 * Sits entirely behind `auth.admin`, exactly like AdminStepUpRequestAction -
 * account/role/session eligibility is already freshly re-checked by
 * AuthenticateAdmin on this exact request, so a deactivated account or a
 * role removed between the request and verify calls is already rejected
 * before this Action ever runs (no separate re-check needed here, unlike
 * the pre-authentication login flow).
 *
 * TWO independent checks must both pass before a session is marked
 * step-up-verified, per the Phase A2.5 SESSION BINDING requirement:
 *   1. `step_up_ticket` must resolve (via AdminWebAuthnChallengeService::
 *      resolvePendingTicket(), filtered by this exact session id) to this
 *      same authenticated Admin - an early, cheap rejection for a
 *      mismatched/wrong-session/unknown/expired/consumed ticket, before any
 *      WebAuthn response is even deserialized.
 *   2. The WebAuthn assertion's own embedded challenge must independently
 *      hash-match a challenge row bound to this exact session
 *      (AdminWebAuthnAssertionService::verify() -> AdminWebAuthnChallengeService::
 *      consume(), also filtered by session id) - the structural guarantee
 *      that actually prevents cross-session reuse regardless of what
 *      `step_up_ticket` value was presented alongside it.
 *
 * Every rejection reason - unknown/wrong-session/expired/consumed ticket,
 * wrong credential, revoked credential, wrong origin/RP ID, missing user
 * verification, bad signature, a sign-counter clone signal - returns the
 * exact same generic message and marks nothing. Never returns 401/revokes
 * the session on failure - see App\Http\Middleware\EnsureAdminStepUpIsFresh's
 * docblock for why a step-up failure is a distinct concept from an
 * authentication failure.
 */
class AdminStepUpVerifyAction
{
    private const GENERIC_FAILURE_MESSAGE = 'This WebAuthn verification could not be completed.';

    public function __construct(
        private readonly AdminWebAuthnConfig $config,
        private readonly AdminWebAuthnChallengeService $challengeService,
        private readonly AdminWebAuthnAssertionService $assertionService,
        private readonly AdminSessionPolicy $sessionPolicy,
    ) {}

    /**
     * @param  array{step_up_ticket: string, credential: array<string, mixed>}  $data
     * @return array{success: bool, message: string, data: array|null}
     */
    public function handle(User $user, AuthSession $session, array $data): array
    {
        $sessionIdBinary = UuidBinary::toBinary($session->id);

        $ticketUser = $this->challengeService->resolvePendingTicket(
            $data['step_up_ticket'],
            AdminWebAuthnChallengePurpose::STEP_UP,
            $sessionIdBinary,
        );

        if ($ticketUser === null || $ticketUser->id !== $user->id) {
            return $this->failure();
        }

        $rawResponseJson = json_encode($data['credential'], JSON_THROW_ON_ERROR);

        $result = $this->assertionService->verify(
            $user,
            AdminWebAuthnChallengePurpose::STEP_UP,
            $rawResponseJson,
            $this->config->rpId(),
            $sessionIdBinary,
        );

        if ($result->outcome !== AdminWebAuthnAssertionOutcome::VERIFIED) {
            return $this->failure();
        }

        $now = now();
        $this->sessionPolicy->markStepUpVerified($session, $now);

        return [
            'success' => true,
            'message' => 'Step-up verification successful.',
            'data' => [
                'step_up_verified_until' => $now->copy()->addMinutes($this->sessionPolicy->stepUpTtlMinutes())->toIso8601String(),
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
