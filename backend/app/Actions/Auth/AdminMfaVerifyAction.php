<?php

namespace App\Actions\Auth;

use App\Actions\Auth\Concerns\ChecksActiveAdminEligibility;
use App\Actions\Auth\Concerns\IssuesAdminAuthSession;
use App\Models\User;
use App\Services\Auth\JwtTokenService;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminSecurityAuditAction;
use App\Support\Admin\AdminSessionPolicy;
use App\Support\Admin\WebAuthn\AdminWebAuthnAssertionOutcome;
use App\Support\Admin\WebAuthn\AdminWebAuthnAssertionService;
use App\Support\Admin\WebAuthn\AdminWebAuthnChallengePurpose;
use App\Support\Admin\WebAuthn\AdminWebAuthnChallengeService;
use App\Support\Admin\WebAuthn\AdminWebAuthnConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * STAGE 2 of the two-stage Admin login flow.
 *
 * BLUE V1 Phase A2.6 additionally audits:
 *
 * - ADMIN_LOGIN_SUCCESS only after WebAuthn succeeds and the Admin session
 *   has been created successfully.
 * - ADMIN_LOGIN_MFA_FAILED only after a valid Stage-2 login ticket resolves
 *   to a real Admin user. Unknown/invalid tickets remain unaudited here so
 *   admin_audit_logs never becomes a pre-authentication identity oracle.
 *
 * Session creation and ADMIN_LOGIN_SUCCESS are written inside the same DB
 * transaction. If either fails, both roll back.
 */
class AdminMfaVerifyAction
{
    use ChecksActiveAdminEligibility;
    use IssuesAdminAuthSession;

    private const GENERIC_INVALID_MESSAGE = 'This WebAuthn verification could not be completed.';

    private const MFA_FAILURE_REASON = 'MFA_VERIFICATION_FAILED';

    public function __construct(
        private readonly AdminWebAuthnConfig $config,
        private readonly AdminWebAuthnChallengeService $challengeService,
        private readonly AdminWebAuthnAssertionService $assertionService,
        private readonly JwtTokenService $jwtTokenService,
        private readonly AdminSessionPolicy $sessionPolicy,
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
    public function handle(Request $request, array $data): array
    {
        $user = $this->challengeService->resolvePendingTicket(
            $data['login_ticket'],
            AdminWebAuthnChallengePurpose::LOGIN_ASSERTION
        );

        // No trusted actor can be established from an invalid/unknown ticket,
        // so do not fabricate an admin_audit_logs actor row.
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

        $result = $this->assertionService->verify(
            $user,
            AdminWebAuthnChallengePurpose::LOGIN_ASSERTION,
            $rawResponseJson,
            $this->config->rpId()
        );

        if ($result->outcome !== AdminWebAuthnAssertionOutcome::VERIFIED) {
            return $this->auditedFailure($request, $user);
        }

        // Immediately-before-session-issuance re-check. Nothing from the
        // password stage is trusted after the WebAuthn round trip.
        $activeAdminRoleCodes = $this->activeAdminRoleCodesFor($user);

        if ($activeAdminRoleCodes === null) {
            return $this->failure();
        }

        return DB::transaction(function () use ($request, $user, $activeAdminRoleCodes, $data): array {
            $session = $this->issueAdminAuthSession(
                $user,
                $activeAdminRoleCodes,
                $data['device_name'] ?? null,
                $data['app_version'] ?? null,
                $data['ip_address'] ?? null,
                $data['user_agent'] ?? null,
                now(),
            );

            AdminAuditLogger::record(
                $request,
                $user,
                AdminSecurityAuditAction::ADMIN_LOGIN_SUCCESS->value,
                'AUTH_SESSION',
                $session['session_uuid'],
                [
                    'client_type' => 'ADMIN_WEB',
                    'role' => $session['role'],
                ],
            );

            return [
                'success' => true,
                'message' => 'Login successful.',
                'data' => $session,
            ];
        });
    }

    /**
     * A Stage-2 failure for a user whose valid login ticket already resolved.
     *
     * No WebAuthn rejection details, challenge, credential id, assertion,
     * signature, public key, password, or token material is persisted.
     *
     * @return array{success: bool, message: string, data: null}
     */
    private function auditedFailure(Request $request, User $user): array
    {
        AdminAuditLogger::recordFailure(
            $request,
            $user,
            AdminSecurityAuditAction::ADMIN_LOGIN_MFA_FAILED->value,
            'ADMIN_USER',
            $user->id,
            self::MFA_FAILURE_REASON,
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
            'message' => self::GENERIC_INVALID_MESSAGE,
            'data' => null,
        ];
    }
}
