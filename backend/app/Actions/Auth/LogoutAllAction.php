<?php

namespace App\Actions\Auth;

use App\Models\AuthSession;
use App\Models\User;
use App\Services\Auth\JwtTokenService;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class LogoutAllAction
{
    private const GENERIC_INVALID_MESSAGE = 'This session is invalid or has expired.';

    public function __construct(private readonly JwtTokenService $jwtTokenService) {}

    /**
     * Validate the presented access token and revoke every auth_sessions
     * row belonging to its `sub` user, including the current session.
     *
     * Every rejection reason below - missing/malformed token, invalid
     * signature, expired token, unknown session, session/user mismatch,
     * already-revoked session, expired session, or non-ACTIVE user -
     * returns the exact same generic message so a caller cannot use the
     * response to determine why a given access token was rejected.
     *
     * @return array{success: bool, message: string}
     */
    public function handle(?string $accessToken): array
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

        return DB::transaction(function () use ($sessionId, $userId, $decoded) {
            // Lock the user row first so logout-all serializes with a
            // concurrent Login for the same user: Login also locks the
            // user row before inserting its new auth_sessions row, so
            // whichever transaction commits first is guaranteed to be
            // visible to the other - a session created by a Login that
            // committed first will be seen and revoked below, and a Login
            // that starts after logout-all commits creates its session
            // unaffected by this now-finished revocation.
            $user = User::where('id', $userId)->lockForUpdate()->first();

            if ($user === null) {
                return $this->failure();
            }

            // Re-fetch and revalidate the current session now that the
            // user row is locked, mirroring the Step 1.6 Logout checks.
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

            // Lock every session row belonging to this user, in
            // deterministic (id-ascending) order, before revoking any of
            // them - including the current session and MOBILE_IOS /
            // MOBILE_ANDROID sessions alike.
            DB::table('auth_sessions')
                ->where('user_id', $userId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            DB::table('auth_sessions')
                ->where('user_id', $userId)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now]);

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
