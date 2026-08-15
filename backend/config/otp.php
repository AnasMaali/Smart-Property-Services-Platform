<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OTP Delivery Driver
    |--------------------------------------------------------------------------
    |
    | BLUE V1 has no SMS provider integration yet (see
    | docs/api-contracts/authentication-v1.md). Every OTP-issuing Action
    | generates a raw code in memory, hashes it, and discards it - this
    | setting controls whether anything else ever happens to that raw value
    | before it is discarded.
    |
    | Supported values:
    |   "null" - discard only (default, safe everywhere). Matches the
    |            original behavior before local OTP delivery existed.
    |   "log"  - additionally write the raw OTP to the log, but ONLY when
    |            APP_ENV=local. App\Providers\OtpDeliveryServiceProvider
    |            refuses to boot with "log" set in any other environment -
    |            it throws rather than silently falling back to "null", so a
    |            misconfigured non-local environment fails loudly instead of
    |            risking a raw OTP ever reaching a shared log.
    |
    | Never default this to "log" - see .env.example.
    |
    */

    'delivery_driver' => env('OTP_DELIVERY_DRIVER') ?? 'null',

];
