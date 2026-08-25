<?php

namespace Tests\Support;

use App\Actions\Auth\Concerns\IssuesAdminAuthSession;
use App\Models\AuthSession;
use App\Models\User;
use App\Services\Auth\JwtTokenService;
use App\Support\Admin\AdminSessionPolicy;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;

/**
 * Mints a real authenticated ADMIN/SUPER_ADMIN session for test setup - via
 * the exact same production session-issuance code the real MFA-gated login
 * flow uses (App\Actions\Auth\Concerns\IssuesAdminAuthSession, the trait
 * AdminMfaVerifyAction uses) - without making an HTTP request to any login
 * endpoint and without performing a password or WebAuthn ceremony.
 *
 * BLUE V1 Phase A2.3 made Admin login MFA-gated: POST /v1/admin/auth/login
 * (Stage 1) no longer returns a session, and completing Stage 2 for real
 * requires an actual WebAuthn assertion. Every feature test that merely
 * needs "a logged in Admin" to exercise an unrelated endpoint (Bookings,
 * Technicians, Contracts, capability authorization, ...) should use this
 * trait instead of driving the real HTTP login flow - production route
 * design/security must never be shaped by test-fixture convenience.
 *
 * Tests that specifically exercise the Admin login/MFA flow itself still
 * call the real HTTP endpoints directly with real WebAuthn cryptography
 * (see AdminMfaLoginTest, using Tests\Support\WebAuthn\WebAuthnTestAuthenticator)
 * - this trait is only for tests where authentication is setup, not the
 * subject under test.
 */
trait AuthenticatesAdminsForTests
{
    use IssuesAdminAuthSession;

    // Named exactly `jwtTokenService`/`sessionPolicy` for the same reason
    // AuthenticatesCustomersForTests documents - IssuesAdminAuthSession
    // reads `$this->jwtTokenService` and (BLUE V1 Phase A2.4)
    // `$this->sessionPolicy` directly.
    private ?JwtTokenService $jwtTokenService = null;

    private ?AdminSessionPolicy $sessionPolicy = null;

    /**
     * @param  array<int, string>  $activeAdminRoleCodes
     * @return array{
     *     user_uuid: string,
     *     full_name: ?string,
     *     phone_number: string,
     *     email: string,
     *     role: string,
     *     roles: array<int, string>,
     *     session_uuid: string,
     *     access_token: string,
     *     access_token_expires_at: string,
     *     refresh_token: string,
     *     session_expires_at: string,
     * }
     */
    private function issueAdminSession(string $userUuid, array $activeAdminRoleCodes = ['ADMIN'], ?Carbon $now = null): array
    {
        $this->jwtTokenService ??= app(JwtTokenService::class);
        $this->sessionPolicy ??= app(AdminSessionPolicy::class);

        $user = User::where('id', UuidBinary::toBinary($userUuid))->firstOrFail();

        return $this->issueAdminAuthSession($user, $activeAdminRoleCodes, null, null, null, null, $now ?? now());
    }

    /**
     * Marks $sessionUuid's step_up_verified_at directly (BLUE V1 Phase
     * A2.5) - the same "mint/mutate session state directly rather than
     * driving a real ceremony" convention issueAdminSession() above already
     * establishes for login. For tests that merely need "this Admin session
     * already has a fresh step-up" as setup for an unrelated
     * admin.stepup-protected route (e.g. contracts.cancel), not for tests
     * that exercise the Step-Up ceremony itself (see AdminStepUpTest, which
     * drives the real HTTP endpoints with real WebAuthn cryptography).
     */
    private function markStepUpVerified(string $sessionUuid, ?Carbon $now = null): void
    {
        $this->sessionPolicy ??= app(AdminSessionPolicy::class);

        $session = AuthSession::where('id', UuidBinary::toBinary($sessionUuid))->firstOrFail();

        $this->sessionPolicy->markStepUpVerified($session, $now ?? now());
    }
}
