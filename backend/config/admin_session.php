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

    /*
    |--------------------------------------------------------------------------
    | Admin Step-Up Freshness Window
    |--------------------------------------------------------------------------
    |
    | Minutes after a successful WebAuthn STEP_UP ceremony that the current
    | ADMIN_WEB session's step-up remains "fresh" enough to satisfy
    | admin.stepup-protected routes (e.g. contracts.cancel) - BLUE V1 Phase
    | A2.5. Reusable within this window (one ceremony can cover several
    | sensitive actions), never extended by a refresh, and never counted at
    | login (auth_sessions.step_up_verified_at starts NULL every session -
    | see App\Actions\Auth\Concerns\IssuesAdminAuthSession).
    |
    | App\Support\Admin\AdminSessionPolicy::stepUpTtlMinutes() additionally
    | clamps this value to never exceed idle_timeout_minutes above, per the
    | Phase A2.5 requirement that the step-up window can never outlive the
    | idle window - a misconfigured value here can widen the freshness
    | window at most up to the idle timeout, never beyond it.
    |
    */

    'step_up_ttl_minutes' => (int) env('AUTH_ADMIN_STEP_UP_TTL_MINUTES', 5),

];
