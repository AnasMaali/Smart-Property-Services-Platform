<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * BLUE V1 Phase 9A builds only the Admin authentication/authorization
 * security boundary - not the Admin operational APIs that will sit behind
 * it in Phase 9B. This guards against accidentally exposing any of those
 * routes (technician assignment/start/complete from Phase 8A/8B, or Booking
 * management) while wiring up the new Admin auth surface.
 */
class NoOperationalEndpointsExposedTest extends TestCase
{
    use DatabaseTransactions;

    private const FORBIDDEN_URI_FRAGMENTS = [
        'assign',
        'reassign',
        'start-work',
        'complete-work',
        'start-job',
        'complete-job',
        'technician',
        'cancel',
    ];

    public function test_no_technician_or_booking_management_routes_are_registered(): void
    {
        $matches = [];

        foreach (Route::getRoutes() as $route) {
            $uri = strtolower($route->uri());

            foreach (self::FORBIDDEN_URI_FRAGMENTS as $fragment) {
                if (str_contains($uri, $fragment)) {
                    $matches[] = $uri;
                }
            }
        }

        $this->assertSame([], $matches, 'Found unexpected operational route(s) registered: '.implode(', ', $matches));
    }

    public function test_only_the_expected_admin_routes_exist(): void
    {
        $adminUris = collect(Route::getRoutes())
            ->map(fn ($route) => $route->uri())
            ->filter(fn ($uri) => str_starts_with($uri, 'api/v1/admin'))
            ->values()
            ->sort()
            ->all();

        $this->assertSame([
            'api/v1/admin/auth/login',
            'api/v1/admin/auth/refresh',
            'api/v1/admin/me',
        ], collect($adminUris)->unique()->sort()->values()->all());
    }
}
