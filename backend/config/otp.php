<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OTP Delivery Driver
    |--------------------------------------------------------------------------
    |
    | Every OTP-issuing Action generates a raw code in memory, hashes it,
    | and discards it - this setting controls whether anything else ever
    | happens to that raw value before it is discarded. See
    | docs/api-contracts/authentication-v1.md for the full contract.
    |
    | Supported values:
    |   "null"   - discard only (default, safe everywhere, but delivers
    |              nothing). App\Providers\OtpDeliveryServiceProvider
    |              refuses to boot with "null" under APP_ENV=production -
    |              a production deployment must configure a real driver
    |              (e.g. "twilio") rather than silently fail to deliver
    |              any OTP.
    |   "log"    - additionally write the raw OTP to the log, but ONLY when
    |              APP_ENV=local. The same provider refuses to boot with
    |              "log" set in any other environment - it throws rather
    |              than silently falling back to safe behavior, so a
    |              misconfigured non-local environment fails loudly instead
    |              of risking a raw OTP ever reaching a shared log.
    |   "twilio" - deliver via Twilio's Messages API (see
    |              App\Support\Otp\TwilioOtpDeliveryChannel and the
    |              'twilio' block in config/services.php for required
    |              credentials). The approved BLUE V1 production driver.
    |
    | Never default this to "log" - see .env.example.
    |
    */

    'delivery_driver' => env('OTP_DELIVERY_DRIVER') ?? 'null',

];
