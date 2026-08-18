<?php

namespace App\Console\Commands;

use App\Actions\Contract\Billing\RetryPendingContractBillingCancelAtSchedulingAction;
use Illuminate\Console\Command;

/**
 * Maintenance-only retry sweep (BLUE V1 Phase 11 real-Stripe-test-mode fix) -
 * see App\Actions\Contract\Billing\RetryPendingContractBillingCancelAtSchedulingAction's
 * docblock for why this is the retry path for scheduling a Subscription's
 * term-end `cancel_at` whenever the original attempt (fired from the
 * Contract Billing webhook handler) may have failed. Safe to schedule on a
 * recurring interval - a fully idempotent no-op run whenever nothing is
 * pending.
 */
class RetryPendingContractBillingCancelAtScheduling extends Command
{
    protected $signature = 'contracts:retry-pending-cancel-at-scheduling {--limit=100 : Maximum pending schedule attempts to retry in one run}';

    protected $description = "Retry scheduling a Stripe subscription's term-end cancel_at for Service Contract billing rows whose subscription is known but cancel_at has not yet been confirmed by the provider webhook.";

    public function handle(RetryPendingContractBillingCancelAtSchedulingAction $action): int
    {
        $attempted = $action->handle(max(1, (int) $this->option('limit')));

        $this->info("Done. Retried: {$attempted}.");

        return self::SUCCESS;
    }
}
