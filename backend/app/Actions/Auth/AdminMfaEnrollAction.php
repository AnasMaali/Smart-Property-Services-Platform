<?php

namespace App\Actions\Auth;

use App\Actions\Auth\Concerns\BuildsAdminMfaChallengeResponses;
use App\Actions\Auth\Concerns\ChecksActiveAdminEligibility;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminSecurityAuditAction;
use App\Support\Admin\WebAuthn\AdminWebAuthnAssertionService;
use App\Support\Admin\WebAuthn\AdminWebAuthnChallengePurpose;
use App\Support\Admin\WebAuthn\AdminWebAuthnChallengeService;
use App\Support\Admin\WebAuthn\AdminWebAuthnConfig;
use App\Support\Admin\WebAuthn\AdminWebAuthnRegistrationOutcome;
use App\Support\Admin\WebAuthn\AdminWebAuthnRegistrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FIRST-CREDENTIAL WebAuthn bootstrap for Admin login.
 *
 * BLUE V1 Phase A2.6 additionally writes
 * WEBAUTHN_CREDENTIAL_REGISTERED after successful persistence, identifying
 * the credential only by BLUE's internal admin_webauthn_credentials UUID.
 *
 * Registration, its audit row, and issuance of the following LOGIN_ASSERTION
 * challenge are wrapped by one outer transaction. The registration service's
 * own transaction therefore becomes nested inside this transaction; if the
 * audit insert or subsequent challenge issuance fails, the newly-created
 * credential is rolled back as well.
 *
 * Raw credential ids, public keys, attestation data, challenges, signatures,
 * passwords, and tokens are never written to admin_audit_logs.
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
    public function handle(Request $request, array $data): array
    {
        $user = $this->challengeService->resolvePendingTicket(
            $data['login_ticket'],
            AdminWebAuthnChallengePurpose::REGISTRATION
        );

        if ($user === null) {
            return $this->failure();
        }

        if ($this->activeAdminRoleCodesFor($user) === null) {
            return $this->failure();
        }

        $rawResponseJson = json_encode($data['credential'], JSON_THROW_ON_ERROR);

        return DB::transaction(function () use ($request, $user, $rawResponseJson): array {
            $result = $this->registrationService->verify(
                $user,
                stepUpVerified: false,
                rawResponseJson: $rawResponseJson,
                host: $this->config->rpId(),
            );

            if ($result->outcome !== AdminWebAuthnRegistrationOutcome::REGISTERED) {
                return $this->failure();
            }

            if ($result->credentialUuid === null) {
                throw new \RuntimeException(
                    'Successful Admin WebAuthn registration did not return its internal credential UUID.'
                );
            }

            AdminAuditLogger::record(
                $request,
                $user,
                AdminSecurityAuditAction::WEBAUTHN_CREDENTIAL_REGISTERED->value,
                'ADMIN_WEBAUTHN_CREDENTIAL',
                $result->credentialUuid,
            );

            return $this->mfaRequiredResponse(
                $this->assertionService->options(
                    $user,
                    AdminWebAuthnChallengePurpose::LOGIN_ASSERTION
                )
            );
        });
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
