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
