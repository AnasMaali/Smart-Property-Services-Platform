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
 * Clears the Admin operator assignment on a Support Request (POST
 * /v1/admin/support-requests/{supportRequest}/unassign-admin,
 * `support.manage`) - the counterpart to App\Actions\Admin\Support\
 * AdminAssignSupportRequestAction. Sets `assigned_admin_user_id` back to
 * null so the request returns to the "unassigned" queue (see the
 * `unassigned` filter on App\Actions\Admin\Support\
 * AdminListSupportRequestsAction) without touching lifecycle status.
 *
 * Already-unassigned is a safe, idempotent 200 (no write, no audit row).
 * Row-locked so a concurrent assign/unassign/status change on the same
 * request can never race.
 */
final class AdminUnassignSupportRequestAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request, string $supportRequestUuid, User $actor): array
    {
        try {
            $supportRequestIdBinary = UuidBinary::toBinary($supportRequestUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Support request not found.');
        }

        return DB::transaction(function () use ($request, $supportRequestUuid, $supportRequestIdBinary, $actor): array {
            $supportRequest = DB::table('support_requests')
                ->where('id', $supportRequestIdBinary)
                ->lockForUpdate()
                ->first();

            if ($supportRequest === null) {
                return $this->notFound('Support request not found.');
            }

            if ($supportRequest->assigned_admin_user_id === null) {
                return $this->ok(200, 'This support request is already unassigned.', ['support_request' => AdminSupportRequestPresenter::detail($this->reload($supportRequestIdBinary))]);
            }

            $previousAdminUuid = UuidBinary::toString($supportRequest->assigned_admin_user_id);

            DB::table('support_requests')->where('id', $supportRequestIdBinary)->update([
                'assigned_admin_user_id' => null,
                'updated_at' => now(),
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'SUPPORT_REQUEST_UNASSIGNED',
                'SUPPORT_REQUEST',
                $supportRequestUuid,
                null,
                ['admin_uuid' => $previousAdminUuid],
            );

            return $this->ok(200, 'Support request unassigned successfully.', ['support_request' => AdminSupportRequestPresenter::detail($this->reload($supportRequestIdBinary))]);
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
