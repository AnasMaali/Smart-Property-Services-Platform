<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Api\V1\Admin\Booking\UpdateAdminBookingController;
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
 * CancelBookingController). BLUE V1 Phase B20 below made its refund
 * execution automatic via Stripe; this test only ever guards the route
 * surface, never the execution mechanism. Every other `cancel`/`refund`
 * route - Admin ones in particular - remains forbidden unless explicitly
 * added here.
 *
 * BLUE V1 Phase 10E added exactly one Admin `cancel` route:
 * api/v1/admin/contracts/{contract}/cancel. This is Service Contract
 * lifecycle cancellation (App\Actions\Admin\Contract\AdminCancelContractAction)
 * - it never touches a Booking, Payment, or refund; it only stops a Contract
 * from authorizing further CONTRACT Bookings. It is carved out below for
 * that reason, the same way the customer Booking-cancel route already was.
 *
 * BLUE V1 Phase B16 added exactly one Admin Booking `cancel` route:
 * api/v1/admin/bookings/{booking}/cancel
 * (App\Actions\Admin\Booking\AdminCancelBookingAction). It is a thin,
 * `bookings.cancel`-gated wrapper over the SAME App\Actions\Booking\
 * CancelBookingAction cascade the customer route above already uses -
 * never a second cancellation/refund implementation - so it is carved out
 * here for the same reason.
 *
 * BLUE V1 Phase B20 (Automated Booking Refunds via Stripe) replaced manual
 * refund execution with automatic Stripe refunds on both cancel routes
 * above, and added exactly two READ-ONLY preview routes whose URI happens
 * to contain "cancel" (`cancellation-preview`):
 * api/v1/bookings/{booking}/cancellation-preview (App\Actions\Booking\
 * PreviewBookingCancellationAction, customer, ownership-scoped) and
 * api/v1/admin/bookings/{booking}/cancellation-preview (the same Action,
 * Admin, `bookings.cancel`-gated). Neither ever mutates a Booking, calls
 * Stripe, or duplicates the cancellation policy - both are carved out here
 * for the same reason as the cancel routes themselves.
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
        'api/v1/bookings/{booking}/cancellation-preview',
        'api/v1/admin/contracts/{contract}/cancel',
        'api/v1/admin/bookings/{booking}/cancel',
        'api/v1/admin/bookings/{booking}/cancellation-preview',
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

    /**
     * BLUE V1 Phase B15 added exactly one PATCH /v1/admin/bookings/{booking}
     * route - the scoped Edit Booking operation (App\Actions\Admin\Booking\
     * AdminUpdateBookingAction), gated by its own `bookings.manage`
     * capability. It is intentionally NOT a generic status-setter: its
     * FormRequest (App\Http\Requests\Admin\UpdateAdminBookingRequest) only
     * ever validates the eight operational visit/location fields, so a
     * `status` (or any other) key in the body is simply dropped by
     * `$request->validated()` and can never reach a write - see
     * AdminFinancialIsolationTest::test_no_generic_admin_status_endpoint_exists
     * for the behavioral proof against a real Booking. This test only
     * confirms no OTHER, unnamed PUT/PATCH route was added beyond that one.
     */
    public function test_no_generic_booking_status_setter_route_exists_beyond_the_scoped_edit_booking_route(): void
    {
        $unexpectedRoute = collect(Route::getRoutes())
            ->contains(function ($route) {
                $uri = strtolower($route->uri());
                $methods = $route->methods();

                if ($uri !== 'api/v1/admin/bookings/{booking}') {
                    return false;
                }

                if (! (in_array('PATCH', $methods, true) || in_array('PUT', $methods, true))) {
                    return false;
                }

                return $route->getActionName() !== UpdateAdminBookingController::class;
            });

        $this->assertFalse($unexpectedRoute, 'Only the scoped Edit Booking controller may handle PATCH/PUT /v1/admin/bookings/{booking}.');
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
            'api/v1/admin/appointment-slots',
            'api/v1/admin/audit-logs',
            'api/v1/admin/audit-logs/{auditLog}',
            'api/v1/admin/auth/login',
            'api/v1/admin/auth/mfa/enroll',
            'api/v1/admin/auth/mfa/verify',
            'api/v1/admin/auth/refresh',
            'api/v1/admin/auth/step-up/request',
            'api/v1/admin/auth/step-up/verify',
            'api/v1/admin/booking-items/{bookingItem}/assign-technician',
            'api/v1/admin/booking-items/{bookingItem}/complete-work',
            'api/v1/admin/booking-items/{bookingItem}/reassign-technician',
            'api/v1/admin/booking-items/{bookingItem}/start-work',
            'api/v1/admin/booking-items/{bookingItem}/technician-candidates',
            'api/v1/admin/bookings',
            'api/v1/admin/bookings/{booking}',
            'api/v1/admin/bookings/{booking}/cancel',
            'api/v1/admin/bookings/{booking}/cancellation-preview',
            'api/v1/admin/bookings/{booking}/force-complete',
            'api/v1/admin/bookings/{booking}/reschedule',
            'api/v1/admin/contract-billings',
            'api/v1/admin/contract-billings/{billing}',
            'api/v1/admin/contracts',
            'api/v1/admin/contracts/{contract}',
            'api/v1/admin/contracts/{contract}/approve',
            'api/v1/admin/contracts/{contract}/cancel',
            'api/v1/admin/contracts/{contract}/send-for-acceptance',
            'api/v1/admin/contracts/{contract}/suspend',
            'api/v1/admin/customers',
            'api/v1/admin/customers/{customer}',
            'api/v1/admin/dashboard',
            'api/v1/admin/me',
            'api/v1/admin/payments',
            'api/v1/admin/payments/{payment}',
            'api/v1/admin/pricing-schemes',
            'api/v1/admin/pricing-schemes/{pricingScheme}',
            'api/v1/admin/pricing-schemes/{pricingScheme}/publish',
            'api/v1/admin/pricing-schemes/{pricingScheme}/rules',
            'api/v1/admin/pricing-schemes/{pricingScheme}/rules/{rule}',
            'api/v1/admin/properties/{property}',
            'api/v1/admin/ratings',
            'api/v1/admin/ratings/{booking}',
            'api/v1/admin/service-categories',
            'api/v1/admin/service-categories/{category}',
            'api/v1/admin/service-categories/{category}/activate',
            'api/v1/admin/service-categories/{category}/deactivate',
            'api/v1/admin/service-media/{media}/activate',
            'api/v1/admin/service-media/{media}/deactivate',
            'api/v1/admin/service-option-choices/{choice}',
            'api/v1/admin/service-option-choices/{choice}/activate',
            'api/v1/admin/service-option-choices/{choice}/deactivate',
            'api/v1/admin/service-options/{option}',
            'api/v1/admin/service-options/{option}/activate',
            'api/v1/admin/service-options/{option}/choices',
            'api/v1/admin/service-options/{option}/deactivate',
            'api/v1/admin/services',
            'api/v1/admin/services/{service}',
            'api/v1/admin/services/{service}/activate',
            'api/v1/admin/services/{service}/category',
            'api/v1/admin/services/{service}/current-price',
            'api/v1/admin/services/{service}/deactivate',
            'api/v1/admin/services/{service}/media',
            'api/v1/admin/services/{service}/options',
            'api/v1/admin/services/{service}/original-price',
            'api/v1/admin/services/{service}/specializations',
            'api/v1/admin/specializations',
            'api/v1/admin/support-requests',
            'api/v1/admin/support-requests/{supportRequest}',
            'api/v1/admin/support-requests/{supportRequest}/messages',
            'api/v1/admin/technicians',
        ], $adminUris);
    }

    public function test_every_admin_operations_route_requires_auth_admin_middleware(): void
    {
        $operationalUris = [
            'api/v1/admin/bookings',
            'api/v1/admin/bookings/{booking}',
            'api/v1/admin/bookings/{booking}/cancel',
            'api/v1/admin/bookings/{booking}/cancellation-preview',
            'api/v1/admin/bookings/{booking}/force-complete',
            'api/v1/admin/bookings/{booking}/reschedule',
            'api/v1/admin/appointment-slots',
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
