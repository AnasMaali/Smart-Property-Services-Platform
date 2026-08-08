<?php

namespace App\Services\Auth;

use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class JwtTokenService
{
    /**
     * Issue a signed HS256 access token for a customer session.
     *
     * @return array{token: string, expires_at: CarbonImmutable}
     */
    public function issueAccessToken(string $userUuid, string $sessionUuid, string $role, string $clientType): array
    {
        $secret = config('jwt.secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('AUTH_JWT_SECRET is not configured.');
        }

        $issuedAt = CarbonImmutable::now();
        $expiresAt = $issuedAt->addMinutes((int) config('jwt.access_token_ttl_minutes'));

        $payload = [
            'sub' => $userUuid,
            'sid' => $sessionUuid,
            'role' => $role,
            'client' => $clientType,
            'iat' => $issuedAt->getTimestamp(),
            'nbf' => $issuedAt->getTimestamp(),
            'exp' => $expiresAt->getTimestamp(),
            'jti' => (string) Str::uuid(),
        ];

        return [
            'token' => JWT::encode($payload, $secret, 'HS256'),
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Verify a customer access token's HS256 signature and expiry, and
     * return its decoded claims. Returns null for any malformed, expired,
     * not-yet-valid, or invalid-signature token, or one missing the `sub`
     * or `sid` claims this service relies on - the caller is expected to
     * turn that into a single generic rejection rather than distinguish
     * between these cases.
     */
    public function decodeAccessToken(string $jwt): ?object
    {
        $secret = config('jwt.secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('AUTH_JWT_SECRET is not configured.');
        }

        try {
            $decoded = JWT::decode($jwt, new Key($secret, 'HS256'));
        } catch (Throwable) {
            return null;
        }

        if (! isset($decoded->sub, $decoded->sid) || ! is_string($decoded->sub) || ! is_string($decoded->sid)) {
            return null;
        }

        return $decoded;
    }
}
