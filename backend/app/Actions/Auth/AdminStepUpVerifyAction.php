<?php

namespace App\Actions\Auth;

use App\Models\AuthSession;
use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminSecurityAuditAction;
use App\Support\Admin\AdminSessionPolicy;
use App\Support\Admin\WebAuthn\AdminWebAuthnAssertionOutcome;
use App\Support\Admin\WebAuthn\AdminWebAuthnAssertionService;
use App\Support\Admin\WebAuthn\AdminWebAuthnChallengePurpose;
use App\Support\Admin\WebAuthn\AdminWebAuthnChallengeService;
use App\Support\Admin\WebAuthn\AdminWebAuthnConfig;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Verifies a WebAuthn STEP_UP assertion for the CURRENT authenticated
 * ADMIN_WEB session.
 *
 * BLUE V1 Phase A2.6 audit behavior:
 *
 * - STEP_UP_VERIFIED is written only after the real WebAuthn assertion
 *   succeeds and the current session's step_up_verified_at is updated.
 * - STEP_UP_FAILED is written for authenticated Step-Up verification
 *   failures using one generic failure reason only.
 *
 * No raw WebAuthn challenge, assertion, credential id, signature, public
 * key, access token, or refresh token is ever placed in admin_audit_logs.
 */
class AdminStepUpVerifyAction
{
    private const GENERIC_FAILURE_MESSAGE = 'This WebAuthn verification could not be completed.';

    private const AUDIT_FAILURE_REASON = 'STEP_UP_VERIFICATION_FAILED';

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
    public function handle(
        Request $request,
        User $user,
        AuthSession $session,
        array $data,
    ): array {
        $sessionIdBinary = UuidBinary::toBinary($session->id);

        $ticketUser = $this->challengeService->resolvePendingTicket(
            $data['step_up_ticket'],
            AdminWebAuthnChallengePurpose::STEP_UP,
            $sessionIdBinary,
        );

        if ($ticketUser === null || $ticketUser->id !== $user->id) {
            return $this->auditedFailure($request, $user, $session);
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
            return $this->auditedFailure($request, $user, $session);
        }

        $now = now();

        return DB::transaction(function () use ($request, $user, $session, $now): array {
            $this->sessionPolicy->markStepUpVerified($session, $now);

            $verifiedUntil = $now
                ->copy()
                ->addMinutes($this->sessionPolicy->stepUpTtlMinutes());

            AdminAuditLogger::record(
                $request,
                $user,
                AdminSecurityAuditAction::STEP_UP_VERIFIED->value,
                'AUTH_SESSION',
                $session->id,
                [
                    'step_up_verified_until' => $verifiedUntil->toIso8601String(),
                ],
            );

            return [
                'success' => true,
                'message' => 'Step-up verification successful.',
                'data' => [
                    'step_up_verified_until' => $verifiedUntil->toIso8601String(),
                ],
            ];
        });
    }

    /**
     * @return array{success: bool, message: string, data: null}
     */
    private function auditedFailure(
        Request $request,
        User $user,
        AuthSession $session,
    ): array {
        AdminAuditLogger::recordFailure(
            $request,
            $user,
            AdminSecurityAuditAction::STEP_UP_FAILED->value,
            'AUTH_SESSION',
            $session->id,
            self::AUDIT_FAILURE_REASON,
        );

        return $this->failure();
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
