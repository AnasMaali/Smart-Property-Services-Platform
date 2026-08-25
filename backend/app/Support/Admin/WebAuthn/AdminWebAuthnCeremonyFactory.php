<?php

namespace App\Support\Admin\WebAuthn;

use Cose\Algorithm\Manager as CoseAlgorithmManager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;
use Cose\Algorithms;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;

/**
 * Builds the web-auth/webauthn-lib primitives shared by registration and
 * assertion verification (BLUE V1 Phase A2.2): the (de)serializer used to
 * turn a browser's raw JSON PublicKeyCredential response into typed
 * objects, and the two ceremony validators.
 *
 * ATTESTATION FORMAT: only "none" attestation is registered/accepted.
 * Registration options request `attestation: 'none'`
 * (App\Support\Admin\WebAuthn\AdminWebAuthnRegistrationService), the
 * spec-recommended, privacy-preserving choice for an RP that does not need
 * to verify authenticator hardware provenance via an attestation trust
 * chain - BLUE's trust model for this credential is "a currently
 * authenticated Admin, on a Tailscale-approved device, freshly registered
 * it," not "this exact authenticator model is on an approved vendor list."
 * Registering only NoneAttestationStatementSupport keeps the dependency
 * surface minimal (no attestation-CA/metadata-service trust anchors to
 * maintain) and matches that choice exactly.
 *
 * USER VERIFICATION: always 'required', set on the options objects by the
 * caller (AdminWebAuthnRegistrationService / AdminWebAuthnAssertionService),
 * never a toggle here - see those classes.
 *
 * ORIGIN VALIDATION: always the operator-configured allowlist
 * (AdminWebAuthnConfig::allowedOrigins()), never derived from a request
 * header.
 *
 * COUNTER POLICY: uses the library's default CounterChecker
 * (Webauthn\Counter\ThrowExceptionIfInvalid). Per WebAuthn §6.1.3 / this
 * library's own CeremonyStep\CheckCounter, a stored counter of 0 with a
 * reported counter of 0 is treated as "this authenticator does not support
 * a counter" and the check is skipped entirely - many resident-key/passkey
 * authenticators legitimately always report 0, and that is never treated as
 * suspicious. Once a real (non-zero) counter has been recorded, any
 * non-increasing value on a later assertion throws Webauthn\Exception\
 * CounterException, which App\Support\Admin\WebAuthn\AdminWebAuthnAssertionService
 * maps to a hard verification failure - a clone-warning signal is never
 * silently ignored.
 */
final class AdminWebAuthnCeremonyFactory
{
    public function __construct(private readonly AdminWebAuthnConfig $config) {}

    public function serializer(): SerializerInterface
    {
        return (new WebauthnSerializerFactory($this->attestationStatementSupportManager()))->create();
    }

    public function attestationValidator(): AuthenticatorAttestationResponseValidator
    {
        return AuthenticatorAttestationResponseValidator::create($this->stepManagerFactory()->creationCeremony());
    }

    public function assertionValidator(): AuthenticatorAssertionResponseValidator
    {
        return AuthenticatorAssertionResponseValidator::create($this->stepManagerFactory()->requestCeremony());
    }

    /**
     * @return list<PublicKeyCredentialParameters>
     */
    public function publicKeyCredentialParameters(): array
    {
        return [
            PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_ES256),
            PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_RS256),
        ];
    }

    public function requiredUserVerificationAuthenticatorSelection(): AuthenticatorSelectionCriteria
    {
        return AuthenticatorSelectionCriteria::create(
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
        );
    }

    public function noneAttestationConveyance(): string
    {
        return PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE;
    }

    private function attestationStatementSupportManager(): AttestationStatementSupportManager
    {
        return new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport,
        ]);
    }

    private function stepManagerFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory;
        $factory->setAttestationStatementSupportManager($this->attestationStatementSupportManager());
        $factory->setAllowedOrigins($this->config->allowedOrigins());
        $factory->setAlgorithmManager(CoseAlgorithmManager::create()->add(ES256::create(), RS256::create()));

        return $factory;
    }
}
