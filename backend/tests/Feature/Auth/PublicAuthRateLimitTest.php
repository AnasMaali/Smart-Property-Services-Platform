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
            'api/v1/auth/login' => 'throttle:auth-login',
            'api/v1/auth/refresh' => 'throttle:auth-refresh',
            'api/v1/auth/forgot-password' => 'throttle:auth-otp-issue',
            'api/v1/auth/verify-password-reset-otp' => 'throttle:auth-otp-verify',
            'api/v1/auth/reset-password' => 'throttle:auth-reset',
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
}
