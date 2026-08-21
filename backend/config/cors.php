<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | BLUE V1's customer client (Flutter) is a native mobile app and is
    | never subject to browser CORS - this configuration only matters for
    | the Admin Web frontend and any other browser-based client. The API is
    | Bearer-token authenticated (never cookie-based), so
    | supports_credentials stays false regardless of allowed_origins - a
    | credentialed wildcard-origin policy is the one CORS combination
    | browsers themselves refuse and this codebase never needs anyway.
    |
    | CORS_ALLOWED_ORIGINS (.env) is a comma-separated origin list (e.g.
    | "https://admin.example.com,https://admin-staging.example.com") -
    | left unset, it defaults to "*" (every origin), matching local
    | development convenience and this project's previous unconfigured
    | behavior. Production should set it to the real Admin Web origin(s)
    | only. Stripe webhooks are server-to-server and are never subject to
    | (or protected by) CORS.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
