<?php

namespace App\Actions\Admin\ContractBilling;

use App\Support\Admin\AdminContractBillingPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only Admin Service Contract Billing detail lookup (BLUE V1 Phase B5)
 * - looked up by the billing row's OWN uuid (distinct from
 * App\Actions\Admin\Contract\AdminGetContractAction, which is looked up by
 * Contract uuid and embeds this same billing state under its own `billing`
 * key). A malformed or unknown Billing UUID is reported identically as 404.
 */
final class AdminGetContractBillingAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $billingUuid): array
    {
        try {
            $billingIdBinary = UuidBinary::toBinary($billingUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Contract billing not found.');
        }

        $row = DB::table('service_contract_billings')
            ->join('service_contracts', 'service_contracts.id', '=', 'service_contract_billings.service_contract_id')
            ->where('service_contract_billings.id', $billingIdBinary)
            ->first([
                'service_contract_billings.*',
                'service_contracts.contract_number',
                'service_contracts.customer_user_id',
            ]);

        if ($row === null) {
            return $this->notFound('Contract billing not found.');
        }

        return $this->ok(200, 'Contract billing retrieved successfully.', ['contract_billing' => AdminContractBillingPresenter::detail($row)]);
    }
}
