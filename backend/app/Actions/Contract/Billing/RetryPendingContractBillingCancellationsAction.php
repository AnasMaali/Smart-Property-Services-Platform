<?php

namespace App\Actions\Contract\Billing;

use App\Support\Contract\Billing\ContractBillingStatuses;
use Illuminate\Support\Facades\DB;

/**
 * Maintenance-only retry sweep (BLUE V1 Phase 11 provider-outage hardening)
 * for Service Contract billing rows that durably recorded a cancellation
 * request (App\Actions\Contract\Billing\
 * CancelContractBillingSubscriptionAction::markCancellationRequested()) but
 * have not yet been reconciled by the provider's `customer.subscription.deleted`
 * webhook - i.e. a request whose original best-effort delivery attempt may
 * have failed or timed out. This is the ONLY retry mechanism for that
 * delivery - a repeated Admin cancel endpoint call is deliberately never
 * one (see App\Actions\Admin\Contract\AdminCancelContractAction and
 * App\Actions\Contract\Billing\CancelContractBillingSubscriptionAction
 * class docblocks): the Contract itself is already CANCELLED locally the
 * moment this command has anything to do, so nothing here ever touches
 * `service_contracts` - only re-attempts the provider-side delivery via the
 * exact same idempotent-by-design
 * CancelContractBillingSubscriptionAction::handle() the initiating Admin
 * cancellation itself used.
 *
 * A row naturally stops being selected the moment either becomes true:
 *   - the webhook reconciles it (`cancelled_at` is set), or
 *   - it somehow no longer has a pending request/subscription id at all.
 * This is what guarantees the sweep is safe to run on an interval forever
 * without ever double-cancelling or spinning forever on a row that is
 * already resolved.
 */
class RetryPendingContractBillingCancellationsAction
{
    public function __construct(private readonly CancelContractBillingSubscriptionAction $attempt) {}

    /**
     * @return int Number of pending cancellation rows an attempt was made for.
     */
    public function handle(int $limit = 100): int
    {
        $cancelledStatusId = ContractBillingStatuses::id('CANCELLED');

        $candidateContractIds = DB::table('service_contract_billings')
            ->whereNotNull('provider_cancellation_requested_at')
            ->whereNull('cancelled_at')
            ->whereNotNull('stripe_subscription_id')
            ->where('status_id', '!=', $cancelledStatusId)
            ->orderBy('provider_cancellation_requested_at')
            ->limit($limit)
            ->pluck('service_contract_id');

        foreach ($candidateContractIds as $contractIdBinary) {
            $this->attempt->handle($contractIdBinary);
        }

        return $candidateContractIds->count();
    }
}
