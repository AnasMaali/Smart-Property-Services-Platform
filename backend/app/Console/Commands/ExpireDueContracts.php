<?php

namespace App\Console\Commands;

use App\Actions\Contract\ExpireDueContractsAction;
use Illuminate\Console\Command;

/**
 * Optional maintenance convenience (BLUE V1 Phase 10F) - never required for
 * correctness. See App\Actions\Contract\ExpireDueContractsAction's
 * docblock for why every write path that actually needs an authoritative
 * Contract status already performs the same lazy ACTIVE -> EXPIRED
 * transition itself, with or without this command ever running.
 */
class ExpireDueContracts extends Command
{
    protected $signature = 'contracts:expire {--limit=500 : Maximum contracts to process in one run}';

    protected $description = 'Transition ACTIVE service contracts past their ends_at to EXPIRED (maintenance convenience only).';

    public function handle(ExpireDueContractsAction $action): int
    {
        $expired = $action->handle(max(1, (int) $this->option('limit')));

        $this->info("Done. Expired: {$expired}.");

        return self::SUCCESS;
    }
}
