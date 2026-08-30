<?php

namespace App\Support\Admin\WebAuthn;

use App\Models\User;
use App\Support\Admin\AdminMutationAuthorizationOutcome;
use App\Support\Admin\AdminMutationAuthorizer;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\Exception\WebauthnException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * WebAuthn credential REGISTRATION foundation (BLUE V1 Phase A2.2).
 *
 * Deliberately not wired into any HTTP route in this phase - see this
 * class's own tests for how it is exercised directly. No endpoint exists
 * that could call this insecurely: the FIRST-CREDENTIAL RULE below is
 * enforced at this service layer regardless of what (future) caller uses
 * it.
 *
 * FIRST-CREDENTIAL RULE (locked, BLUE V1 Phase A2 architecture):
 * - An Admin with ZERO active credentials may register one after password
 *   authentication succeeded (Tailscale already gated network access to
 *   reach this point at all) - $stepUpVerified is not required.
 * - An Admin who already holds >=1 active credential may only register
 *   another if the caller asserts $stepUpVerified = true. No step-up HTTP
 *   ceremony exists yet (Phase A2.5), so no real caller in this phase can
 *   ever legitimately produce that assertion for such an Admin - the rule
 *   is enforced here regardless, so a future caller cannot accidentally
 *   bypass it by forgetting to check first.
 *
 * Every rejection reason (wrong role, wrong/expired/replayed challenge,
 * origin/RP/signature/UV failure) is deliberately collapsed into one of a
 * small number of generic outcomes (AdminWebAuthnRegistrationOutcome) -
 * never a granular per-cause message - matching this codebase's existing
 * anti-oracle convention (AdminLoginAction, AuthenticateAdmin).
 */
final class AdminWebAuthnRegistrationService
{
    public function __construct(
        private readonly AdminWebAuthnConfig $config,
        private readonly AdminWebAuthnCeremonyFactory $ceremonyFactory,
        private readonly AdminWebAuthnChallengeService $challengeService,
        private readonly AdminWebAuthnCredentialRepository $credentialRepository,
        private readonly AdminMutationAuthorizer $mutationAuthorizer,
    ) {}

    public function options(User $actor, bool $stepUpVerified): AdminWebAuthnRegistrationOptionsResult
    {
        return DB::transaction(function () use ($actor, $stepUpVerified): AdminWebAuthnRegistrationOptionsResult {
            $blocked = $this->checkEnrollmentAllowed($actor, $stepUpVerified);

            if ($blocked !== null) {
                return new AdminWebAuthnRegistrationOptionsResult($blocked);
            }

            $issued = $this->challengeService->issue($actor, AdminWebAuthnChallengePurpose::REGISTRATION);

            return new AdminWebAuthnRegistrationOptionsResult(
                AdminWebAuthnRegistrationOutcome::ELIGIBLE,
                $this->buildCreationOptions($actor, $issued->rawChallenge),
                $issued->ticket,
            );
        });
    }

    public function verify(User $actor, bool $stepUpVerified, string $rawResponseJson, string $host, ?string $label = null): AdminWebAuthnRegistrationResult
    {
        return DB::transaction(function () use ($actor, $stepUpVerified, $rawResponseJson, $host, $label): AdminWebAuthnRegistrationResult {
            $blocked = $this->checkEnrollmentAllowed($actor, $stepUpVerified);

            if ($blocked !== null) {
                return new AdminWebAuthnRegistrationResult($blocked);
            }

            try {
                $publicKeyCredential = $this->ceremonyFactory->serializer()->deserialize(
                    $rawResponseJson,
                    PublicKeyCredential::class,
                    'json'
                );
            } catch (Throwable) {
                return new AdminWebAuthnRegistrationResult(AdminWebAuthnRegistrationOutcome::VERIFICATION_FAILED);
            }

            if (! $publicKeyCredential instanceof PublicKeyCredential
                || ! $publicKeyCredential->response instanceof AuthenticatorAttestationResponse) {
                return new AdminWebAuthnRegistrationResult(AdminWebAuthnRegistrationOutcome::VERIFICATION_FAILED);
            }

            $rawChallenge = $publicKeyCredential->response->clientDataJSON->challenge;

            $challengeOutcome = $this->challengeService->consume(
                UuidBinary::toBinary($actor->id),
                AdminWebAuthnChallengePurpose::REGISTRATION,
                $rawChallenge,
            );

            if ($challengeOutcome !== AdminWebAuthnChallengeOutcome::VALID) {
                return new AdminWebAuthnRegistrationResult(AdminWebAuthnRegistrationOutcome::CHALLENGE_INVALID);
            }

            $creationOptions = $this->buildCreationOptions($actor, $rawChallenge);

            try {
                $credentialRecord = $this->attestationValidator()->check(
                    $publicKeyCredential->response,
                    $creationOptions,
                    $host,
                );
            } catch (WebauthnException) {
                return new AdminWebAuthnRegistrationResult(AdminWebAuthnRegistrationOutcome::VERIFICATION_FAILED);
            }

            try {
                $credentialUuid = $this->credentialRepository->store($credentialRecord, $actor, $label);
            } catch (QueryException) {
                return new AdminWebAuthnRegistrationResult(AdminWebAuthnRegistrationOutcome::DUPLICATE_CREDENTIAL);
            }

            return new AdminWebAuthnRegistrationResult(
                AdminWebAuthnRegistrationOutcome::REGISTERED,
                $credentialRecord,
                $credentialUuid,
            );
        });
    }

    private function checkEnrollmentAllowed(User $actor, bool $stepUpVerified): ?AdminWebAuthnRegistrationOutcome
    {
        $authorization = $this->mutationAuthorizer->checkBinary(UuidBinary::toBinary($actor->id));

        if ($authorization !== AdminMutationAuthorizationOutcome::AUTHORIZED) {
            return AdminWebAuthnRegistrationOutcome::ACTOR_NOT_ELIGIBLE;
        }

        $hasExistingCredential = $this->credentialRepository->activeCount(UuidBinary::toBinary($actor->id)) > 0;

        if ($hasExistingCredential && ! $stepUpVerified) {
            return AdminWebAuthnRegistrationOutcome::STEP_UP_REQUIRED;
        }

        return null;
    }

    private function buildCreationOptions(User $actor, string $rawChallenge): PublicKeyCredentialCreationOptions
    {
        $existing = $this->credentialRepository->listActive(UuidBinary::toBinary($actor->id));

        return PublicKeyCredentialCreationOptions::create(
            rp: $this->config->rpEntity(),
            user: $this->userEntity($actor),
            challenge: $rawChallenge,
            pubKeyCredParams: $this->ceremonyFactory->publicKeyCredentialParameters(),
            authenticatorSelection: $this->ceremonyFactory->requiredUserVerificationAuthenticatorSelection(),
            attestation: $this->ceremonyFactory->noneAttestationConveyance(),
            excludeCredentials: array_map(
                fn ($record): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
                    'public-key',
                    $record->publicKeyCredentialId,
                    $record->transports,
                ),
                $existing,
            ),
        );
    }

    private function userEntity(User $actor): PublicKeyCredentialUserEntity
    {
        $fullName = DB::table('user_profiles')->where('user_id', UuidBinary::toBinary($actor->id))->value('full_name');

        return PublicKeyCredentialUserEntity::create(
            name: $actor->phone_number,
            id: UuidBinary::toBinary($actor->id),
            displayName: is_string($fullName) && $fullName !== '' ? $fullName : $actor->phone_number,
        );
    }

    private function attestationValidator(): AuthenticatorAttestationResponseValidator
    {
        return $this->ceremonyFactory->attestationValidator();
    }
}
