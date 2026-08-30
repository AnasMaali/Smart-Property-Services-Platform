<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Relying Party Name
    |--------------------------------------------------------------------------
    |
    | Human-readable name shown by the authenticator/OS during registration
    | and assertion prompts. Not security-sensitive.
    |
    */

    'rp_name' => env('ADMIN_WEBAUTHN_RP_NAME', 'BLUE Admin'),

    /*
    |--------------------------------------------------------------------------
    | Relying Party ID
    |--------------------------------------------------------------------------
    |
    | The WebAuthn RP ID (a registrable domain suffix, e.g. "admin.example.com"
    | or "example.com"). Security-critical: every credential is permanently
    | bound to this exact value at registration time. Deliberately has NO
    | default - unlike CORS_ALLOWED_ORIGINS (config/cors.php), which defaults
    | to "*" for local-dev convenience, WebAuthn origin/RP validation is a
    | hard cryptographic security boundary (the actual defense against
    | phishing), never a browser-relaxation concern, so it must never have a
    | permissive or guessed default in ANY environment, local dev included.
    | AdminWebAuthnConfig throws if this is unset when first used.
    |
    */

    'rp_id' => env('ADMIN_WEBAUTHN_RP_ID'),

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | Comma-separated, exact-match origin allowlist (e.g.
    | "https://admin.example.com"), passed directly to the WebAuthn library's
    | own origin-validation ceremony step - never derived from any request
    | header (Origin/Host/X-Forwarded-*), which are caller-controlled and
    | therefore hostile input for this exact purpose. Deliberately has NO
    | default; an unset/empty value is treated as "not configured" and every
    | ceremony is refused (fail closed) rather than falling back to a
    | best-effort guess.
    |
    */

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ADMIN_WEBAUTHN_ORIGINS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Challenge TTL
    |--------------------------------------------------------------------------
    |
    | Seconds a generated WebAuthn challenge (registration, login assertion,
    | or step-up) remains valid before AdminWebAuthnChallengeService rejects
    | it as expired. Not security-critical to configure precisely (unlike
    | rp_id/allowed_origins above), so a safe operational default is fine.
    |
    */

    'challenge_ttl_seconds' => (int) env('ADMIN_WEBAUTHN_CHALLENGE_TTL_SECONDS', 300),

];
