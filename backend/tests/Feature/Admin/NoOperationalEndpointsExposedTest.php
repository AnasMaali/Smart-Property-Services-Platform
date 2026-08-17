<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * BLUE V1 Phase 9A built only the Admin authentication/authorization
 * security boundary. Phase 9B now exposes the actual Admin Operations
 * surface behind it (see docs/api-contracts/admin-operations-v1.md) - this
 * test was updated in lockstep with that route list. It still guards
 * against anything Phase 9B explicitly did NOT build: Admin Booking
 * cancellation/refund, a generic status-setter endpoint, and any route not
 * gated by `auth.admin`.
 *
 * A later phase added customer-facing Booking cancellation
 * (api/v1/bookings/{booking}/cancel). That single, explicitly named route
 * is carved out below - it is NOT an Admin operational route (see
 * CancelBookingController), never touches Stripe, and refund execution
 * stays MANUAL. Every other `cancel`/`refund` route - Admin ones in
 * particular - remains forbidden unless explicitly added here.
 *
 * BLUE V1 Phase 10E added exactly one Admin `cancel` route:
 * api/v1/admin/contracts/{contract}/cancel. This is Service Contract
 * lifecycle cancellation (App\Actions\Admin\Contract\AdminCancelContractAction)
 * - it never touches a Booking, Payment, or refund; it only stops a Contract
 * from authorizing further CONTRACT Bookings. It is carved out below for
 * that reason, the same way the customer Booking-cancel route already was.
 */
class NoOperationalEndpointsExposedTest extends TestCase
{
    use DatabaseTransactions;

    private const FORBIDDEN_URI_FRAGMENTS = [
        'cancel',
        'refund',
        'reprice',
    ];

    private const ALLOWED_URI_EXCEPTIONS = [
        'api/v1/bookings/{booking}/cancel',
        'api/v1/admin/contracts/{contract}/cancel',
    ];

    public function test_no_out_of_scope_operational_routes_are_registered(): void
    {
        $matches = [];

        foreach (Route::getRoutes() as $route) {
            $uri = strtolower($route->uri());

            if (in_array($uri, self::ALLOWED_URI_EXCEPTIONS, true)) {
                continue;
            }

            foreach (self::FORBIDDEN_URI_FRAGMENTS as $fragment) {
                if (str_contains($uri, $fragment)) {
                    $matches[] = $uri;
                }
            }
        }

        $this->assertSame([], $matches, 'Found unexpected operational route(s) registered: '.implode(', ', $matches));
    }

    public function test_no_generic_booking_status_setter_route_exists(): void
    {
        $hasGenericStatusRoute = collect(Route::getRoutes())
            ->contains(function ($route) {
                $uri = strtolower($route->uri());
                $methods = $route->methods();

                return $uri === 'api/v1/admin/bookings/{booking}'
                    && (in_array('PATCH', $methods, true) || in_array('PUT', $methods, true));
            });

        $this->assertFalse($hasGenericStatusRoute, 'A generic PATCH/PUT booking status-setter route must never exist - only named operations (complete, assign-technician, etc.) are allowed.');
    }

    public function test_only_the_expected_admin_routes_exist(): void
    {
        $adminUris = collect(Route::getRoutes())
            ->filter(fn ($route) => in_array('auth.admin', $route->gatherMiddleware(), true) || str_starts_with($route->uri(), 'api/v1/admin/auth'))
            ->map(fn ($route) => $route->uri())
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'api/v1/admin/auth/login',
            'api/v1/admin/auth/refresh',
            'api/v1/admin/booking-items/{bookingItem}/assign-technician',
            'api/v1/admin/booking-items/{bookingItem}/complete-work',
            'api/v1/admin/booking-items/{bookingItem}/reassign-technician',
            'api/v1/admin/booking-items/{bookingItem}/start-work',
            'api/v1/admin/booking-items/{bookingItem}/technician-candidates',
            'api/v1/admin/bookings',
            'api/v1/admin/bookings/{booking}',
            'api/v1/admin/contracts',
            'api/v1/admin/contracts/{contract}',
            'api/v1/admin/contracts/{contract}/approve',
            'api/v1/admin/contracts/{contract}/cancel',
            'api/v1/admin/contracts/{contract}/send-for-acceptance',
            'api/v1/admin/contracts/{contract}/suspend',
            'api/v1/admin/me',
            'api/v1/admin/technicians',
        ], $adminUris);
    }

    public function test_every_admin_operations_route_requires_auth_admin_middleware(): void
    {
        $operationalUris = [
            'api/v1/admin/bookings',
            'api/v1/admin/bookings/{booking}',
            'api/v1/admin/technicians',
            'api/v1/admin/booking-items/{bookingItem}/technician-candidates',
            'api/v1/admin/booking-items/{bookingItem}/assign-technician',
            'api/v1/admin/booking-items/{bookingItem}/reassign-technician',
            'api/v1/admin/booking-items/{bookingItem}/start-work',
            'api/v1/admin/booking-items/{bookingItem}/complete-work',
            'api/v1/admin/contracts',
            'api/v1/admin/contracts/{contract}',
            'api/v1/admin/contracts/{contract}/approve',
            'api/v1/admin/contracts/{contract}/cancel',
            'api/v1/admin/contracts/{contract}/send-for-acceptance',
            'api/v1/admin/contracts/{contract}/suspend',
        ];

        foreach (Route::getRoutes() as $route) {
            if (in_array($route->uri(), $operationalUris, true)) {
                $this->assertContains('auth.admin', $route->gatherMiddleware(), "Route {$route->uri()} must be gated by auth.admin.");
            }
        }
    }
}
