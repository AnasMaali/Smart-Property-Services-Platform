<?php

namespace App\Support\Admin\WebAuthn;

/**
 * Outcomes for AdminWebAuthnRegistrationService (BLUE V1 Phase A2.2).
 * ELIGIBLE/REGISTERED are success signals for options()/verify()
 * respectively; the rest are shared rejection reasons either method can
 * return. CHALLENGE_INVALID and VERIFICATION_FAILED are each deliberately
 * generic - see AdminWebAuthnChallengeOutcome and
 * AdminWebAuthnRegistrationService's own docblock for why granular reasons
 * are never surfaced to a caller.
 */
enum AdminWebAuthnRegistrationOutcome
{
    case ELIGIBLE;
    case REGISTERED;
    case ACTOR_NOT_ELIGIBLE;
    case STEP_UP_REQUIRED;
    case CHALLENGE_INVALID;
    case VERIFICATION_FAILED;
    case DUPLICATE_CREDENTIAL;
}
