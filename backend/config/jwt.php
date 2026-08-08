<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Access Token Signing Secret
    |--------------------------------------------------------------------------
    |
    | Dedicated secret used to sign and verify customer access tokens
    | (see docs/06-technical-system-design/02-authentication-session-and-token-design.md).
    | This must never reuse Laravel's APP_KEY.
    |
    */

    'secret' => env('AUTH_JWT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Access Token TTL
    |--------------------------------------------------------------------------
    |
    | Lifetime, in minutes, of an issued access token.
    |
    */

    'access_token_ttl_minutes' => (int) env('AUTH_ACCESS_TOKEN_TTL_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Refresh / Session TTL
    |--------------------------------------------------------------------------
    |
    | Absolute lifetime, in days, of an auth_sessions row from the moment of
    | login. Not extended by future refresh calls.
    |
    */

    'session_ttl_days' => (int) env('AUTH_SESSION_TTL_DAYS', 30),

];
