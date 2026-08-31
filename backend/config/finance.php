<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Financial Reporting Business Timezone
    |--------------------------------------------------------------------------
    |
    | BLUE V1 Admin Financial Dashboard/Ledger date-range filters (Today,
    | Last 7 Days, This Month) are interpreted as UAE calendar days, never
    | the storage timezone (`config('app.timezone')`, UTC) and never the
    | machine/server timezone this app happens to run on - mirrors
    | `config('cancellation.timezone')`'s exact precedent and rationale
    | (see App\Support\Booking\RefundEligibilityCalculator's docblock).
    | Database timestamps stay stored in UTC; only the calendar-day
    | boundary math (App\Support\Admin\AdminFinancialDateRange) converts
    | into this timezone, then back to UTC instants before ever touching a
    | query.
    |
    */

    'timezone' => env('FINANCE_TIMEZONE', 'Asia/Dubai'),

];
