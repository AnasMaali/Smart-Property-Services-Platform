<?php

namespace App\Actions\Auth\Concerns;

use App\Models\AuthSession;
use App\Models\User;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single place an authenticated CUSTOMER session (auth_sessions row +
 * access/refresh token pair) is created, regardless of how the customer
 * proved their identity. LoginAction (password) and VerifyLoginOtpAction
 * (OTP) both use this trait so the session/JWT architecture itself never
 * forks between the two login proofs - only the identity-verification step
 * before it differs.
 *
 * The consuming class must define a `JwtTokenService $jwtTokenService`
 * constructor property and a private `lookupId(string $table, string $code): int`
 * method - every Action in this namespace already does, per existing
 * convention.
 *
 * Callers must already hold the user row's lock (FOR UPDATE) and must have
 * already fully re-validated login eligibility immediately before calling
 * this - this method performs no eligibility checks of its own.
 */
trait IssuesAuthSession
{
    /**
     * @return array{
     *     user_uuid: string,
     *     full_name: ?string,
     *     phone_number: string,
     *     email: string,
     *     role: string,
     *     session_uuid: string,
     *     access_token: string,
     *     access_token_expires_at: string,
     *     refresh_token: string,
     *     session_expires_at: string,
     * }
     */
    private function issueAuthSession(
        User $user,
        string $clientTypeCode,
        ?string $deviceName,
        ?string $appVersion,
        ?string $ipAddress,
        ?string $userAgent,
        Carbon $now,
    ): array {
        $clientTypeId = DB::table('auth_client_types')
            ->where('code', $clientTypeCode)
            ->value('id');

        if ($clientTypeId === null) {
            throw new RuntimeException("Unknown or inactive auth_client_types.code = {$clientTypeCode}");
        }

        $sessionUuid = UuidBinary::generate();
        $rawRefreshToken = random_bytes(32);
        $sessionExpiresAt = $now->copy()->addDays((int) config('jwt.session_ttl_days'));

        // created_at/updated_at are set explicitly to the same $now instant
        // as last_used_at (with $timestamps disabled) for the same reason
        // documented in the original LoginAction: auth_sessions.created_at
        // and last_used_at are datetime(6) columns, but Eloquent's default
        // date format truncates to whole-second precision on write - if
        // created_at were left to Eloquent's auto-timestamp, a request that
        // straddles a second boundary between capturing $now and the INSERT
        // would violate chk_auth_sessions_last_used.
        $session = new AuthSession([
            'id' => $sessionUuid,
            'user_id' => $user->id,
            'client_type_id' => $clientTypeId,
            'refresh_token_hash' => hash('sha256', $rawRefreshToken, true),
            'device_name' => $deviceName,
            'app_version' => $appVersion,
            'ip_address' => $this->packIp($ipAddress),
            'user_agent' => $userAgent,
            'last_used_at' => $now,
            'expires_at' => $sessionExpiresAt,
            'revoked_at' => null,
        ]);
        $session->timestamps = false;
        $session->created_at = $now;
        $session->updated_at = $now;
        $session->save();

        $user->last_login_at = $now;
        $user->save();

        $fullName = DB::table('user_profiles')
            ->where('user_id', UuidBinary::toBinary($user->id))
            ->value('full_name');

        $accessToken = $this->jwtTokenService->issueAccessToken(
            $user->id,
            $sessionUuid,
            'CUSTOMER',
            $clientTypeCode
        );

        return [
            'user_uuid' => $user->id,
            'full_name' => $fullName,
            'phone_number' => $user->phone_number,
            'email' => $user->email,
            'role' => 'CUSTOMER',
            'session_uuid' => $sessionUuid,
            'access_token' => $accessToken['token'],
            'access_token_expires_at' => $accessToken['expires_at']->toIso8601String(),
            'refresh_token' => bin2hex($rawRefreshToken),
            'session_expires_at' => $sessionExpiresAt->toIso8601String(),
        ];
    }

    private function packIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        $packed = inet_pton($ip);

        return $packed === false ? null : $packed;
    }
}
