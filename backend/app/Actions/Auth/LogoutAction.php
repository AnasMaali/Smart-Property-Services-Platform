<?php

namespace App\Actions\Auth;

use App\Models\AuthSession;
use App\Models\User;
use App\Services\Auth\JwtTokenService;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class LogoutAction
{
    private const GENERIC_INVALID_MESSAGE = 'This session is invalid or has expired.';

    public function __construct(private readonly JwtTokenService $jwtTokenService) {}

    /**
     * Validate the presented access token and revoke the auth_sessions row
     * referenced by its `sid` claim.
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

            $session->revoked_at = $now;
            $session->save();

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
