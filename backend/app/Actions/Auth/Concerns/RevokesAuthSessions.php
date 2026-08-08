<?php

namespace App\Actions\Auth\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

trait RevokesAuthSessions
{
    /**
     * Locks every active auth_sessions row for the given user, in
     * deterministic (id-ascending) order, then revokes all of them -
     * excluding $exceptSessionIdBinary when one is given.
     *
     * Callers must lock the user row FOR UPDATE before calling this method,
     * preserving the project-wide USER-before-session lock order (matches
     * LogoutAllAction).
     */
    private function revokeOtherSessions(string $userIdBinary, Carbon $now, ?string $exceptSessionIdBinary = null): void
    {
        $scope = fn () => DB::table('auth_sessions')
            ->where('user_id', $userIdBinary)
            ->when(
                $exceptSessionIdBinary !== null,
                fn ($query) => $query->where('id', '!=', $exceptSessionIdBinary)
            );

        $scope()->orderBy('id')->lockForUpdate()->get(['id']);

        $scope()->whereNull('revoked_at')->update(['revoked_at' => $now]);
    }
}
