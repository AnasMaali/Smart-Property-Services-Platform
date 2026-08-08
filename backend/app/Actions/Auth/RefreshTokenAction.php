<?php

namespace App\Actions\Auth;

use App\Models\AuthSession;
use App\Models\User;
use App\Services\Auth\JwtTokenService;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RefreshTokenAction
{
    private const GENERIC_INVALID_MESSAGE = 'This refresh token is invalid or has expired.';

    public function __construct(private readonly JwtTokenService $jwtTokenService) {}

    /**
     * Validate a raw refresh token, rotate it, and issue a new access token.
     *
     * The auth_sessions row is located by SHA-256(raw token) and locked
     * FOR UPDATE before rotation so that two concurrent requests presenting
     * the same (still valid) raw token cannot both succeed: whichever
     * transaction commits first rotates the hash, and the second - once
     * unblocked - re-reads a row whose hash no longer matches what it
     * looked up, and fails.
     *
     * Every rejection reason below - unknown token, revoked/expired
     * session, non-ACTIVE user, unverified phone, missing/inactive
     * CUSTOMER role, or an inactive/non-mobile client type - returns the
     * exact same generic message so a caller cannot use the response to
     * determine why a given refresh token was rejected.
     *
     * @param  array{refresh_token: string}  $data
     * @return array{success: bool, message: string, data: array|null}
     */
    public function handle(array $data): array
    {
        $rawRefreshToken = hex2bin($data['refresh_token']);

        if ($rawRefreshToken === false || strlen($rawRefreshToken) !== 32) {
            return $this->failure();
        }

        $refreshTokenHash = hash('sha256', $rawRefreshToken, true);

        return DB::transaction(function () use ($refreshTokenHash) {
            $session = AuthSession::where('refresh_token_hash', $refreshTokenHash)
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                return $this->failure();
            }

            $now = now();

            if ($session->revoked_at !== null || $session->expires_at->lessThanOrEqualTo($now)) {
                return $this->failure();
            }

            $user = User::where('id', UuidBinary::toBinary($session->user_id))->first();

            if ($user === null) {
                return $this->failure();
            }

            $activeAccountStatusId = $this->lookupId('user_account_statuses', 'ACTIVE');

            if ($user->account_status_id !== $activeAccountStatusId) {
                return $this->failure();
            }

            if ($user->phone_verified_at === null) {
                return $this->failure();
            }

            $hasActiveCustomerRole = DB::table('user_roles')
                ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->where('user_roles.user_id', UuidBinary::toBinary($user->id))
                ->where('roles.code', 'CUSTOMER')
                ->where('roles.is_active', 1)
                ->exists();

            if (! $hasActiveCustomerRole) {
                return $this->failure();
            }

            $clientType = DB::table('auth_client_types')
                ->where('id', $session->client_type_id)
                ->first();

            if ($clientType === null
                || ! $clientType->is_active
                || ! in_array($clientType->code, ['MOBILE_IOS', 'MOBILE_ANDROID'], true)
            ) {
                return $this->failure();
            }

            $newRawRefreshToken = random_bytes(32);

            $session->refresh_token_hash = hash('sha256', $newRawRefreshToken, true);
            $session->last_used_at = $now;
            $session->save();

            $accessToken = $this->jwtTokenService->issueAccessToken(
                $user->id,
                $session->id,
                'CUSTOMER',
                $clientType->code
            );

            return [
                'success' => true,
                'message' => 'Access token refreshed successfully.',
                'data' => [
                    'access_token' => $accessToken['token'],
                    'access_token_expires_at' => $accessToken['expires_at']->toIso8601String(),
                    'refresh_token' => bin2hex($newRawRefreshToken),
                    'session_uuid' => $session->id,
                    'session_expires_at' => $session->expires_at->toIso8601String(),
                ],
            ];
        });
    }

    private function failure(): array
    {
        return [
            'success' => false,
            'message' => self::GENERIC_INVALID_MESSAGE,
            'data' => null,
        ];
    }

    private function lookupId(string $table, string $code): int
    {
        $id = DB::table($table)->where('code', $code)->value('id');

        if ($id === null) {
            throw new RuntimeException("Missing required reference row: {$table}.code = {$code}");
        }

        return (int) $id;
    }
}
