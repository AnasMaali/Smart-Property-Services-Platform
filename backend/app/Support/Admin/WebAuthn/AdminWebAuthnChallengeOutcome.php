<?php

namespace App\Support\Admin\WebAuthn;

/**
 * Outcome of AdminWebAuthnChallengeService::consume() (BLUE V1 Phase A2.2).
 * Every non-VALID case is deliberately generic - the caller-facing behavior
 * for "no such challenge", "already used", and "expired" should look
 * identical to avoid handing an attacker a timing/existence oracle, exactly
 * like AdminMutationAuthorizationOutcome collapses several rejection
 * reasons into ACTOR_NOT_AUTHORIZED.
 */
enum AdminWebAuthnChallengeOutcome
{
    case VALID;
    case NOT_FOUND;
    case EXPIRED;
    case ALREADY_CONSUMED;
}
