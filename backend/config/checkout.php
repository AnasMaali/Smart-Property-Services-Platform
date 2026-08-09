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

];
