<?php

namespace App\Actions\Contract\Concerns;

use App\Support\Contract\ContractStatuses;
use App\Support\Contract\ContractStatusMachine;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic Service Contract expiry (BLUE V1 Phase 10F "Contract
 * Expiry") - no background scheduler is required for correctness. Every
 * write path that needs an authoritative "is this Contract usable right
 * now" answer (entitlement consumption, suspend, cancel) calls
 * applyLazyExpiry() on the row it has ALREADY locked FOR UPDATE, which
 * performs the real ACTIVE -> EXPIRED transition - with exactly one
 * service_contract_status_history row - the moment it discovers the term
 * has ended, before evaluating any precondition that assumes ACTIVE. This
 * makes the transition race-safe for free: two concurrent callers both
 * holding row locks on the same contract can only ever run this
 * sequentially, and the second one observes the first's already-committed
 * EXPIRED status and does nothing.
 *
 * Plain reads (list/detail presenters) never call this - they compute the
 * same "effective status" purely, without writing, via
 * App\Support\Contract\ContractPresenter::effectiveStatus() /
 * App\Support\Admin\AdminContractPresenter, so a GET request is never
 * surprised by a side-effecting write. A `contracts:expire` Artisan command
 * (BLUE V1 Phase 10F) additionally sweeps any ACTIVE contract past its
 * ends_at in bulk as a maintenance convenience, but correctness never
 * depends on that command having run.
 */
trait AppliesContractExpiry
{
    private function applyLazyExpiry(object $contract, Carbon $now): object
    {
        if ((int) $contract->status_id !== ContractStatuses::id('ACTIVE')) {
            return $contract;
        }

        if ($contract->ends_at === null || Carbon::parse($contract->ends_at)->greaterThan($now)) {
            return $contract;
        }

        $machine = new ContractStatusMachine;

        if (! $machine->transitionToExpired($contract, $now)) {
            return $contract;
        }

        DB::table('service_contract_status_history')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'service_contract_id' => $contract->id,
            'from_status_id' => (int) $contract->status_id,
            'to_status_id' => ContractStatuses::id('EXPIRED'),
            'changed_by_user_id' => null,
            'reason' => 'Contract term ended.',
            'changed_at' => $now->format('Y-m-d H:i:s.u'),
        ]);

        $contract->status_id = ContractStatuses::id('EXPIRED');
        $contract->status_changed_at = $now->format('Y-m-d H:i:s.u');

        return $contract;
    }
}
