<?php

namespace Tests\Feature\Auth;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class PublicAuthRateLimitTest extends TestCase
{
    public function test_sensitive_public_auth_routes_have_expected_rate_limiters(): void
    {
        $expected = [
            'api/v1/auth/register' => 'throttle:auth-register',
            'api/v1/auth/verify-phone' => 'throttle:auth-otp-verify',
            'api/v1/auth/resend-otp' => 'throttle:auth-otp-issue',
            'api/v1/auth/login/request-otp' => 'throttle:auth-login-otp-issue',
            'api/v1/auth/login/verify-otp' => 'throttle:auth-login-otp-verify',
            'api/v1/auth/login/resend-otp' => 'throttle:auth-login-otp-issue',
            'api/v1/auth/refresh' => 'throttle:auth-refresh',
            'api/v1/admin/auth/login' => 'throttle:admin-auth-login',
            'api/v1/admin/auth/mfa/enroll' => 'throttle:admin-auth-mfa-enroll',
            'api/v1/admin/auth/mfa/verify' => 'throttle:admin-auth-mfa-verify',
            'api/v1/admin/auth/refresh' => 'throttle:auth-refresh',
        ];

        foreach ($expected as $uri => $expectedMiddleware) {
            $route = collect(RouteFacade::getRoutes())
                ->first(function (Route $route) use ($uri): bool {
                    return $route->uri() === $uri
                        && in_array('POST', $route->methods(), true);
                });

            $this->assertNotNull(
                $route,
                "Expected public Auth POST route [{$uri}] was not registered."
            );

            $this->assertContains(
                $expectedMiddleware,
                $route->middleware(),
                "Public Auth route [{$uri}] is missing [{$expectedMiddleware}]."
            );
        }
    }

    // Regression guard for the BLUE V1 passwordless-login product decision:
    // the password-based Customer login route must never come back as a
    // registered route, under either its old path or method.
    public function test_password_customer_login_route_no_longer_exists(): void
    {
        $route = collect(RouteFacade::getRoutes())
            ->first(function (Route $route): bool {
                return $route->uri() === 'api/v1/auth/login'
                    && in_array('POST', $route->methods(), true);
            });

        $this->assertNull($route, 'The deprecated password Customer login route must not be registered.');

        $this->postJson('/api/v1/auth/login', [
            'phone_number' => '+971500001234',
            'password' => 'irrelevant',
            'client_type' => 'MOBILE_IOS',
        ])->assertStatus(404);
    }

    public function test_legacy_customer_password_routes_no_longer_exist(): void
    {
        foreach ([
            'api/v1/auth/forgot-password',
            'api/v1/auth/verify-password-reset-otp',
            'api/v1/auth/reset-password',
            'api/v1/auth/change-password',
        ] as $uri) {
            $route = collect(RouteFacade::getRoutes())
                ->first(function (Route $route) use ($uri): bool {
                    return $route->uri() === $uri;
                });

            $this->assertNull(
                $route,
                "Legacy customer password route [{$uri}] must not be registered."
            );
        }
    }

    // The equivalent defense-in-depth boundary for the authenticated
    // phone-number-change OTP flow - added alongside the public routes
    // above since it proves the same "sensitive OTP-adjacent route has an
    // HTTP-layer throttle" invariant, just behind auth.customer instead of
    // being anonymous.
    public function test_authenticated_phone_number_change_routes_have_expected_rate_limiters(): void
    {
        $expected = [
            'api/v1/auth/change-phone-number' => 'throttle:auth-phone-change-issue',
            'api/v1/auth/verify-phone-number-change-otp' => 'throttle:auth-phone-change-verify',
            'api/v1/auth/resend-phone-number-change-otp' => 'throttle:auth-phone-change-issue',
        ];

        foreach ($expected as $uri => $expectedMiddleware) {
            $route = collect(RouteFacade::getRoutes())
                ->first(function (Route $route) use ($uri): bool {
                    return $route->uri() === $uri
                        && in_array('POST', $route->methods(), true);
                });

            $this->assertNotNull(
                $route,
                "Expected authenticated phone-number-change route [{$uri}] was not registered."
            );

            $this->assertContains(
                'auth.customer',
                $route->middleware(),
                "Phone-number-change route [{$uri}] must remain behind auth.customer."
            );

            $this->assertContains(
                $expectedMiddleware,
                $route->middleware(),
                "Phone-number-change route [{$uri}] is missing [{$expectedMiddleware}]."
            );
        }
    }

    public function test_authenticated_account_deletion_otp_routes_have_expected_rate_limiters(): void
    {
        $expected = [
            'api/v1/auth/account/request-otp' => 'throttle:auth-account-deletion-otp-issue',
            'api/v1/auth/account/resend-otp' => 'throttle:auth-account-deletion-otp-issue',
        ];

        foreach ($expected as $uri => $expectedMiddleware) {
            $route = collect(RouteFacade::getRoutes())
                ->first(function (Route $route) use ($uri): bool {
                    return $route->uri() === $uri
                        && in_array('POST', $route->methods(), true);
                });

            $this->assertNotNull(
                $route,
                "Expected account-deletion OTP route [{$uri}] was not registered."
            );

            $this->assertContains(
                'auth.customer',
                $route->middleware(),
                "Account-deletion OTP route [{$uri}] must remain behind auth.customer."
            );

            $this->assertContains(
                $expectedMiddleware,
                $route->middleware(),
                "Account-deletion OTP route [{$uri}] is missing [{$expectedMiddleware}]."
            );
        }
    }

    // Proves the safe-by-default trusted-proxy boundary
    // (bootstrap/app.php's env-driven TRUSTED_PROXIES) actually protects
    // the IP-keyed half of these limiters: with no trusted proxy configured
    // (the test environment's own state, matching an unconfigured
    // production deployment), Laravel must ignore X-Forwarded-For entirely,
    // so a client cannot claim a fresh IP on every request to evade the
    // per-IP bucket. auth/refresh's limiter (60/min, IP-only, no identity
    // dimension) isolates exactly this mechanism.
    public function test_spoofed_forwarded_for_header_cannot_evade_ip_rate_limiting(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/v1/auth/refresh', [
                'refresh_token' => 'invalid-token-for-rate-limit-test',
            ], [
                'X-Forwarded-For' => '203.0.113.'.$i,
            ]);
        }

        $blocked = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => 'invalid-token-for-rate-limit-test',
        ], [
            'X-Forwarded-For' => '203.0.113.250',
        ]);

        $blocked->assertStatus(429);
    }
}
