<?php

namespace Tests\Support;

use App\Actions\Auth\Concerns\IssuesAuthSession;
use App\Models\User;
use App\Services\Auth\JwtTokenService;
use App\Support\Uuid\UuidBinary;

/**
 * Mints a real authenticated CUSTOMER session for test setup - via the
 * exact same production session-issuance code every real login path uses
 * (App\Actions\Auth\Concerns\IssuesAuthSession, the trait LoginAction and
 * VerifyLoginOtpAction both use) - without making an HTTP request to any
 * login endpoint and without any password check.
 *
 * Replaces the previous test-wide pattern of calling
 * POST /v1/auth/login (password) purely as a fixture shortcut to obtain an
 * authenticated session. That endpoint has been removed from the public
 * Customer contract; production route design should never be shaped by
 * test-fixture convenience. Every feature test that merely needs "a logged
 * in customer" to exercise an unrelated endpoint (Cart, Profile, Change
 * Password, Logout, Refresh, etc.) should use this trait instead.
 *
 * Tests that specifically exercise the Login/Login-OTP feature itself
 * still call the real login endpoints directly (see LoginOtpTest) - this
 * trait is only for tests where authentication is setup, not the subject
 * under test.
 */
trait AuthenticatesCustomersForTests
{
    use IssuesAuthSession;

    // Named exactly `jwtTokenService` because IssuesAuthSession::issueAuthSession()
    // reads `$this->jwtTokenService` directly - every production Action that
    // uses the trait defines a constructor-promoted property of this exact
    // name, so this trait must match it rather than relying on PHP magic
    // methods (`__get` cannot be aliased to a different property name).
    private ?JwtTokenService $jwtTokenService = null;

    /**
     * @return array{
     *     user_uuid: string,
     *     full_name: ?string,
     *     phone_number: string,
     *     email: string,
     *     role: string,
     *     session_uuid: string,
     *     access_token: string,
     *     access_token_expires_at: string,
     *     refresh_token: string,
     *     session_expires_at: string,
     * }
     */
    private function issueCustomerSession(string $userUuid, string $clientType = 'MOBILE_IOS'): array
    {
        $this->jwtTokenService ??= app(JwtTokenService::class);

        $user = User::where('id', UuidBinary::toBinary($userUuid))->firstOrFail();

        return $this->issueAuthSession($user, $clientType, null, null, null, null, now());
    }
}
