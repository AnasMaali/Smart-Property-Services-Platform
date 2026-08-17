<?php

namespace App\Actions\Contract;

use App\Actions\Contract\Concerns\AppliesContractExpiry;
use App\Support\Contract\ContractStatuses;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * Maintenance-only bulk sweep of ACTIVE Service Contracts whose ends_at has
 * already passed (BLUE V1 Phase 10F "Contract Expiry"). Correctness never
 * depends on this running - every write path that actually needs an
 * authoritative status already performs the same lazy transition itself
 * (see App\Actions\Contract\Concerns\AppliesContractExpiry's docblock).
 * This exists purely so `service_contract_statuses`-filtered Admin/Customer
 * list reads show EXPIRED promptly even for Contracts nobody has tried to
 * book against or mutate since their term ended.
 */
class ExpireDueContractsAction
{
    use AppliesContractExpiry;

    /**
     * @return int Number of Contracts transitioned to EXPIRED.
     */
    public function handle(int $limit = 500): int
    {
        $now = now();

        $candidateIds = DB::table('service_contracts')
            ->where('status_id', ContractStatuses::id('ACTIVE'))
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $now)
            ->orderBy('ends_at')
            ->limit($limit)
            ->pluck('id');

        $expired = 0;

        foreach ($candidateIds as $idBinary) {
            $uuid = UuidBinary::toString($idBinary);

            DB::transaction(function () use ($idBinary, &$expired): void {
                $contract = DB::table('service_contracts')->where('id', $idBinary)->lockForUpdate()->first();

                if ($contract === null) {
                    return;
                }

                $before = (int) $contract->status_id;
                $this->applyLazyExpiry($contract, now());

                if ((int) $contract->status_id !== $before) {
                    $expired++;
                }
            });
        }

        return $expired;
    }
}
