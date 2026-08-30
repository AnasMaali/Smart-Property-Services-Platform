<?php

namespace App\Support\Admin\WebAuthn;

use RuntimeException;
use Webauthn\PublicKeyCredentialRpEntity;

/**
 * Resolves Admin WebAuthn configuration (config/admin_webauthn.php) into the
 * values the ceremony/challenge services need, failing loudly - never
 * silently - when a security-critical value is unset. Mirrors
 * App\Services\Auth\JwtTokenService's own "throw a RuntimeException at the
 * point of use" convention for AUTH_JWT_SECRET.
 *
 * rp_id and allowed_origins are deliberately never derived from a request
 * (Origin/Host/X-Forwarded-* headers, which are caller-controlled) - only
 * from trusted, operator-set environment configuration. See
 * config/admin_webauthn.php for why this is not the same posture as
 * CORS_ALLOWED_ORIGINS.
 */
final class AdminWebAuthnConfig
{
    public function rpEntity(): PublicKeyCredentialRpEntity
    {
        return PublicKeyCredentialRpEntity::create($this->rpName(), $this->rpId());
    }

    public function rpName(): string
    {
        $name = config('admin_webauthn.rp_name');

        return is_string($name) && $name !== '' ? $name : 'BLUE Admin';
    }

    public function rpId(): string
    {
        $rpId = config('admin_webauthn.rp_id');

        if (! is_string($rpId) || $rpId === '') {
            throw new RuntimeException('ADMIN_WEBAUTHN_RP_ID is not configured.');
        }

        return $rpId;
    }

    /**
     * @return array<int, string>
     */
    public function allowedOrigins(): array
    {
        $origins = config('admin_webauthn.allowed_origins');

        if (! is_array($origins) || $origins === []) {
            throw new RuntimeException('ADMIN_WEBAUTHN_ORIGINS is not configured.');
        }

        return $origins;
    }

    public function challengeTtlSeconds(): int
    {
        $ttl = config('admin_webauthn.challenge_ttl_seconds');

        return is_int($ttl) && $ttl > 0 ? $ttl : 300;
    }
}
