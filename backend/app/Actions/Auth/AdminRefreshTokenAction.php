<?php

namespace App\Actions\Auth;

use App\Models\AuthSession;
use App\Models\User;
use App\Services\Auth\JwtTokenService;
use App\Support\Admin\AdminSessionPolicy;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdminRefreshTokenAction
{
    private const GENERIC_INVALID_MESSAGE = 'This refresh token is invalid or has expired.';

    private const CLIENT_TYPE_CODE = 'ADMIN_WEB';

    /** Priority order used only to pick the single `role` JWT claim when a user holds more than one Admin role. */
    private const ADMIN_ROLE_PRIORITY = ['SUPER_ADMIN', 'ADMIN'];

    public function __construct(
        private readonly JwtTokenService $jwtTokenService,
        private readonly AdminSessionPolicy $sessionPolicy,
    ) {}

    /**
     * Validate a raw Admin refresh token, rotate it, and issue a new access
     * token. Mirrors RefreshTokenAction's locking/rotation strategy exactly
     * (see that class for the concurrency notes); the differences are the
     * role requirement (ADMIN/SUPER_ADMIN instead of CUSTOMER, re-read
     * fresh from the database on every refresh so a role revoked after
     * login stops working on the very next refresh), the client type
     * requirement (ADMIN_WEB instead of the mobile client types), and (BLUE
     * V1 Phase A2.4) the Admin idle-timeout check below.
     *
     * CRITICAL (Phase A2.4): a silent refresh is deliberately NEVER treated
     * as Admin activity. This method enforces the idle timeout (via the
     * same App\Support\Admin\AdminSessionPolicy App\Http\Middleware\AuthenticateAdmin
     * uses, so the two can never drift) but never calls
     * AdminSessionPolicy::touchIfDue() - unlike the Customer RefreshTokenAction,
     * which does update `last_used_at` on every successful refresh (that
     * Customer-only behavior is deliberately unchanged - see that class).
     * An Admin session already idle-expired at the moment of a refresh
     * attempt is rejected and revoked here exactly as it would be on a
     * Bearer request - refresh can never resurrect it.
     *
     * Every rejection reason - unknown token, revoked/expired/idle-expired
     * session, non-ACTIVE user, missing/inactive ADMIN/SUPER_ADMIN role, or
     * an inactive/non-ADMIN_WEB client type - returns the exact same
     * generic message.
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

            if ($this->sessionPolicy->enforceIdleTimeout($session, $now)) {
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

            $activeAdminRoleCodes = DB::table('user_roles')
                ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->where('user_roles.user_id', UuidBinary::toBinary($user->id))
                ->whereIn('roles.code', self::ADMIN_ROLE_PRIORITY)
                ->where('roles.is_active', 1)
                ->pluck('roles.code')
                ->all();

            if ($activeAdminRoleCodes === []) {
                return $this->failure();
            }

            $clientType = DB::table('auth_client_types')
                ->where('id', $session->client_type_id)
                ->first();

            if ($clientType === null
                || ! $clientType->is_active
                || $clientType->code !== self::CLIENT_TYPE_CODE
            ) {
                return $this->failure();
            }

            $newRawRefreshToken = random_bytes(32);

            // Deliberately does NOT set last_used_at here - see class
            // docblock. A silent refresh must never count as Admin activity.
            // Also deliberately never touches step_up_verified_at (BLUE V1
            // Phase A2.5) - a refresh must never create, reset, or extend a
            // sensitive-operation step-up window; whatever value the row
            // already has (fresh, stale, or NULL) is left completely
            // untouched by rotating the refresh token.
            $session->refresh_token_hash = hash('sha256', $newRawRefreshToken, true);
            $session->save();

            $primaryRole = $this->resolvePrimaryRole($activeAdminRoleCodes);

            $accessToken = $this->jwtTokenService->issueAccessToken(
                $user->id,
                $session->id,
                $primaryRole,
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

    /**
     * @param  array<int, string>  $activeAdminRoleCodes
     */
    private function resolvePrimaryRole(array $activeAdminRoleCodes): string
    {
        foreach (self::ADMIN_ROLE_PRIORITY as $roleCode) {
            if (in_array($roleCode, $activeAdminRoleCodes, true)) {
                return $roleCode;
            }
        }

        // Unreachable: $activeAdminRoleCodes is always a non-empty subset of
        // self::ADMIN_ROLE_PRIORITY at the only call site.
        throw new RuntimeException('No Admin role found among active role codes.');
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
