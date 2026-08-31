<?php

namespace App\Actions\Admin\Support;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminSupportRequestPresenter;
use App\Support\Admin\SupportRequestStatusMachine;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one Support Request lifecycle status mutation (POST
 * /v1/admin/support-requests/{supportRequest}/status, `support.manage`) -
 * the explicit `AdminUpdateSupportRequestStatusAction` that
 * App\Actions\Admin\Support\AdminSendSupportMessageAction's docblock and
 * docs/api-contracts/admin-operations-v1.md "Support" both named as the
 * future home for this. Never writes `status_id` directly from a
 * Controller - every write goes through App\Support\Admin\
 * SupportRequestStatusMachine, the single source of truth for which
 * transitions are allowed and how `resolved_at`/`closed_at` move with
 * them.
 *
 * Already-in-target-state is a safe, idempotent 200 (no write, no audit
 * row - mirrors App\Actions\Admin\Technician\AdminSetTechnicianStatusAction).
 * A structurally disallowed transition is a 409, never a 500 or a silent
 * no-op. Row-locked (`SELECT ... FOR UPDATE`) so two concurrent status
 * changes on the same request can never race.
 */
final class AdminUpdateSupportRequestStatusAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly SupportRequestStatusMachine $machine = new SupportRequestStatusMachine,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request, string $supportRequestUuid, User $actor, string $statusCode): array
    {
        try {
            $supportRequestIdBinary = UuidBinary::toBinary($supportRequestUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Support request not found.');
        }

        return DB::transaction(function () use ($request, $supportRequestUuid, $supportRequestIdBinary, $actor, $statusCode): array {
            $supportRequest = DB::table('support_requests')
                ->join('support_request_statuses', 'support_request_statuses.id', '=', 'support_requests.status_id')
                ->where('support_requests.id', $supportRequestIdBinary)
                ->lockForUpdate()
                ->first(['support_requests.*', 'support_request_statuses.code as status']);

            if ($supportRequest === null) {
                return $this->notFound('Support request not found.');
            }

            $targetStatus = DB::table('support_request_statuses')->where('code', $statusCode)->where('is_active', 1)->first(['code']);

            if ($targetStatus === null) {
                return $this->unprocessable('The given data was invalid.', ['status' => ['This support request status does not exist.']]);
            }

            if ($targetStatus->code === $supportRequest->status) {
                return $this->ok(200, 'Support request is already in this status.', ['support_request' => AdminSupportRequestPresenter::detail($supportRequest)]);
            }

            if (! $this->machine->isAllowed($supportRequest->status, $targetStatus->code)) {
                return $this->conflict("This support request cannot move from {$supportRequest->status} to {$targetStatus->code}.");
            }

            $previousStatus = $supportRequest->status;

            $this->machine->transition($supportRequest, $targetStatus->code, now());

            AdminAuditLogger::record(
                $request,
                $actor,
                'SUPPORT_REQUEST_STATUS_CHANGED',
                'SUPPORT_REQUEST',
                $supportRequestUuid,
                ['status' => $targetStatus->code],
                ['status' => $previousStatus],
            );

            $updated = DB::table('support_requests')
                ->join('support_request_statuses', 'support_request_statuses.id', '=', 'support_requests.status_id')
                ->where('support_requests.id', $supportRequestIdBinary)
                ->first(['support_requests.*', 'support_request_statuses.code as status']);

            return $this->ok(200, 'Support request status updated successfully.', ['support_request' => AdminSupportRequestPresenter::detail($updated)]);
        });
    }
}
