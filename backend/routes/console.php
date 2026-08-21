<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| BLUE V1 Scheduled Maintenance
|--------------------------------------------------------------------------
|
| Production only needs one system cron entry that executes
| `php artisan schedule:run` every minute. Laravel decides which of the
| following maintenance commands are actually due.
|
*/

// Keep Contract operational status aligned with its configured term.
Schedule::command('contracts:expire')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Retry provider-side cancellation requests that have not yet been
// confirmed by the billing-provider webhook.
Schedule::command('contracts:retry-pending-billing-cancellations')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Retry setting the provider subscription's term-end cancellation time
// when a subscription exists but scheduling has not yet been confirmed.
Schedule::command('contracts:retry-pending-cancel-at-scheduling')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Escalate Contracts whose billing has remained PAST_DUE beyond the
// configured grace period.
Schedule::command('contracts:suspend-past-due-billing')
    ->hourly()
    ->withoutOverlapping(120);

// Recover Bookings for SUCCESSFUL payment attempts whose post-webhook
// conversion attempt transiently failed (see
// App\Console\Commands\ConvertSuccessfulPaymentsToBookings) - idempotent
// and safe to run repeatedly, so a healthy system simply finds nothing.
Schedule::command('bookings:convert-successful-payments')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Complete deferred customer account deletions once their blocking
// Booking/Contract/Payment obligation has become terminal (see
// App\Console\Commands\ProcessPendingAccountDeletions) - idempotent and
// safe to run repeatedly, so a system with no pending requests simply
// finds nothing.
Schedule::command('accounts:process-pending-deletions')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);
