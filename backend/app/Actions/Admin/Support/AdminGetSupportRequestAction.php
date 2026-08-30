<?php

namespace App\Actions\Admin\Support;

use App\Support\Admin\AdminSupportRequestPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only Admin Support Request detail lookup (BLUE V1 Phase B7) - never
 * scoped to an actor (there is no customer-facing Support implementation
 * yet to preserve ownership semantics for). A malformed or unknown Support
 * Request UUID is reported identically as 404.
 */
final class AdminGetSupportRequestAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $supportRequestUuid): array
    {
        try {
            $supportRequestIdBinary = UuidBinary::toBinary($supportRequestUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Support request not found.');
        }

        $row = DB::table('support_requests')
            ->join('support_request_statuses', 'support_request_statuses.id', '=', 'support_requests.status_id')
            ->where('support_requests.id', $supportRequestIdBinary)
            ->first(['support_requests.*', 'support_request_statuses.code as status']);

        if ($row === null) {
            return $this->notFound('Support request not found.');
        }

        return $this->ok(200, 'Support request retrieved successfully.', ['support_request' => AdminSupportRequestPresenter::detail($row)]);
    }
}
