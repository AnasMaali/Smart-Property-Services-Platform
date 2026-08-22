<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single source of truth for "is this user currently allowed to
 * establish a new CUSTOMER session" - the same three gates LoginAction has
 * always enforced for password login: account_status is ACTIVE, the phone
 * number is verified, and the user holds an active CUSTOMER role.
 *
 * Used by IssueLoginOtpAction (at OTP-issue time) and VerifyLoginOtpAction
 * (re-checked immediately before session issuance, since OTP possession is
 * not permanent authorization - the account may have been deactivated,
 * role-changed, or deleted between issue and verify). LoginAction itself
 * keeps its own inline checks unchanged, to avoid touching a fully-covered,
 * unrelated password-login code path for this OTP-login feature.
 */
class CustomerLoginEligibility
{
    public function isEligible(User $user): bool
    {
        if ($user->account_status_id !== $this->lookupId('user_account_statuses', 'ACTIVE')) {
            return false;
        }

        if ($user->phone_verified_at === null) {
            return false;
        }

        return DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', UuidBinary::toBinary($user->id))
            ->where('roles.code', 'CUSTOMER')
            ->where('roles.is_active', 1)
            ->exists();
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
