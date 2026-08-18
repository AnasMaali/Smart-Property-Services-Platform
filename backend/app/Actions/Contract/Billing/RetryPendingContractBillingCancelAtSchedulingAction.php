<?php

namespace App\Actions\Contract\Billing;

use App\Support\Contract\Billing\ContractBillingStatuses;
use Illuminate\Support\Facades\DB;

/**
 * Maintenance-only retry sweep (BLUE V1 Phase 11 real-Stripe-test-mode fix)
 * for Service Contract billing rows whose Stripe Subscription id is known
 * but whose term-end `cancel_at` has not yet been confirmed by the provider
 * webhook - i.e. the original best-effort
 * App\Actions\Contract\Billing\ScheduleContractBillingSubscriptionCancelAtAction
 * attempt (fired from App\Actions\Contract\Billing\
 * ProcessContractBillingWebhookAction) may have failed or timed out. This
 * exists to uphold the business guarantee that a Contract's subscription
 * must never continue billing past the Contract's own `ends_at`.
 *
 * Selects purely from already-existing columns - no new durable-marker
 * column exists or is needed (see ScheduleContractBillingSubscriptionCancelAtAction's
 * docblock for why this differs from the provider-cancellation retry case).
 * A row naturally stops being selected the moment either becomes true:
 *   - the webhook confirms it (`cancel_at` is set), or
 *   - it no longer has a Subscription id / Contract `ends_at` at all.
 * Safe to run on an interval forever - re-attempting an already-confirmed
 * row is impossible by construction (it is excluded by the same query).
 */
class RetryPendingContractBillingCancelAtSchedulingAction
{
    public function __construct(private readonly ScheduleContractBillingSubscriptionCancelAtAction $attempt) {}

    /**
     * @return int Number of pending schedule rows an attempt was made for.
     */
    public function handle(int $limit = 100): int
    {
        $cancelledStatusId = ContractBillingStatuses::id('CANCELLED');

        $candidateContractIds = DB::table('service_contract_billings')
            ->join('service_contracts', 'service_contracts.id', '=', 'service_contract_billings.service_contract_id')
            ->whereNotNull('service_contract_billings.stripe_subscription_id')
            ->whereNull('service_contract_billings.cancel_at')
            ->whereNotNull('service_contracts.ends_at')
            ->where('service_contract_billings.status_id', '!=', $cancelledStatusId)
            ->orderBy('service_contract_billings.updated_at')
            ->limit($limit)
            ->pluck('service_contract_billings.service_contract_id');

        foreach ($candidateContractIds as $contractIdBinary) {
            $this->attempt->handle($contractIdBinary);
        }

        return $candidateContractIds->count();
    }
}
