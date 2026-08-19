<?php

namespace App\Http\Middleware;

use App\Models\AuthSession;
use App\Models\User;
use App\Services\Auth\JwtTokenService;
use App\Support\Uuid\UuidBinary;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates an access token against every rule required for a protected
 * customer request (see docs/06-technical-system-design/02-authentication-
 * session-and-token-design.md §7): signature, exp/nbf, a backing
 * auth_sessions row that is not revoked and not expired, an ACTIVE user, and
 * an active CUSTOMER role.
 *
 * This performs read-only validation - it never locks or mutates rows. Any
 * endpoint that acts on the resolved user/session under lock (change
 * password, change phone number, ...) must still re-fetch and lock those
 * rows itself inside its own transaction, the same "unlocked read, then
 * locked re-validate" pattern already used throughout this codebase (see
 * VerifyPhoneAction, ResendPhoneOtpAction).
 *
 * On success, the resolved User and AuthSession models are attached to the
 * request as `auth_user` / `auth_session` attributes for the controller to
 * pass into its Action.
 *
 * Also requires the backing session's own client_type_id to be one of the
 * mobile client types. Role membership alone is not a sufficient boundary:
 * a user who holds both an Admin role and CUSTOMER would otherwise be able
 * to use an access token issued by the Admin Web login (ADMIN_WEB) against
 * these customer routes, since the role check below only looks at live
 * role rows, not at how the presented session was issued. Checking
 * client_type_id off the server-side auth_sessions row (never the JWT's
 * own `client` claim) is what actually enforces the Customer-mobile-only
 * boundary documented in routes/api.php.
 */
class AuthenticateCustomer
{
    private const GENERIC_INVALID_MESSAGE = 'This session is invalid or has expired.';

    private const CLIENT_TYPE_CODES = ['MOBILE_IOS', 'MOBILE_ANDROID'];

    public function __construct(private readonly JwtTokenService $jwtTokenService) {}

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

        $user = User::where('id', $userId)->first();

        if ($user === null || $user->account_status_id !== $this->lookupId('user_account_statuses', 'ACTIVE')) {
            return $this->reject();
        }

        $hasActiveCustomerRole = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $userId)
            ->where('roles.code', 'CUSTOMER')
            ->where('roles.is_active', 1)
            ->exists();

        if (! $hasActiveCustomerRole) {
            return $this->reject();
        }

        $clientType = DB::table('auth_client_types')
            ->where('id', $session->client_type_id)
            ->first();

        if ($clientType === null || ! $clientType->is_active || ! in_array($clientType->code, self::CLIENT_TYPE_CODES, true)) {
            return $this->reject();
        }

        $request->attributes->set('auth_user', $user);
        $request->attributes->set('auth_session', $session);

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
