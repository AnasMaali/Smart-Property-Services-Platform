<?php

namespace App\Actions\Admin\Contract;

use App\Support\Admin\AdminContractPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only, paginated Service Contract listing for Admin operators (BLUE
 * V1 Phase 10E) - mirrors App\Actions\Admin\Booking\AdminListBookingsAction's
 * pagination/filter conventions exactly. Never scoped to one customer.
 */
final class AdminListContractsAction
{
    use BuildsCartResult;

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{status?: string, contract_number?: string, customer_uuid?: string}  $filters
     * @return array<string, mixed>
     */
    public function handle(array $filters, int $page, int $perPage): array
    {
        $page = max($page, 1);
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        if (isset($filters['customer_uuid'])) {
            try {
                $filters['customer_uuid'] = UuidBinary::toBinary($filters['customer_uuid']);
            } catch (InvalidArgumentException) {
                return $this->ok(200, 'Service contracts retrieved successfully.', [
                    'contracts' => [],
                    'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1],
                ]);
            }
        }

        $query = DB::table('service_contracts')
            ->join('service_contract_statuses', 'service_contract_statuses.id', '=', 'service_contracts.status_id');

        if (isset($filters['status'])) {
            $query->where('service_contract_statuses.code', $filters['status']);
        }

        if (isset($filters['contract_number'])) {
            $query->where('service_contracts.contract_number', $filters['contract_number']);
        }

        if (isset($filters['customer_uuid'])) {
            $query->where('service_contracts.customer_user_id', $filters['customer_uuid']);
        }

        $total = (clone $query)->count('service_contracts.id');
        $lastPage = max((int) ceil($total / $perPage), 1);

        $rows = $query
            ->orderByDesc('service_contracts.created_at')
            ->orderByDesc('service_contracts.id')
            ->forPage($page, $perPage)
            ->get(['service_contracts.*']);

        return $this->ok(200, 'Service contracts retrieved successfully.', [
            'contracts' => AdminContractPresenter::presentList($rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }
}
