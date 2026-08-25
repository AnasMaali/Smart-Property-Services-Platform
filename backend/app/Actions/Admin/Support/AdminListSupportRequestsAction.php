<?php

namespace App\Actions\Admin\Support;

use App\Support\Admin\AdminSupportRequestPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only, paginated Support Request listing for Admin operators (BLUE V1
 * Phase B7) - mirrors App\Actions\Admin\Booking\AdminListBookingsAction's
 * pagination/filter conventions exactly. Never scoped to one customer.
 * Deterministic ordering (`created_at DESC, id DESC`) and a bounded page
 * size make this safe to call against an unbounded table.
 */
final class AdminListSupportRequestsAction
{
    use BuildsCartResult;

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{status?: string, customer_uuid?: string, booking_uuid?: string, assigned_admin_uuid?: string, unassigned?: bool, search?: string}  $filters
     * @return array<string, mixed>
     */
    public function handle(array $filters, int $page, int $perPage): array
    {
        $page = max($page, 1);
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        foreach (['customer_uuid', 'booking_uuid', 'assigned_admin_uuid'] as $uuidFilter) {
            if (isset($filters[$uuidFilter])) {
                try {
                    $filters[$uuidFilter] = UuidBinary::toBinary($filters[$uuidFilter]);
                } catch (InvalidArgumentException) {
                    return $this->ok(200, 'Support requests retrieved successfully.', [
                        'support_requests' => [],
                        'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1],
                    ]);
                }
            }
        }

        $query = DB::table('support_requests')
            ->join('support_request_statuses', 'support_request_statuses.id', '=', 'support_requests.status_id');

        if (isset($filters['status'])) {
            $query->where('support_request_statuses.code', $filters['status']);
        }

        if (isset($filters['customer_uuid'])) {
            $query->where('support_requests.customer_user_id', $filters['customer_uuid']);
        }

        if (isset($filters['booking_uuid'])) {
            $query->where('support_requests.booking_id', $filters['booking_uuid']);
        }

        if (isset($filters['assigned_admin_uuid'])) {
            $query->where('support_requests.assigned_admin_user_id', $filters['assigned_admin_uuid']);
        }

        if (! empty($filters['unassigned'])) {
            $query->whereNull('support_requests.assigned_admin_user_id');
        }

        if (isset($filters['search'])) {
            $query->where('support_requests.subject', 'like', '%'.$filters['search'].'%');
        }

        $total = (clone $query)->count('support_requests.id');
        $lastPage = max((int) ceil($total / $perPage), 1);

        $rows = $query
            ->orderByDesc('support_requests.created_at')
            ->orderByDesc('support_requests.id')
            ->forPage($page, $perPage)
            ->get([
                'support_requests.*',
                'support_request_statuses.code as status',
            ]);

        return $this->ok(200, 'Support requests retrieved successfully.', [
            'support_requests' => AdminSupportRequestPresenter::presentList($rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }
}
