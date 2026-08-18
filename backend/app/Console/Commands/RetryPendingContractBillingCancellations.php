<?php

namespace App\Console\Commands;

use App\Actions\Contract\Billing\RetryPendingContractBillingCancellationsAction;
use Illuminate\Console\Command;

/**
 * Maintenance-only retry sweep (BLUE V1 Phase 11 provider-outage hardening) -
 * see App\Actions\Contract\Billing\RetryPendingContractBillingCancellationsAction's
 * docblock for why this is the ONLY retry path for a provider-side
 * Subscription cancellation whose original delivery attempt may have failed
 * (a repeated Admin cancel endpoint call is deliberately never one). Safe to
 * schedule on a recurring interval - a fully idempotent no-op run whenever
 * nothing is pending.
 */
class RetryPendingContractBillingCancellations extends Command
{
    protected $signature = 'contracts:retry-pending-billing-cancellations {--limit=100 : Maximum pending cancellations to retry in one run}';

    protected $description = 'Retry provider-side subscription cancellation for Service Contract billing rows whose cancellation request has not yet been reconciled by the provider webhook.';

    public function handle(RetryPendingContractBillingCancellationsAction $action): int
    {
        $attempted = $action->handle(max(1, (int) $this->option('limit')));

        $this->info("Done. Retried: {$attempted}.");

        return self::SUCCESS;
    }
}
