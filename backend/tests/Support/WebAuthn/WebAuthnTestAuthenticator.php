<?php

namespace Tests\Support\WebAuthn;

use CBOR\ByteStringObject;
use CBOR\Encoder;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;
use Webauthn\AuthenticatorData;

/**
 * A minimal, spec-compliant WebAuthn authenticator + browser emulator for
 * tests only (BLUE V1 Phase A2.2). Strictly under tests/ - never referenced
 * by application code, and never a bypass hook: it builds the exact same
 * JSON shape a real browser's `navigator.credentials.create()`/`.get()`
 * would produce, using real ECDSA (P-256/ES256) keys generated via PHP's
 * openssl extension, and the already-installed CBOR encoder
 * (spomky-labs/cbor-php, a transitive dependency of web-auth/webauthn-lib)
 * to build the COSE public key and attestation object - no hand-written
 * cryptography, no fake/short-circuited verification path.
 *
 * App\Support\Admin\WebAuthn\* code under test never knows this class
 * exists; it only ever sees the same raw JSON string a real browser would
 * send to a real endpoint.
 */
final class WebAuthnTestAuthenticator
{
    public readonly string $credentialId;

    private readonly \OpenSSLAsymmetricKey $privateKey;

    private readonly string $publicKeyCoseBytes;

    private readonly string $aaguid;

    private int $signCount;

    public function __construct(?string $credentialId = null, int $initialSignCount = 0, ?string $aaguid = null)
    {
        $this->credentialId = $credentialId ?? random_bytes(32);
        $this->signCount = $initialSignCount;
        $this->aaguid = $aaguid ?? random_bytes(16);

        $keyResource = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($keyResource === false) {
            throw new RuntimeException('Unable to generate a test EC keypair.');
        }

        $this->privateKey = $keyResource;
        $this->publicKeyCoseBytes = $this->buildCosePublicKey($keyResource);
    }

    /**
     * Builds the full top-level PublicKeyCredential JSON a browser would
     * return from navigator.credentials.create(), using "none" attestation.
     */
    public function attestationResponseJson(
        string $rpId,
        string $rawChallenge,
        string $origin,
        bool $userVerified = true,
        bool $userPresent = true,
        bool $backupEligible = false,
        bool $backupState = false,
    ): string {
        $authData = $this->buildAuthenticatorData(
            rpId: $rpId,
            userPresent: $userPresent,
            userVerified: $userVerified,
            backupEligible: $backupEligible,
            backupState: $backupState,
            includeAttestedCredentialData: true,
        );

        $attestationObject = (new Encoder)->encode([
            'fmt' => 'none',
            'attStmt' => [],
            'authData' => ByteStringObject::create($authData),
        ]);

        $clientDataJson = $this->buildClientDataJson('webauthn.create', $rawChallenge, $origin);

        return json_encode([
            'id' => $this->base64Url($this->credentialId),
            'rawId' => $this->base64Url($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->base64Url($clientDataJson),
                'attestationObject' => $this->base64Url($attestationObject),
                'transports' => ['internal'],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Builds the full top-level PublicKeyCredential JSON a browser would
     * return from navigator.credentials.get(). $overrideSignCount lets a
     * test simulate a counter regression (clone signal) independent of
     * this authenticator's own tracked counter.
     */
    public function assertionResponseJson(
        string $rpId,
        string $rawChallenge,
        string $origin,
        string $userHandle,
        bool $userVerified = true,
        bool $userPresent = true,
        bool $backupEligible = false,
        bool $backupState = false,
        ?int $overrideSignCount = null,
        bool $tamperSignature = false,
    ): string {
        $signCount = $overrideSignCount ?? ++$this->signCount;

        $authData = $this->buildAuthenticatorData(
            rpId: $rpId,
            userPresent: $userPresent,
            userVerified: $userVerified,
            backupEligible: $backupEligible,
            backupState: $backupState,
            includeAttestedCredentialData: false,
            signCount: $signCount,
        );

        $clientDataJson = $this->buildClientDataJson('webauthn.get', $rawChallenge, $origin);
        $signature = $this->sign($authData.hash('sha256', $clientDataJson, true));

        if ($tamperSignature) {
            $signature = substr($signature, 0, -1).chr(ord(substr($signature, -1)) ^ 0xFF);
        }

        return json_encode([
            'id' => $this->base64Url($this->credentialId),
            'rawId' => $this->base64Url($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->base64Url($clientDataJson),
                'authenticatorData' => $this->base64Url($authData),
                'signature' => $this->base64Url($signature),
                'userHandle' => $this->base64Url($userHandle),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function buildAuthenticatorData(
        string $rpId,
        bool $userPresent,
        bool $userVerified,
        bool $backupEligible,
        bool $backupState,
        bool $includeAttestedCredentialData,
        ?int $signCount = null,
    ): string {
        $flags = 0;
        $flags |= $userPresent ? AuthenticatorData::FLAG_UP : 0;
        $flags |= $userVerified ? AuthenticatorData::FLAG_UV : 0;
        $flags |= $backupEligible ? AuthenticatorData::FLAG_BE : 0;
        $flags |= $backupState ? AuthenticatorData::FLAG_BS : 0;
        $flags |= $includeAttestedCredentialData ? AuthenticatorData::FLAG_AT : 0;

        $authData = hash('sha256', $rpId, true)
            .chr($flags)
            .pack('N', $signCount ?? $this->signCount);

        if ($includeAttestedCredentialData) {
            $authData .= $this->aaguid
                .pack('n', strlen($this->credentialId))
                .$this->credentialId
                .$this->publicKeyCoseBytes;
        }

        return $authData;
    }

    private function buildClientDataJson(string $type, string $rawChallenge, string $origin): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => $this->base64Url($rawChallenge),
            'origin' => $origin,
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR);
    }

    private function buildCosePublicKey(\OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);

        if ($details === false || ! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('Unable to read EC key details for the test authenticator.');
        }

        // openssl_pkey_get_details() returns each coordinate as the
        // shortest big-endian byte string that represents its value - if
        // the coordinate's high byte happens to be 0x00, that leading byte
        // is silently dropped, occasionally yielding <32 bytes for a P-256
        // coordinate (a well-known OpenSSL quirk). P-256 field elements are
        // always exactly 32 bytes; left-pad with zero bytes to restore the
        // fixed width the COSE encoding (and CheckSignature's key parsing)
        // expects, rather than relying on the 255/256 chance this padding
        // is never actually needed.
        return (new Encoder)->encode([
            1 => 2,   // kty: EC2
            3 => -7,  // alg: ES256
            -1 => 1,  // crv: P-256
            -2 => ByteStringObject::create(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
            -3 => ByteStringObject::create(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
        ]);
    }

    private function sign(string $data): string
    {
        $signature = '';

        if (! openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign test assertion data.');
        }

        return $signature;
    }

    private function base64Url(string $raw): string
    {
        return Base64UrlSafe::encodeUnpadded($raw);
    }
}
