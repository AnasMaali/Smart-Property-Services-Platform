<?php

namespace App\Support\Admin;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Transaction-time authorization guard for security-sensitive Admin
 * mutations.
 *
 * This MUST be called from inside the mutation's existing DB transaction,
 * before any privileged state change is written.
 *
 * Authority is protected with shared row locks:
 *
 *   users
 *     -> roles (ADMIN / SUPER_ADMIN)
 *       -> user_roles
 *
 * Shared locks deliberately allow many legitimate Admin mutations to run
 * concurrently while preventing a concurrent authority change from slipping
 * between authorization and commit:
 *
 * - account deactivation/update needs an exclusive lock on users;
 * - global role deactivation needs an exclusive lock on roles;
 * - role removal needs an exclusive lock on user_roles.
 *
 * Those authority changes therefore serialize against this authorization
 * check without globally serializing normal Admin operations.
 */
final class AdminMutationAuthorizer
{
    private const ADMIN_ROLE_CODES = ['ADMIN', 'SUPER_ADMIN'];

    public function checkBinary(string $actorIdBinary): AdminMutationAuthorizationOutcome
    {
        /*
         * 1) Account authority.
         *
         * A shared lock blocks a concurrent account-status UPDATE while the
         * privileged mutation is still in progress, but permits unrelated
         * reads and other safe concurrent activity.
         */
        $user = DB::table('users')
            ->where('id', $actorIdBinary)
            ->sharedLock()
            ->first(['id', 'account_status_id']);

        if ($user === null) {
            return AdminMutationAuthorizationOutcome::ACTOR_NOT_FOUND;
        }

        $activeStatusId = DB::table('user_account_statuses')
            ->where('code', 'ACTIVE')
            ->value('id');

        if ($activeStatusId === null) {
            throw new RuntimeException(
                'Missing required reference row: user_account_statuses.code = ACTIVE'
            );
        }

        if ((int) $user->account_status_id !== (int) $activeStatusId) {
            return AdminMutationAuthorizationOutcome::ACTOR_NOT_AUTHORIZED;
        }

        /*
         * 2) Role-definition authority.
         *
         * Lock both Admin role definitions in stable primary-key order.
         * sharedLock() allows concurrent Admin operations, but a global
         * roles.is_active change must wait for existing privileged mutations
         * to finish.
         */
        $roles = DB::table('roles')
            ->whereIn('code', self::ADMIN_ROLE_CODES)
            ->orderBy('id')
            ->sharedLock()
            ->get(['id', 'code', 'is_active']);

        if ($roles->count() !== count(self::ADMIN_ROLE_CODES)) {
            throw new RuntimeException(
                'Missing required ADMIN/SUPER_ADMIN role reference row.'
            );
        }

        $roleIds = $roles
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $activeRoleIds = $roles
            ->filter(fn (object $role): bool => (int) $role->is_active === 1)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($activeRoleIds === []) {
            return AdminMutationAuthorizationOutcome::ACTOR_NOT_AUTHORIZED;
        }

        /*
         * 3) User-role membership authority.
         *
         * Lock every ADMIN/SUPER_ADMIN membership belonging to this actor.
         * A concurrent DELETE from user_roles therefore cannot revoke the
         * role halfway through this privileged mutation.
         */
        $memberships = DB::table('user_roles')
            ->where('user_id', $actorIdBinary)
            ->whereIn('role_id', $roleIds)
            ->orderBy('role_id')
            ->sharedLock()
            ->get(['role_id']);

        $hasActiveAdminMembership = $memberships->contains(
            fn (object $membership): bool => in_array(
                (int) $membership->role_id,
                $activeRoleIds,
                true
            )
        );

        if (! $hasActiveAdminMembership) {
            return AdminMutationAuthorizationOutcome::ACTOR_NOT_AUTHORIZED;
        }

        return AdminMutationAuthorizationOutcome::AUTHORIZED;
    }
}
