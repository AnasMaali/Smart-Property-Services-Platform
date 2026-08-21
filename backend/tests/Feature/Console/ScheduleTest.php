<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Production only runs one system cron entry (`php artisan schedule:run`
 * every minute, per routes/console.php's own docblock) - everything else
 * depends on Laravel actually knowing which Artisan commands are due. This
 * proves every maintenance command this codebase documents as scheduled
 * really is registered, so a command silently missing from routes/
 * console.php (like bookings:convert-successful-payments was before this
 * fix - a documented recovery path with no scheduled invocation) is caught
 * here instead of only being discovered in production.
 */
class ScheduleTest extends TestCase
{
    /**
     * @return Collection<int, \Illuminate\Console\Scheduling\Event>
     */
    private function scheduledEvents(): Collection
    {
        return collect(app(Schedule::class)->events());
    }

    private function eventFor(string $commandSignature): ?object
    {
        return $this->scheduledEvents()
            ->first(fn ($event) => str_contains((string) $event->command, "'{$commandSignature}'") || str_contains((string) $event->command, " {$commandSignature}"));
    }

    public function test_every_documented_maintenance_command_is_scheduled(): void
    {
        foreach ([
            'contracts:expire',
            'contracts:retry-pending-billing-cancellations',
            'contracts:retry-pending-cancel-at-scheduling',
            'contracts:suspend-past-due-billing',
            'bookings:convert-successful-payments',
            'accounts:process-pending-deletions',
        ] as $signature) {
            $this->assertNotNull($this->eventFor($signature), "Expected \"{$signature}\" to be registered in routes/console.php's scheduler.");
        }
    }

    // The Booking-conversion recovery command is documented as idempotent
    // and safe to run repeatedly (see ConvertSuccessfulPaymentsToBookings's
    // own docblock) - it must run frequently enough that a transient
    // conversion failure is recovered within minutes, not left stranded
    // until an operator notices, and must never overlap a still-running
    // invocation.
    public function test_booking_conversion_recovery_runs_frequently_and_without_overlap(): void
    {
        $event = $this->eventFor('bookings:convert-successful-payments');

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->getExpression());
        $this->assertTrue($event->withoutOverlapping);
    }

    // The deferred account-deletion processor (BLUE V1 Phase 13) must run
    // frequently enough that a customer's PENDING deletion completes
    // within minutes of their blocking obligation clearing, not left
    // stranded until an operator notices, and must never overlap a
    // still-running invocation (see App\Console\Commands\
    // ProcessPendingAccountDeletions's own idempotency docblock).
    public function test_pending_account_deletion_processor_runs_frequently_and_without_overlap(): void
    {
        $event = $this->eventFor('accounts:process-pending-deletions');

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->getExpression());
        $this->assertTrue($event->withoutOverlapping);
    }
}
