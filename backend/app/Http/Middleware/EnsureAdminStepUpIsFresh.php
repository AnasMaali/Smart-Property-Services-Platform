<?php

namespace App\Http\Middleware;

use App\Models\AuthSession;
use App\Support\Admin\AdminSessionPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP-boundary WebAuthn step-up freshness gate for sensitive Admin routes
 * (BLUE V1 Phase A2.5), aliased `admin.stepup`. Reusable across future
 * sensitive operations exactly like `admin.capability` - no per-route
 * freshness logic is ever duplicated.
 *
 * MUST run after both `auth.admin` and `admin.capability:<code>` in a
 * route's middleware stack: `auth.admin` is what attaches `auth_session` to
 * the request (already re-validated this exact request - ACTIVE account,
 * active ADMIN/SUPER_ADMIN role, non-idle session), and per the Phase A2.5
 * spec, capability authorization must be checked before step-up freshness
 * ("step-up proves identity freshness, not authorization" - a caller who
 * lacks the capability entirely should never learn whether their step-up is
 * fresh). If `auth_session` is missing (a route misconfigured without
 * `auth.admin` first) this fails closed with the same rejection, rather
 * than assuming freshness.
 *
 * Delegates the actual freshness decision entirely to
 * App\Support\Admin\AdminSessionPolicy::isStepUpFresh() - the same class
 * AuthenticateAdmin/AdminRefreshTokenAction use for idle timeout, so
 * boundary semantics (BOUNDARY CONVENTION: "fresh while `now` is strictly
 * before the deadline") can never drift between the two.
 *
 * A step-up failure here does NOT revoke or otherwise affect the Admin
 * session - it only means this one sensitive action needs a fresh WebAuthn
 * proof first. The caller remains fully authenticated and can retry
 * immediately after completing POST /v1/admin/auth/step-up/request +
 * /verify.
 *
 * RESPONSE SHAPE DECISION: 403 (already used by `admin.capability` for
 * "you can never do this without a role/permission change") would make it
 * impossible for the Admin frontend to distinguish "no permission, don't
 * retry" from "you have permission, but must re-verify first" using the
 * status code alone. This middleware instead returns 428 Precondition
 * Required (RFC 6585) - the standards-track status for exactly this shape
 * of "the request needs an additional precondition satisfied first" - never
 * 401 (the session itself is genuinely still valid), together with a
 * machine-readable top-level `code: "STEP_UP_REQUIRED"` field the frontend
 * can key off directly (mirroring how validation failures already add a
 * top-level `errors` field to the base {success, message} envelope - see
 * App\Http\Requests\ApiFormRequest). No WebAuthn-internal detail (which
 * credential, why exactly it is stale, ...) is ever included.
 */
class EnsureAdminStepUpIsFresh
{
    private const STEP_UP_REQUIRED_MESSAGE = 'This action requires a fresh WebAuthn verification.';

    private const STEP_UP_REQUIRED_CODE = 'STEP_UP_REQUIRED';

    public function __construct(private readonly AdminSessionPolicy $sessionPolicy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->attributes->get('auth_session');

        if (! $session instanceof AuthSession) {
            return $this->stepUpRequired();
        }

        if (! $this->sessionPolicy->isStepUpFresh($session, now())) {
            return $this->stepUpRequired();
        }

        return $next($request);
    }

    private function stepUpRequired(): Response
    {
        return response()->json([
            'success' => false,
            'message' => self::STEP_UP_REQUIRED_MESSAGE,
            'code' => self::STEP_UP_REQUIRED_CODE,
        ], 428);
    }
}
