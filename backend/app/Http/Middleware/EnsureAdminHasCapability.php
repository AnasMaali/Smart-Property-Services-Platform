<?php

namespace App\Http\Middleware;

use App\Support\Admin\AdminAuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP-boundary capability gate for Admin operational routes (BLUE V1 Phase
 * A1), aliased `admin.capability` and parameterized with exactly one
 * capability code, e.g. `Route::middleware(AdminCapability::BOOKINGS_VIEW->middleware())`
 * (which resolves to `admin.capability:bookings.view`).
 *
 * MUST run after `auth.admin` in the route's middleware stack — it only
 * reads `auth_admin_roles`, already resolved and verified against the
 * database by `AuthenticateAdmin` on this exact request, and never
 * re-authenticates the request itself (authentication and authorization are
 * deliberately separate concerns — see AdminAuthorizationService's docblock).
 * If `auth_admin_roles` is missing or empty (a route misconfigured without
 * `auth.admin` first, or attached in the wrong order) this fails closed with
 * the same generic denial, rather than assuming access.
 *
 * Delegates the actual decision entirely to AdminAuthorizationService — this
 * class contains no role-name or capability-name branching of its own, so
 * controllers and this middleware both stay thin.
 */
class EnsureAdminHasCapability
{
    private const FORBIDDEN_MESSAGE = 'You are not authorized to perform this action.';

    public function __construct(private readonly AdminAuthorizationService $authorizationService) {}

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $activeAdminRoleCodes = $request->attributes->get('auth_admin_roles');

        if (! is_array($activeAdminRoleCodes) || $activeAdminRoleCodes === []) {
            return $this->deny();
        }

        if (! $this->authorizationService->authorize($activeAdminRoleCodes, $capability)) {
            return $this->deny();
        }

        return $next($request);
    }

    private function deny(): Response
    {
        return response()->json([
            'success' => false,
            'message' => self::FORBIDDEN_MESSAGE,
        ], 403);
    }
}
