<?php

namespace App\Actions\Admin\Support;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminSupportRequestPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Assigns (or reassigns) the Admin operator responsible for a Support
 * Request (POST /v1/admin/support-requests/{supportRequest}/assign-admin,
 * `support.manage`) - the explicit `AdminAssignSupportRequestAction` that
 * App\Actions\Admin\Support\AdminSendSupportMessageAction's docblock and
 * docs/api-contracts/admin-operations-v1.md "Support" both named as the
 * future home for this.
 *
 * `support_requests.assigned_admin_user_id` is a single nullable FK column
 * (database/blue_v1_schema.sql) - not a join table with its own
 * release/history rows like `technician_assignments` - so, unlike
 * App\Actions\Admin\Technician\AdminAssignTechnicianAction /
 * AdminReassignTechnicianAction, one action covers both the initial
 * assignment and reassigning to a different Admin: both are simply "set
 * this column to a new value", and every prior value is already
 * recoverable from `admin_audit_logs` (see the `old_values`/`new_values`
 * written below) without a dedicated history table.
 *
 * The target must be a real user currently holding an active ADMIN or
 * SUPER_ADMIN role - exactly the same eligibility check
 * App\Support\Admin\AdminSupportRequestPresenter already uses to classify a
 * message sender as `ADMIN`. Already-assigned-to-this-exact-admin is a
 * safe, idempotent 200 (no write, no audit row). Row-locked so a
 * concurrent assign/unassign/status change on the same request can never
 * race.
 */
final class AdminAssignSupportRequestAction
{
    use BuildsCartResult;

    private const ADMIN_ROLE_CODES = ['ADMIN', 'SUPER_ADMIN'];

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request, string $supportRequestUuid, User $actor, string $targetAdminUuid): array
    {
        try {
            $supportRequestIdBinary = UuidBinary::toBinary($supportRequestUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Support request not found.');
        }

        $targetAdminIdBinary = UuidBinary::toBinary($targetAdminUuid);

        return DB::transaction(function () use ($request, $supportRequestUuid, $supportRequestIdBinary, $actor, $targetAdminUuid, $targetAdminIdBinary): array {
            $supportRequest = DB::table('support_requests')
                ->where('id', $supportRequestIdBinary)
                ->lockForUpdate()
                ->first();

            if ($supportRequest === null) {
                return $this->notFound('Support request not found.');
            }

            $targetAdmin = DB::table('users')
                ->join('user_roles', 'user_roles.user_id', '=', 'users.id')
                ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->where('users.id', $targetAdminIdBinary)
                ->whereIn('roles.code', self::ADMIN_ROLE_CODES)
                ->where('roles.is_active', 1)
                ->first(['users.id']);

            if ($targetAdmin === null) {
                return $this->unprocessable('The given data was invalid.', ['admin_uuid' => ['This user is not an active Admin and cannot be assigned.']]);
            }

            if ($supportRequest->assigned_admin_user_id === $targetAdminIdBinary) {
                return $this->ok(200, 'This admin is already assigned to this support request.', ['support_request' => AdminSupportRequestPresenter::detail($this->reload($supportRequestIdBinary))]);
            }

            $previousAdminUuid = $supportRequest->assigned_admin_user_id === null ? null : UuidBinary::toString($supportRequest->assigned_admin_user_id);

            DB::table('support_requests')->where('id', $supportRequestIdBinary)->update([
                'assigned_admin_user_id' => $targetAdminIdBinary,
                'updated_at' => now(),
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                $previousAdminUuid === null ? 'SUPPORT_REQUEST_ASSIGNED' : 'SUPPORT_REQUEST_REASSIGNED',
                'SUPPORT_REQUEST',
                $supportRequestUuid,
                ['admin_uuid' => $targetAdminUuid],
                ['admin_uuid' => $previousAdminUuid],
            );

            $message = $previousAdminUuid === null ? 'Support request assigned successfully.' : 'Support request reassigned successfully.';

            return $this->ok(200, $message, ['support_request' => AdminSupportRequestPresenter::detail($this->reload($supportRequestIdBinary))]);
        });
    }

    private function reload(string $supportRequestIdBinary): object
    {
        return DB::table('support_requests')
            ->join('support_request_statuses', 'support_request_statuses.id', '=', 'support_requests.status_id')
            ->where('support_requests.id', $supportRequestIdBinary)
            ->first(['support_requests.*', 'support_request_statuses.code as status']);
    }
}
