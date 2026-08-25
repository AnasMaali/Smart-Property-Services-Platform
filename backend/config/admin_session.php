<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin Absolute Session Lifetime
    |--------------------------------------------------------------------------
    |
    | Hours from the moment of a successful MFA-issued Admin login until
    | auth_sessions.expires_at, for ADMIN_WEB sessions only - deliberately
    | much shorter than Customer sessions' AUTH_SESSION_TTL_DAYS (30 days),
    | since an Admin session carries materially more risk. Never extended by
    | refresh - see App\Support\Admin\AdminSessionPolicy.
    |
    */

    'session_ttl_hours' => (int) env('AUTH_ADMIN_SESSION_TTL_HOURS', 12),

    /*
    |--------------------------------------------------------------------------
    | Admin Idle Timeout
    |--------------------------------------------------------------------------
    |
    | Minutes of inactivity (no authenticated Admin API request) after which
    | an ADMIN_WEB session is treated as idle-expired and revoked, regardless
    | of how much of the absolute session lifetime remains. Enforced
    | identically by both AuthenticateAdmin (Bearer requests) and
    | AdminRefreshTokenAction (refresh) via the shared
    | App\Support\Admin\AdminSessionPolicy - a silent token refresh never
    | resets this clock.
    |
    */

    'idle_timeout_minutes' => (int) env('AUTH_ADMIN_IDLE_TIMEOUT_MINUTES', 20),

    /*
    |--------------------------------------------------------------------------
    | Admin Activity Touch Interval
    |--------------------------------------------------------------------------
    |
    | Minutes that must have elapsed since auth_sessions.last_used_at was
    | last written before a genuine authenticated Admin API request (never a
    | refresh) is allowed to update it again. Throttles the activity-tracking
    | write to roughly once per this interval instead of once per request -
    | see App\Support\Admin\AdminSessionPolicy::touchIfDue().
    |
    */

    'activity_touch_minutes' => (int) env('AUTH_ADMIN_ACTIVITY_TOUCH_MINUTES', 5),

];
