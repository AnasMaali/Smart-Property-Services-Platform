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

class LogoutAction
{
    private const GENERIC_INVALID_MESSAGE = 'This session is invalid or has expired.';

    public function __construct(private readonly JwtTokenService $jwtTokenService) {}

    /**
     * Validate the presented access token and revoke the auth_sessions row
     * referenced by its sid claim.
     *
     * BLUE V1 Phase A2.6:
     * if and only if the resolved session is ADMIN_WEB, the successful
     * revocation and ADMIN_LOGOUT audit row are written in the same
     * transaction. Customer/mobile sessions retain their existing behavior
     * and never produce Admin security-audit rows.
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
            $session = AuthSession::where('id', $sessionId)->lockForUpdate()->first();

            if ($session === null || $session->user_id !== $decoded->sub) {
                return $this->failure();
            }

            $now = now();

            if ($session->revoked_at !== null || $session->expires_at->lessThanOrEqualTo($now)) {
                return $this->failure();
            }

            $user = User::where('id', $userId)->first();

            if ($user === null || $user->account_status_id !== $this->lookupId('user_account_statuses', 'ACTIVE')) {
                return $this->failure();
            }

            $isAdminWeb = (int) $session->client_type_id === $this->lookupId('auth_client_types', 'ADMIN_WEB');

            $session->revoked_at = $now;
            $session->save();

            if ($isAdminWeb) {
                AdminAuditLogger::record(
                    $request,
                    $user,
                    AdminSecurityAuditAction::ADMIN_LOGOUT->value,
                    'AUTH_SESSION',
                    $session->id,
                );
            }

            return $this->success();
        });
    }

    private function success(): array
    {
        return [
            'success' => true,
            'message' => 'Logged out successfully.',
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
