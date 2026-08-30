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
 * Posts an Admin reply message on a Support Request (BLUE V1 Phase B7,
 * POST /v1/admin/support-requests/{supportRequest}/messages) - the ONLY
 * Support mutation this phase implements. `sender_user_id` is always the
 * `auth.admin`-resolved actor, never a request field (matches every other
 * privileged Admin mutation in this codebase).
 *
 * Deliberately does NOT touch `support_requests.status_id`/
 * `status_changed_at`/`resolved_at`/`closed_at` - no existing code
 * (customer-facing or Admin) or product requirement document defines
 * whether/how sending a reply should affect the Support Request's
 * lifecycle status, and inventing that policy here would be exactly the
 * kind of unrequested lifecycle rule this phase's own instructions
 * prohibit. Status-transition and assignment mutations are intentionally
 * NOT implemented in this phase - see docs/api-contracts/
 * admin-operations-v1.md "Support" section for the full explanation of
 * why that decision was made, and what would be needed to add them later.
 */
final class AdminSendSupportMessageAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request, string $supportRequestUuid, User $actor, string $messageBody): array
    {
        try {
            $supportRequestIdBinary = UuidBinary::toBinary($supportRequestUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Support request not found.');
        }

        $actorIdBinary = UuidBinary::toBinary($actor->id);

        return DB::transaction(function () use ($request, $supportRequestUuid, $supportRequestIdBinary, $actor, $actorIdBinary, $messageBody): array {
            $supportRequest = DB::table('support_requests')
                ->join('support_request_statuses', 'support_request_statuses.id', '=', 'support_requests.status_id')
                ->where('support_requests.id', $supportRequestIdBinary)
                ->first(['support_requests.*', 'support_request_statuses.code as status']);

            if ($supportRequest === null) {
                return $this->notFound('Support request not found.');
            }

            $now = now();
            $messageUuid = UuidBinary::generate();

            DB::table('support_messages')->insert([
                'id' => UuidBinary::toBinary($messageUuid),
                'support_request_id' => $supportRequestIdBinary,
                'sender_user_id' => $actorIdBinary,
                'message_body' => $messageBody,
                'created_at' => $now,
            ]);

            // Safe identifiers only - the message text itself is already
            // stored canonically in support_messages and is never
            // duplicated into admin_audit_logs.
            AdminAuditLogger::record(
                $request,
                $actor,
                'SUPPORT_MESSAGE_SENT',
                'SUPPORT_REQUEST',
                $supportRequestUuid,
                ['message_uuid' => $messageUuid]
            );

            $updated = DB::table('support_requests')
                ->join('support_request_statuses', 'support_request_statuses.id', '=', 'support_requests.status_id')
                ->where('support_requests.id', $supportRequestIdBinary)
                ->first(['support_requests.*', 'support_request_statuses.code as status']);

            return $this->ok(201, 'Message sent successfully.', ['support_request' => AdminSupportRequestPresenter::detail($updated)]);
        });
    }
}
