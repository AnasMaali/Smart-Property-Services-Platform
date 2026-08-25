<?php

namespace App\Support\Admin\WebAuthn;

/**
 * Outcomes for AdminWebAuthnAssertionService (BLUE V1 Phase A2.2), shared by
 * LOGIN_ASSERTION and STEP_UP - the same generic-per-cause philosophy as
 * AdminWebAuthnRegistrationOutcome: an unknown, wrong-owner, or revoked
 * credential are all CREDENTIAL_NOT_FOUND; a bad/expired/replayed challenge
 * is CHALLENGE_INVALID; every ceremony rejection (origin, RP ID, signature,
 * user verification, counter/clone signal) is VERIFICATION_FAILED.
 */
enum AdminWebAuthnAssertionOutcome
{
    case VERIFIED;
    case CREDENTIAL_NOT_FOUND;
    case CHALLENGE_INVALID;
    case VERIFICATION_FAILED;
}
