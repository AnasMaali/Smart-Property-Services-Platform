<?php

namespace App\Actions\Admin\ContractBilling;

use App\Support\Admin\AdminContractBillingPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only, paginated Service Contract Billing listing for Admin operators
 * (BLUE V1 Phase B5) - recurring subscription billing state, distinct from
 * one-off App\Actions\Admin\Payment\AdminListPaymentsAction. Mirrors that
 * class's pagination/filter conventions exactly.
 */
final class AdminListContractBillingsAction
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
                return $this->ok(200, 'Contract billings retrieved successfully.', [
                    'contract_billings' => [],
                    'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1],
                ]);
            }
        }

        $query = DB::table('service_contract_billings')
            ->join('service_contracts', 'service_contracts.id', '=', 'service_contract_billings.service_contract_id')
            ->join('service_contract_billing_statuses', 'service_contract_billing_statuses.id', '=', 'service_contract_billings.status_id');

        if (isset($filters['status'])) {
            $query->where('service_contract_billing_statuses.code', $filters['status']);
        }

        if (isset($filters['contract_number'])) {
            $query->where('service_contracts.contract_number', $filters['contract_number']);
        }

        if (isset($filters['customer_uuid'])) {
            $query->where('service_contracts.customer_user_id', $filters['customer_uuid']);
        }

        $total = (clone $query)->count('service_contract_billings.id');
        $lastPage = max((int) ceil($total / $perPage), 1);

        $rows = $query
            ->orderByDesc('service_contract_billings.created_at')
            ->orderByDesc('service_contract_billings.id')
            ->forPage($page, $perPage)
            ->get([
                'service_contract_billings.*',
                'service_contracts.contract_number',
                'service_contracts.customer_user_id',
            ]);

        return $this->ok(200, 'Contract billings retrieved successfully.', [
            'contract_billings' => AdminContractBillingPresenter::presentList($rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }
}
