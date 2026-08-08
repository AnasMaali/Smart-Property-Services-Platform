<?php

namespace App\Services\Auth;

use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Illuminate\Support\Str;
use RuntimeException;

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
}
