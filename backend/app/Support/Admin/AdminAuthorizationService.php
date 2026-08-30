<?php

namespace App\Support\Admin;

use Illuminate\Support\Facades\DB;

/**
 * The single centralized Admin AUTHORIZATION mechanism (BLUE V1 Phase A1).
 *
 * Deliberately separate from AUTHENTICATION (`AuthenticateAdmin` /
 * `auth.admin`): authentication already answered "is this a currently valid
 * Admin-session user, holding at least one active ADMIN/SUPER_ADMIN role?"
 * before this class is ever consulted — see `EnsureAdminHasCapability`,
 * which only runs after `auth.admin` has attached `auth_admin_roles` to the
 * request. Authorization answers a narrower question: "is one of THIS
 * caller's currently active Admin roles granted THIS specific capability?"
 *
 * SUPER_ADMIN override: a caller holding an active SUPER_ADMIN role is
 * authorized for every capability, without an `admin_role_permissions` row
 * ever needing to exist for it. This is intentional and explicit — a single,
 * named branch in one place, never a per-controller/per-Action
 * `if ($role === 'SUPER_ADMIN')` special case — so that granting SUPER_ADMIN
 * a brand-new future capability is never a step a future module can forget.
 * The override bypasses permission ASSIGNMENT only. It never bypasses
 * authentication, account/session/role validity (already re-checked by
 * `auth.admin` before this class runs, and again at transaction time by
 * `AdminMutationAuthorizer` for privileged mutations), request validation,
 * domain transition rules, or any database constraint.
 *
 * Fails closed: an empty role list, an unknown/unseeded capability code, a
 * deactivated capability row, a deactivated role, or an active role holding
 * no matching grant all result in `false` — never an exception, never an
 * implicit allow.
 *
 * No audit row is written by this class. Authorization *denials* are a
 * normal, expected, high-frequency outcome of everyday API usage, not a
 * security event in their own right — writing one to `admin_audit_logs` per
 * checked-and-rejected request would make that table noisy and would not
 * match its existing semantics (BLUE V1 Phase 9B: one row per successful,
 * state-changing privileged mutation). The privileged mutation an Admin
 * successfully performs after passing this check is still audited exactly as
 * before, by the domain Action that performs it.
 */
final class AdminAuthorizationService
{
    private const SUPER_ADMIN_ROLE_CODE = 'SUPER_ADMIN';

    /**
     * @param  array<int, string>  $activeAdminRoleCodes  The caller's currently active ADMIN/SUPER_ADMIN role codes, as already resolved by AuthenticateAdmin (`$request->attributes->get('auth_admin_roles')`). Never re-derived from a JWT claim.
     */
    public function authorize(array $activeAdminRoleCodes, AdminCapability|string $capability): bool
    {
        if ($activeAdminRoleCodes === []) {
            return false;
        }

        if (in_array(self::SUPER_ADMIN_ROLE_CODE, $activeAdminRoleCodes, true)) {
            return true;
        }

        $capabilityCode = $capability instanceof AdminCapability ? $capability->value : $capability;

        return DB::table('admin_role_permissions')
            ->join('roles', 'roles.id', '=', 'admin_role_permissions.role_id')
            ->join('admin_permissions', 'admin_permissions.id', '=', 'admin_role_permissions.permission_id')
            ->whereIn('roles.code', $activeAdminRoleCodes)
            ->where('roles.is_active', 1)
            ->where('admin_permissions.code', $capabilityCode)
            ->where('admin_permissions.is_active', 1)
            ->exists();
    }
}
