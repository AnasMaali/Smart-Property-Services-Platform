<?php

namespace App\Support\Admin\WebAuthn;

use App\Models\User;
use App\Support\Uuid\UuidBinary;
use InvalidArgumentException;
use Throwable;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\Exception\WebauthnException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * WebAuthn ASSERTION verification foundation (BLUE V1 Phase A2.2), reusable
 * for both LOGIN_ASSERTION and STEP_UP (see AdminWebAuthnChallengePurpose) -
 * no duplicated ceremony logic between the two. Deliberately does not issue
 * an auth_sessions row or set any session state - this class only answers
 * "did this exact Admin just prove possession of one of their own active,
 * non-revoked WebAuthn credentials, with user verification, for this
 * purpose". What a caller does with a VERIFIED outcome (create a session,
 * mark a session step-up-verified, ...) belongs to Phase A2.3/A2.5.
 *
 * The target Admin is always known in advance (never a "discoverable
 * credential"/usernameless flow) - both the challenge and the resolved
 * credential are cross-checked against $expectedActor's own binary user id,
 * matching how Phase A2.1's challenge table is itself always bound to one
 * user.
 *
 * SESSION BINDING (BLUE V1 Phase A2.5): both options() and verify() accept
 * an optional $authSessionIdBinary. It is required (an InvalidArgumentException
 * is thrown otherwise - a caller/programmer error, never user input) whenever
 * $purpose is STEP_UP, and forwarded to AdminWebAuthnChallengeService so the
 * issued/consumed challenge row is bound to that exact auth_sessions row -
 * see AdminWebAuthnChallengeService's own docblock for why this is the
 * actual enforcement of "a STEP_UP challenge from session A cannot step up
 * session B". LOGIN_ASSERTION never passes this (no session exists yet at
 * login time), and the two call sites in AdminMfaVerifyAction are
 * unaffected by this parameter's addition.
 */
final class AdminWebAuthnAssertionService
{
    private const ALLOWED_PURPOSES = [
        AdminWebAuthnChallengePurpose::LOGIN_ASSERTION,
        AdminWebAuthnChallengePurpose::STEP_UP,
    ];

    public function __construct(
        private readonly AdminWebAuthnConfig $config,
        private readonly AdminWebAuthnCeremonyFactory $ceremonyFactory,
        private readonly AdminWebAuthnChallengeService $challengeService,
        private readonly AdminWebAuthnCredentialRepository $credentialRepository,
    ) {}

    public function options(User $expectedActor, AdminWebAuthnChallengePurpose $purpose, ?string $authSessionIdBinary = null): AdminWebAuthnAssertionOptionsResult
    {
        $this->assertAllowedPurpose($purpose, $authSessionIdBinary);

        $issued = $this->challengeService->issue($expectedActor, $purpose, $authSessionIdBinary);

        $allowCredentials = array_map(
            fn ($record): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
                'public-key',
                $record->publicKeyCredentialId,
                $record->transports,
            ),
            $this->credentialRepository->listActive(UuidBinary::toBinary($expectedActor->id)),
        );

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: $issued->rawChallenge,
            rpId: $this->config->rpId(),
            allowCredentials: $allowCredentials,
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
        );

        return new AdminWebAuthnAssertionOptionsResult($issued->ticket, $options);
    }

    public function verify(User $expectedActor, AdminWebAuthnChallengePurpose $purpose, string $rawResponseJson, string $host, ?string $authSessionIdBinary = null): AdminWebAuthnAssertionResult
    {
        $this->assertAllowedPurpose($purpose, $authSessionIdBinary);

        try {
            $publicKeyCredential = $this->ceremonyFactory->serializer()->deserialize(
                $rawResponseJson,
                PublicKeyCredential::class,
                'json'
            );
        } catch (Throwable) {
            return new AdminWebAuthnAssertionResult(AdminWebAuthnAssertionOutcome::VERIFICATION_FAILED);
        }

        if (! $publicKeyCredential instanceof PublicKeyCredential
            || ! $publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
            return new AdminWebAuthnAssertionResult(AdminWebAuthnAssertionOutcome::VERIFICATION_FAILED);
        }

        $expectedActorIdBinary = UuidBinary::toBinary($expectedActor->id);
        $rawChallenge = $publicKeyCredential->response->clientDataJSON->challenge;

        $challengeOutcome = $this->challengeService->consume($expectedActorIdBinary, $purpose, $rawChallenge, $authSessionIdBinary);

        if ($challengeOutcome !== AdminWebAuthnChallengeOutcome::VALID) {
            return new AdminWebAuthnAssertionResult(AdminWebAuthnAssertionOutcome::CHALLENGE_INVALID);
        }

        $credentialRecord = $this->credentialRepository->findActiveByCredentialId($publicKeyCredential->rawId);

        if ($credentialRecord === null || ! hash_equals($credentialRecord->userHandle, $expectedActorIdBinary)) {
            return new AdminWebAuthnAssertionResult(AdminWebAuthnAssertionOutcome::CREDENTIAL_NOT_FOUND);
        }

        $requestOptions = PublicKeyCredentialRequestOptions::create(
            challenge: $rawChallenge,
            rpId: $this->config->rpId(),
            allowCredentials: [
                PublicKeyCredentialDescriptor::create('public-key', $credentialRecord->publicKeyCredentialId, $credentialRecord->transports),
            ],
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
        );

        try {
            $verifiedRecord = $this->assertionValidator()->check(
                $credentialRecord,
                $publicKeyCredential->response,
                $requestOptions,
                $host,
                $expectedActorIdBinary,
            );
        } catch (WebauthnException) {
            return new AdminWebAuthnAssertionResult(AdminWebAuthnAssertionOutcome::VERIFICATION_FAILED);
        }

        $this->credentialRepository->updateAfterVerification($verifiedRecord);

        return new AdminWebAuthnAssertionResult(AdminWebAuthnAssertionOutcome::VERIFIED, $verifiedRecord);
    }

    private function assertAllowedPurpose(AdminWebAuthnChallengePurpose $purpose, ?string $authSessionIdBinary): void
    {
        if (! in_array($purpose, self::ALLOWED_PURPOSES, true)) {
            throw new InvalidArgumentException('AdminWebAuthnAssertionService only supports LOGIN_ASSERTION and STEP_UP.');
        }

        if ($purpose === AdminWebAuthnChallengePurpose::STEP_UP && $authSessionIdBinary === null) {
            throw new InvalidArgumentException('STEP_UP purpose requires a bound auth session id.');
        }
    }

    private function assertionValidator(): AuthenticatorAssertionResponseValidator
    {
        return $this->ceremonyFactory->assertionValidator();
    }
}
