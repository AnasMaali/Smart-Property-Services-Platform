<?php

namespace App\Support\Admin\WebAuthn;

/**
 * Stable WebAuthn challenge purpose codes (BLUE V1 Phase A2.1/A2.2). Each
 * case's backing value is the exact `admin_webauthn_challenge_purposes.code`
 * row it represents (see `database/blue_v1_seed.sql` §2C) - mirrors
 * App\Support\Admin\AdminCapability's pattern from Phase A1.
 */
enum AdminWebAuthnChallengePurpose: string
{
    case REGISTRATION = 'REGISTRATION';
    case LOGIN_ASSERTION = 'LOGIN_ASSERTION';
    case STEP_UP = 'STEP_UP';
}
