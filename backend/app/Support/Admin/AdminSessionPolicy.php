<?php

namespace App\Support\Admin;

use App\Models\AuthSession;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Centralized ADMIN_WEB session security policy (BLUE V1 Phase A2.4):
 * absolute session lifetime, idle timeout, and the throttled activity-touch
 * write. Used identically by App\Http\Middleware\AuthenticateAdmin (every
 * Bearer request) and App\Actions\Auth\AdminRefreshTokenAction (refresh), so
 * the two enforcement points can never drift - and by
 * App\Actions\Auth\Concerns\IssuesAdminAuthSession, so a new session's
 * absolute expiry is computed the exact same way.
 *
 * Deliberately never touched by Customer/mobile sessions - AuthenticateCustomer
 * and RefreshTokenAction (Customer) do not use this class at all, and
 * config/admin_session.php is entirely separate from AUTH_SESSION_TTL_DAYS.
 *
 * BOUNDARY CONVENTION: both the absolute-expiry check this codebase already
 * had (`expires_at->lessThanOrEqualTo($now)`) and the new idle-timeout check
 * below treat the exact boundary instant as already expired - "valid while
 * `now` is strictly before the deadline", never "valid up to and including
 * the deadline". Example: `last_used_at` = 10:00, a 20-minute idle timeout
 * means the deadline is 10:20:00 - a request at 10:19:59 is allowed, a
 * request at exactly 10:20:00 or any instant after is rejected.
 */
final class AdminSessionPolicy
{
    public function sessionTtlHours(): int
    {
        return $this->positiveIntOrDefault(config('admin_session.session_ttl_hours'), 12);
    }

    public function idleTimeoutMinutes(): int
    {
        return $this->positiveIntOrDefault(config('admin_session.idle_timeout_minutes'), 20);
    }

    public function activityTouchMinutes(): int
    {
        return $this->positiveIntOrDefault(config('admin_session.activity_touch_minutes'), 5);
    }

    /**
     * The absolute `auth_sessions.expires_at` a brand-new Admin session
     * should be created with. Never used again after creation - refresh
     * never extends it (see AdminRefreshTokenAction, which only ever reads
     * the existing value).
     */
    public function newSessionAbsoluteExpiry(Carbon $now): Carbon
    {
        return $now->copy()->addHours($this->sessionTtlHours());
    }

    /**
     * True if $session has been idle longer than the configured Admin idle
     * timeout, per this class's boundary convention. A session with no
     * recorded activity at all (should not occur - session creation always
     * sets last_used_at) fails closed as idle-expired rather than assuming
     * it is fresh.
     */
    public function isIdleExpired(AuthSession $session, Carbon $now): bool
    {
        if ($session->last_used_at === null) {
            return true;
        }

        $deadline = $session->last_used_at->copy()->addMinutes($this->idleTimeoutMinutes());

        return $now->greaterThanOrEqualTo($deadline);
    }

    /**
     * If $session is currently idle-expired, revokes it - atomically,
     * idempotently (the `revoked_at IS NULL` guard means a second concurrent
     * caller's identical UPDATE simply matches zero rows) - and returns
     * true. Returns false without writing anything if the session is not
     * idle-expired. Once revoked here, the session can never become usable
     * again through any path (Bearer or refresh), even if idle-timeout
     * configuration is later changed or the server clock is adjusted.
     *
     * Callers must still separately check `revoked_at`/absolute `expires_at`
     * themselves (unchanged pre-existing checks) - this method concerns idle
     * timeout only.
     */
    public function enforceIdleTimeout(AuthSession $session, Carbon $now): bool
    {
        if (! $this->isIdleExpired($session, $now)) {
            return false;
        }

        DB::table('auth_sessions')
            ->where('id', UuidBinary::toBinary($session->id))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now, 'updated_at' => $now]);

        return true;
    }

    /**
     * Updates `last_used_at` to $now ONLY if the stored value is already at
     * least activityTouchMinutes() old (or was never set) - throttling the
     * write to roughly once per touch interval instead of once per request.
     * The staleness check and the write happen in one atomic, conditional
     * SQL UPDATE (no prior SELECT, no separate lock): a concurrent request
     * racing the exact same check can only ever move the stored value
     * forward, never backward, since the WHERE clause only matches when the
     * currently-stored value already precedes $now by a full touch
     * interval - the new value is therefore always later than whatever it
     * could possibly be replacing.
     *
     * MUST NOT be called from a token refresh - a silent refresh is
     * deliberately never treated as activity (see AdminRefreshTokenAction,
     * which never calls this method).
     */
    public function touchIfDue(AuthSession $session, Carbon $now): void
    {
        $staleThreshold = $now->copy()->subMinutes($this->activityTouchMinutes());

        DB::table('auth_sessions')
            ->where('id', UuidBinary::toBinary($session->id))
            ->where(function ($query) use ($staleThreshold) {
                $query->whereNull('last_used_at')->orWhere('last_used_at', '<=', $staleThreshold);
            })
            ->update(['last_used_at' => $now, 'updated_at' => $now]);
    }

    private function positiveIntOrDefault(mixed $value, int $default): int
    {
        return is_int($value) && $value > 0 ? $value : $default;
    }
}
