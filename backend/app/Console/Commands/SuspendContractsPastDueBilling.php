<?php

namespace App\Console\Commands;

use App\Actions\Contract\Billing\SuspendContractsPastDueBillingAction;
use Illuminate\Console\Command;

/**
 * Maintenance-only escalation (BLUE V1 Phase 11) - see
 * App\Actions\Contract\Billing\SuspendContractsPastDueBillingAction's
 * docblock for why new CONTRACT Bookings are already blocked for the whole
 * PAST_DUE window with or without this command ever running; this command
 * only handles the slower "still PAST_DUE after the grace period"
 * escalation to SUSPENDED.
 */
class SuspendContractsPastDueBilling extends Command
{
    protected $signature = 'contracts:suspend-past-due-billing {--limit=500 : Maximum contracts to process in one run}';

    protected $description = 'Suspend ACTIVE service contracts whose billing has stayed PAST_DUE beyond the configured grace period.';

    public function handle(SuspendContractsPastDueBillingAction $action): int
    {
        $suspended = $action->handle(max(1, (int) $this->option('limit')));

        $this->info("Done. Suspended: {$suspended}.");

        return self::SUCCESS;
    }
}
