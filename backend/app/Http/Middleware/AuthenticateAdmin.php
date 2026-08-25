<?php

namespace App\Http\Middleware;

use App\Models\AuthSession;
use App\Models\User;
use App\Services\Auth\JwtTokenService;
use App\Support\Admin\AdminSessionPolicy;
use App\Support\Uuid\UuidBinary;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates an access token against every rule required for a protected
 * Admin request: signature, exp/nbf, a backing auth_sessions row that is not
 * revoked and not expired, an ACTIVE user, and at least one currently active
 * ADMIN or SUPER_ADMIN role.
 *
 * Mirrors AuthenticateCustomer (see that class for the shared design notes)
 * but authorizes against ADMIN/SUPER_ADMIN instead of CUSTOMER, and does not
 * require phone_verified_at - Admin accounts are provisioned directly, not
 * through the OTP-verified customer registration flow.
 *
 * Role membership is re-read from user_roles/roles on every request; the
 * JWT's `role` claim is never trusted for authorization. If an Admin's role
 * is revoked or the account is deactivated after a token was issued, the
 * very next request using that token is rejected here even though the token
 * itself is still cryptographically valid and unexpired.
 *
 * Also requires the backing session's own client_type_id to be ADMIN_WEB.
 * Role membership alone is not a sufficient boundary: a user who holds both
 * CUSTOMER and an Admin role would otherwise be able to use an access token
 * issued by the ordinary Customer mobile login (MOBILE_IOS/MOBILE_ANDROID)
 * against Admin routes, since that check only looks at live role rows, not
 * at how the presented session was issued. Checking client_type_id off the
 * server-side auth_sessions row (never the JWT's own `client` claim, which
 * is unauthenticated caller-controlled-at-issuance data) is what actually
 * enforces the Admin-Web-only boundary documented in routes/api.php.
 *
 * On success, the resolved User and AuthSession models are attached to the
 * request as `auth_user` / `auth_session`, and the caller's currently active
 * Admin role codes as `auth_admin_roles` (e.g. ['ADMIN'] or ['ADMIN',
 * 'SUPER_ADMIN']) for downstream Admin controllers/Actions to consult.
 *
 * BLUE V1 Phase A2.4: also enforces the Admin idle timeout via the shared
 * App\Support\Admin\AdminSessionPolicy - an idle-expired session is revoked
 * and rejected here exactly as App\Actions\Auth\AdminRefreshTokenAction does
 * for refresh, so the policy cannot drift between the two enforcement
 * points. On a request that passes every check, the session's activity
 * timestamp is touched (throttled - see AdminSessionPolicy::touchIfDue()),
 * never on every single request.
 */
class AuthenticateAdmin
{
    private const GENERIC_INVALID_MESSAGE = 'This session is invalid or has expired.';

    private const ADMIN_ROLE_CODES = ['ADMIN', 'SUPER_ADMIN'];

    private const CLIENT_TYPE_CODE = 'ADMIN_WEB';

    public function __construct(
        private readonly JwtTokenService $jwtTokenService,
        private readonly AdminSessionPolicy $sessionPolicy,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $accessToken = $request->bearerToken();

        if ($accessToken === null || $accessToken === '') {
            return $this->reject();
        }

        $decoded = $this->jwtTokenService->decodeAccessToken($accessToken);

        if ($decoded === null) {
            return $this->reject();
        }

        try {
            $sessionId = UuidBinary::toBinary($decoded->sid);
            $userId = UuidBinary::toBinary($decoded->sub);
        } catch (InvalidArgumentException) {
            return $this->reject();
        }

        $session = AuthSession::where('id', $sessionId)->first();

        if ($session === null || $session->user_id !== $decoded->sub) {
            return $this->reject();
        }

        $now = now();

        if ($session->revoked_at !== null || $session->expires_at->lessThanOrEqualTo($now)) {
            return $this->reject();
        }

        if ($this->sessionPolicy->enforceIdleTimeout($session, $now)) {
            return $this->reject();
        }

        $user = User::where('id', $userId)->first();

        if ($user === null || $user->account_status_id !== $this->lookupId('user_account_statuses', 'ACTIVE')) {
            return $this->reject();
        }

        $activeAdminRoleCodes = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $userId)
            ->whereIn('roles.code', self::ADMIN_ROLE_CODES)
            ->where('roles.is_active', 1)
            ->pluck('roles.code')
            ->all();

        if ($activeAdminRoleCodes === []) {
            return $this->reject();
        }

        $clientType = DB::table('auth_client_types')
            ->where('id', $session->client_type_id)
            ->first();

        if ($clientType === null || ! $clientType->is_active || $clientType->code !== self::CLIENT_TYPE_CODE) {
            return $this->reject();
        }

        $this->sessionPolicy->touchIfDue($session, $now);

        $request->attributes->set('auth_user', $user);
        $request->attributes->set('auth_session', $session);
        $request->attributes->set('auth_admin_roles', $activeAdminRoleCodes);

        return $next($request);
    }

    private function reject(): Response
    {
        return response()->json([
            'success' => false,
            'message' => self::GENERIC_INVALID_MESSAGE,
        ], 401);
    }

    private function lookupId(string $table, string $code): int
    {
        $id = DB::table($table)->where('code', $code)->value('id');

        if ($id === null) {
            throw new RuntimeException("Missing required reference row: {$table}.code = {$code}");
        }

        return (int) $id;
    }
}
