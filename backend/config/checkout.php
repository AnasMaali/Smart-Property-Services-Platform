<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Appointment Hold TTL
    |--------------------------------------------------------------------------
    |
    | Minutes an appointment_holds row stays usable after creation before it
    | is treated as expired (chk_appointment_holds_expiration only constrains
    | expires_at > held_at; the magnitude is an explicit BLUE V1 product
    | decision, not derived from schema). Approved default: 10 minutes,
    | configurable through CHECKOUT_APPOINTMENT_HOLD_TTL_MINUTES, following
    | the same env-configurable-TTL pattern already used for
    | AUTH_ACCESS_TOKEN_TTL_MINUTES. Never hardcode 10 (or any other value)
    | inside an Action - always read it from this config.
    |
    */

    'appointment_hold_ttl_minutes' => (int) env('CHECKOUT_APPOINTMENT_HOLD_TTL_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Appointment Schedule Business Timezone
    |--------------------------------------------------------------------------
    |
    | BLUE V1 Phase B27 (Appointment Schedule Management). Mirrors
    | `config('cancellation.timezone')` / `config('finance.timezone')`'s
    | exact precedent and rationale: BLUE is a UAE-only operation, so
    | Asia/Dubai is the one correct default for interpreting a calendar
    | DATE - the customer `?date=` filter on GET /v1/checkout/appointment-
    | slots, and the Admin Appointment Schedule day view/generator
    | (App\Actions\Admin\AppointmentSchedule\*). `appointment_slots.starts_at`/
    | `ends_at` themselves stay stored under `config('app.timezone')` (UTC) -
    | only the CALENDAR DAY boundary is computed in this timezone, then
    | converted back to UTC instants before ever touching the database. A
    | separate config key (rather than reusing cancellation/finance's) keeps
    | each domain independently configurable, per this codebase's existing
    | convention - see config/cancellation.php's own docblock.
    |
    */

    'timezone' => env('CHECKOUT_TIMEZONE', 'Asia/Dubai'),

];
