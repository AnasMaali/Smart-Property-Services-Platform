<?php

namespace App\Support\Admin;

/**
 * Transaction-time authorization result for privileged Admin mutations.
 *
 * Middleware authorization remains the first HTTP boundary, but privileged
 * mutations re-check authority while holding database read locks so account
 * deactivation, role removal, or global role deactivation cannot race a
 * mutation after middleware has already allowed the request through.
 */
enum AdminMutationAuthorizationOutcome: string
{
    case AUTHORIZED = 'AUTHORIZED';
    case ACTOR_NOT_FOUND = 'ACTOR_NOT_FOUND';
    case ACTOR_NOT_AUTHORIZED = 'ACTOR_NOT_AUTHORIZED';
}
