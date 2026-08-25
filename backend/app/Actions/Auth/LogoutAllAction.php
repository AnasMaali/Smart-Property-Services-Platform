<?php

namespace App\Actions\Auth;

use App\Models\AuthSession;
use App\Models\User;
use App\Services\Auth\JwtTokenService;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminSecurityAuditAction;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class LogoutAllAction
{
    private const GENERIC_INVALID_MESSAGE = 'This session is invalid or has expired.';

    public function __construct(private readonly JwtTokenService $jwtTokenService) {}

    /**
     * Validate the current access token and revoke every auth_sessions row
     * belonging to its user.
     *
     * BLUE V1 Phase A2.6:
     * when the session initiating logout-all is ADMIN_WEB, the revocation
     * set and ADMIN_LOGOUT_ALL audit row are committed atomically.
     * Customer/mobile initiated logout-all remains unaudited by the Admin
     * security audit trail.
     *
     * @return array{success: bool, message: string}
     */
    public function handle(Request $request, ?string $accessToken): array
    {
        if ($accessToken === null || $accessToken === '') {
            return $this->failure();
        }

        $decoded = $this->jwtTokenService->decodeAccessToken($accessToken);

        if ($decoded === null) {
            return $this->failure();
        }

        try {
            $sessionId = UuidBinary::toBinary($decoded->sid);
            $userId = UuidBinary::toBinary($decoded->sub);
        } catch (InvalidArgumentException) {
            return $this->failure();
        }

        return DB::transaction(function () use ($request, $sessionId, $userId, $decoded) {
            $user = User::where('id', $userId)->lockForUpdate()->first();

            if ($user === null) {
                return $this->failure();
            }

            $session = AuthSession::where('id', $sessionId)->lockForUpdate()->first();

            if ($session === null || $session->user_id !== $decoded->sub) {
                return $this->failure();
            }

            $now = now();

            if ($session->revoked_at !== null || $session->expires_at->lessThanOrEqualTo($now)) {
                return $this->failure();
            }

            if ($user->account_status_id !== $this->lookupId('user_account_statuses', 'ACTIVE')) {
                return $this->failure();
            }

            $isAdminWeb = (int) $session->client_type_id === $this->lookupId('auth_client_types', 'ADMIN_WEB');

            DB::table('auth_sessions')
                ->where('user_id', $userId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            $revokedSessions = DB::table('auth_sessions')
                ->where('user_id', $userId)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now]);

            if ($isAdminWeb) {
                AdminAuditLogger::record(
                    $request,
                    $user,
                    AdminSecurityAuditAction::ADMIN_LOGOUT_ALL->value,
                    'ADMIN_USER',
                    $user->id,
                    [
                        'revoked_sessions' => $revokedSessions,
                    ],
                );
            }

            return $this->success();
        });
    }

    private function success(): array
    {
        return [
            'success' => true,
            'message' => 'Logged out from all sessions successfully.',
        ];
    }

    private function failure(): array
    {
        return [
            'success' => false,
            'message' => self::GENERIC_INVALID_MESSAGE,
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
