<?php

namespace App\Actions\Auth\Concerns;

use App\Models\User;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * Shared "is this user currently eligible for Admin access?" check (BLUE V1
 * Phase A2.3), re-read fresh from the database every time it is called -
 * never cached or trusted from an earlier step. Used identically by all
 * three stages of the Admin login flow (AdminLoginAction Stage 1,
 * AdminMfaEnrollAction's first-credential bootstrap, and
 * AdminMfaVerifyAction Stage 2's pre-session-issuance re-check), so the
 * exact same eligibility rule - ACTIVE account, at least one currently
 * active ADMIN/SUPER_ADMIN role - can never silently drift between them.
 *
 * Password validation and WebAuthn MFA verification may be separated by
 * minutes; this is deliberately re-run at Stage 2 rather than trusting
 * Stage 1's decision, so a role/account change in between is never missed.
 */
trait ChecksActiveAdminEligibility
{
    private const ADMIN_ROLE_CODES = ['ADMIN', 'SUPER_ADMIN'];

    private const ADMIN_WEB_CLIENT_TYPE_CODE = 'ADMIN_WEB';

    /**
     * @return array<int, string>|null Active Admin role codes, or null if
     *                                 the account is not ACTIVE or holds no currently active
     *                                 ADMIN/SUPER_ADMIN role. The two failure cases are deliberately
     *                                 never distinguished by the caller.
     */
    private function activeAdminRoleCodesFor(User $user): ?array
    {
        $activeAccountStatusId = DB::table('user_account_statuses')->where('code', 'ACTIVE')->value('id');

        if ($user->account_status_id !== $activeAccountStatusId) {
            return null;
        }

        $roleCodes = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', UuidBinary::toBinary($user->id))
            ->whereIn('roles.code', self::ADMIN_ROLE_CODES)
            ->where('roles.is_active', 1)
            ->pluck('roles.code')
            ->all();

        return $roleCodes === [] ? null : $roleCodes;
    }

    private function adminWebClientTypeIsActive(): bool
    {
        $clientType = DB::table('auth_client_types')
            ->where('code', self::ADMIN_WEB_CLIENT_TYPE_CODE)
            ->first();

        return $clientType !== null && (bool) $clientType->is_active;
    }
}
