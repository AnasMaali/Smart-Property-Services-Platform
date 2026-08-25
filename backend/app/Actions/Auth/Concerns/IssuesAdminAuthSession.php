<?php

namespace App\Actions\Auth\Concerns;

use App\Models\AuthSession;
use App\Models\User;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single place an authenticated ADMIN/SUPER_ADMIN session (auth_sessions
 * row + access/refresh token pair) is created (BLUE V1 Phase A2.3) -
 * mirrors App\Actions\Auth\Concerns\IssuesAuthSession's customer-session
 * pattern exactly, with two Admin-specific differences: the client type is
 * always ADMIN_WEB (there is only one Admin client type in V1), and the
 * `role` JWT claim is resolved from the caller's currently active Admin
 * role codes (SUPER_ADMIN takes priority over ADMIN) rather than a fixed
 * constant.
 *
 * Per the Phase A2.3 non-negotiable rule, this trait is used from exactly
 * one place: App\Actions\Auth\AdminMfaVerifyAction, called only after a
 * successful WebAuthn assertion. Nothing in Stage 1 (password) or the
 * first-credential bootstrap ceremony calls this - see AdminLoginAction /
 * AdminMfaEnrollAction, neither of which ever create a session.
 *
 * The consuming class must define a `JwtTokenService $jwtTokenService`
 * constructor property, per the same convention IssuesAuthSession already
 * establishes. Callers must have already fully re-validated login
 * eligibility (ACTIVE account, active ADMIN/SUPER_ADMIN role, ADMIN_WEB
 * client type) immediately before calling this - this method performs no
 * eligibility checks of its own.
 */
trait IssuesAdminAuthSession
{
    use PacksIpAddress;

    private const CLIENT_TYPE_CODE = 'ADMIN_WEB';

    private const ADMIN_ROLE_PRIORITY = ['SUPER_ADMIN', 'ADMIN'];

    /**
     * @param  array<int, string>  $activeAdminRoleCodes
     * @return array{
     *     user_uuid: string,
     *     full_name: ?string,
     *     phone_number: string,
     *     email: string,
     *     role: string,
     *     roles: array<int, string>,
     *     session_uuid: string,
     *     access_token: string,
     *     access_token_expires_at: string,
     *     refresh_token: string,
     *     session_expires_at: string,
     * }
     */
    private function issueAdminAuthSession(
        User $user,
        array $activeAdminRoleCodes,
        ?string $deviceName,
        ?string $appVersion,
        ?string $ipAddress,
        ?string $userAgent,
        Carbon $now,
    ): array {
        $clientTypeId = DB::table('auth_client_types')
            ->where('code', self::CLIENT_TYPE_CODE)
            ->value('id');

        if ($clientTypeId === null) {
            throw new RuntimeException('Unknown or inactive auth_client_types.code = '.self::CLIENT_TYPE_CODE);
        }

        $sessionUuid = UuidBinary::generate();
        $rawRefreshToken = random_bytes(32);
        $sessionExpiresAt = $now->copy()->addDays((int) config('jwt.session_ttl_days'));

        // See the customer IssuesAuthSession trait for why created_at/
        // updated_at are set explicitly to the same $now instant with
        // $timestamps disabled.
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

        $primaryRole = $this->resolvePrimaryAdminRole($activeAdminRoleCodes);

        $accessToken = $this->jwtTokenService->issueAccessToken(
            $user->id,
            $sessionUuid,
            $primaryRole,
            self::CLIENT_TYPE_CODE
        );

        return [
            'user_uuid' => $user->id,
            'full_name' => $fullName,
            'phone_number' => $user->phone_number,
            'email' => $user->email,
            'role' => $primaryRole,
            'roles' => $activeAdminRoleCodes,
            'session_uuid' => $sessionUuid,
            'access_token' => $accessToken['token'],
            'access_token_expires_at' => $accessToken['expires_at']->toIso8601String(),
            'refresh_token' => bin2hex($rawRefreshToken),
            'session_expires_at' => $sessionExpiresAt->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, string>  $activeAdminRoleCodes
     */
    private function resolvePrimaryAdminRole(array $activeAdminRoleCodes): string
    {
        foreach (self::ADMIN_ROLE_PRIORITY as $roleCode) {
            if (in_array($roleCode, $activeAdminRoleCodes, true)) {
                return $roleCode;
            }
        }

        // Unreachable: callers only ever pass a non-empty subset of
        // self::ADMIN_ROLE_PRIORITY, already re-verified immediately before
        // calling this trait.
        throw new RuntimeException('No Admin role found among active role codes.');
    }
}
