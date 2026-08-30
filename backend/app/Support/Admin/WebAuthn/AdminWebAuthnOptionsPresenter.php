<?php

namespace App\Support\Admin\WebAuthn;

use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Converts web-auth/webauthn-lib option objects into the browser-safe,
 * snake_case JSON shape this API's Admin Auth contract uses (BLUE V1 Phase
 * A2.3) - deliberately hand-built rather than the library's own generic
 * Symfony serializer, since that produces camelCase property names and this
 * API's existing convention is snake_case throughout
 * (`access_token_expires_at`, `session_uuid`, ...).
 *
 * Every binary value (challenge, credential ids, the WebAuthn user handle)
 * is base64url-encoded, matching the exact wire format
 * `navigator.credentials.create()`/`.get()` and their JS `PublicKeyCredential`
 * response objects already use - the frontend never needs to re-encode
 * anything this presenter emits.
 */
final class AdminWebAuthnOptionsPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function presentCreationOptions(PublicKeyCredentialCreationOptions $options): array
    {
        return [
            'rp' => [
                'id' => $options->rp->id,
                'name' => $options->rp->name,
            ],
            'user' => [
                'id' => self::base64Url($options->user->id),
                'name' => $options->user->name,
                'display_name' => $options->user->displayName,
            ],
            'challenge' => self::base64Url($options->challenge),
            'pub_key_cred_params' => array_map(
                static fn ($param): array => ['type' => $param->type, 'alg' => $param->alg],
                $options->pubKeyCredParams,
            ),
            'authenticator_selection' => [
                'user_verification' => $options->authenticatorSelection?->userVerification,
            ],
            'attestation' => $options->attestation,
            'exclude_credentials' => array_map(
                self::presentDescriptor(...),
                $options->excludeCredentials,
            ),
            'timeout' => $options->timeout,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentRequestOptions(PublicKeyCredentialRequestOptions $options): array
    {
        return [
            'rp_id' => $options->rpId,
            'challenge' => self::base64Url($options->challenge),
            'allow_credentials' => array_map(
                self::presentDescriptor(...),
                $options->allowCredentials,
            ),
            'user_verification' => $options->userVerification,
            'timeout' => $options->timeout,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentDescriptor(PublicKeyCredentialDescriptor $descriptor): array
    {
        return [
            'id' => self::base64Url($descriptor->id),
            'type' => $descriptor->type,
            'transports' => $descriptor->transports,
        ];
    }

    private static function base64Url(string $raw): string
    {
        return Base64UrlSafe::encodeUnpadded($raw);
    }
}
