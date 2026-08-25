<?php

use App\Http\Controllers\Api\V1\Admin\Auth\AdminLoginController;
use App\Http\Controllers\Api\V1\Admin\Auth\AdminMfaEnrollController;
use App\Http\Controllers\Api\V1\Admin\Auth\AdminMfaVerifyController;
use App\Http\Controllers\Api\V1\Admin\Auth\AdminRefreshController;
use App\Http\Controllers\Api\V1\Admin\Auth\AdminStepUpRequestController;
use App\Http\Controllers\Api\V1\Admin\Auth\AdminStepUpVerifyController;
use App\Http\Controllers\Api\V1\Admin\Booking\GetAdminBookingController;
use App\Http\Controllers\Api\V1\Admin\Booking\ListAdminBookingsController;
use App\Http\Controllers\Api\V1\Admin\Contract\ApproveContractController;
use App\Http\Controllers\Api\V1\Admin\Contract\CancelContractController;
use App\Http\Controllers\Api\V1\Admin\Contract\GetAdminContractController;
use App\Http\Controllers\Api\V1\Admin\Contract\ListAdminContractsController;
use App\Http\Controllers\Api\V1\Admin\Contract\SendContractForAcceptanceController;
use App\Http\Controllers\Api\V1\Admin\Contract\SuspendContractController;
use App\Http\Controllers\Api\V1\Admin\ContractBilling\GetAdminContractBillingController;
use App\Http\Controllers\Api\V1\Admin\ContractBilling\ListAdminContractBillingsController;
use App\Http\Controllers\Api\V1\Admin\Customer\GetAdminCustomerController;
use App\Http\Controllers\Api\V1\Admin\Customer\ListAdminCustomersController;
use App\Http\Controllers\Api\V1\Admin\MeController as AdminMeController;
use App\Http\Controllers\Api\V1\Admin\Payment\GetAdminPaymentController;
use App\Http\Controllers\Api\V1\Admin\Payment\ListAdminPaymentsController;
use App\Http\Controllers\Api\V1\Admin\Property\GetAdminPropertyController;
use App\Http\Controllers\Api\V1\Admin\Technician\AssignTechnicianController;
use App\Http\Controllers\Api\V1\Admin\Technician\CompleteWorkController;
use App\Http\Controllers\Api\V1\Admin\Technician\ListAdminTechniciansController;
use App\Http\Controllers\Api\V1\Admin\Technician\ListTechnicianCandidatesController;
use App\Http\Controllers\Api\V1\Admin\Technician\ReassignTechnicianController;
use App\Http\Controllers\Api\V1\Admin\Technician\StartWorkController;
use App\Http\Controllers\Api\V1\Auth\ChangePasswordController;
use App\Http\Controllers\Api\V1\Auth\DeleteAccountController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\GetAccountDeletionStatusController;
use App\Http\Controllers\Api\V1\Auth\LogoutAllController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RefreshController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\RequestLoginOtpController;
use App\Http\Controllers\Api\V1\Auth\RequestPhoneNumberChangeController;
use App\Http\Controllers\Api\V1\Auth\ResendLoginOtpController;
use App\Http\Controllers\Api\V1\Auth\ResendOtpController;
use App\Http\Controllers\Api\V1\Auth\ResendPhoneNumberChangeOtpController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Auth\VerifyLoginOtpController;
use App\Http\Controllers\Api\V1\Auth\VerifyPasswordResetOtpController;
use App\Http\Controllers\Api\V1\Auth\VerifyPhoneController;
use App\Http\Controllers\Api\V1\Auth\VerifyPhoneNumberChangeOtpController;
use App\Http\Controllers\Api\V1\Booking\CancelBookingController;
use App\Http\Controllers\Api\V1\Booking\GetBookingController;
use App\Http\Controllers\Api\V1\Booking\ListBookingsController;
use App\Http\Controllers\Api\V1\Cart\AddCartItemController;
use App\Http\Controllers\Api\V1\Cart\ClearCartController;
use App\Http\Controllers\Api\V1\Cart\GetCartController;
use App\Http\Controllers\Api\V1\Cart\RemoveCartItemController;
use App\Http\Controllers\Api\V1\Cart\UpdateCartItemController;
use App\Http\Controllers\Api\V1\Checkout\CreateAppointmentHoldController;
use App\Http\Controllers\Api\V1\Checkout\GetAppointmentSlotsController;
use App\Http\Controllers\Api\V1\Checkout\GetCheckoutController;
use App\Http\Controllers\Api\V1\Checkout\ReleaseAppointmentHoldController;
use App\Http\Controllers\Api\V1\Checkout\SaveCheckoutLocationController;
use App\Http\Controllers\Api\V1\Contract\AcceptContractController;
use App\Http\Controllers\Api\V1\Contract\Billing\ContractBillingWebhookController;
use App\Http\Controllers\Api\V1\Contract\Billing\CreateContractBillingCheckoutController;
use App\Http\Controllers\Api\V1\Contract\CreateContractBookingController;
use App\Http\Controllers\Api\V1\Contract\GetContractController;
use App\Http\Controllers\Api\V1\Contract\ListContractsController;
use App\Http\Controllers\Api\V1\Contract\RequestContractController;
use App\Http\Controllers\Api\V1\Payment\CreatePaymentController;
use App\Http\Controllers\Api\V1\Payment\GetPaymentController;
use App\Http\Controllers\Api\V1\Payment\PaymentWebhookController;
use App\Http\Controllers\Api\V1\Profile\GetProfileController;
use App\Http\Controllers\Api\V1\Profile\UpdateProfileController;
use App\Http\Controllers\Api\V1\Property\CreatePropertyController;
use App\Http\Controllers\Api\V1\Property\DeletePropertyController;
use App\Http\Controllers\Api\V1\Property\GetPropertyController;
use App\Http\Controllers\Api\V1\Property\ListPropertiesController;
use App\Http\Controllers\Api\V1\Property\UpdatePropertyController;
use App\Http\Controllers\Api\V1\ReferenceData\ReferenceDataController;
use App\Http\Controllers\Api\V1\ServiceCatalog\GetServiceDetailsController;
use App\Http\Controllers\Api\V1\ServiceCatalog\ListCategoryServicesController;
use App\Http\Controllers\Api\V1\ServiceCatalog\ListServiceCategoriesController;
use App\Support\Admin\AdminCapability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::post('/v1/auth/register', RegisterController::class)->middleware('throttle:auth-register');
Route::post('/v1/auth/verify-phone', VerifyPhoneController::class)->middleware('throttle:auth-otp-verify');
Route::post('/v1/auth/resend-otp', ResendOtpController::class)->middleware('throttle:auth-otp-issue');
// Canonical Customer login (the ONLY public Customer login contract):
// Phone -> 6-digit LOGIN OTP -> session. The previous password-based
// POST /v1/auth/login route has been removed entirely - it is no longer
// reachable, not even as a deprecated/internal endpoint. Test suites that
// previously used it as a fixture shortcut now mint sessions via
// tests/Support/AuthenticatesCustomersForTests instead of HTTP. See
// docs/api-contracts/authentication-v1.md.
Route::post('/v1/auth/login/request-otp', RequestLoginOtpController::class)->middleware('throttle:auth-login-otp-issue');
Route::post('/v1/auth/login/verify-otp', VerifyLoginOtpController::class)->middleware('throttle:auth-login-otp-verify');
Route::post('/v1/auth/login/resend-otp', ResendLoginOtpController::class)->middleware('throttle:auth-login-otp-issue');

Route::post('/v1/auth/refresh', RefreshController::class)->middleware('throttle:auth-refresh');
Route::post('/v1/auth/logout', LogoutController::class);
Route::post('/v1/auth/logout-all', LogoutAllController::class);
Route::post('/v1/auth/forgot-password', ForgotPasswordController::class)->middleware('throttle:auth-otp-issue');
Route::post('/v1/auth/verify-password-reset-otp', VerifyPasswordResetOtpController::class)->middleware('throttle:auth-otp-verify');
Route::post('/v1/auth/reset-password', ResetPasswordController::class)->middleware('throttle:auth-reset');

Route::middleware('auth.customer')->group(function () {
    Route::post('/v1/auth/change-password', ChangePasswordController::class);
    Route::delete('/v1/auth/account', DeleteAccountController::class)->middleware('throttle:auth-account-delete');
    Route::get('/v1/auth/account-deletion', GetAccountDeletionStatusController::class);
    Route::post('/v1/auth/change-phone-number', RequestPhoneNumberChangeController::class)->middleware('throttle:auth-phone-change-issue');
    Route::post('/v1/auth/verify-phone-number-change-otp', VerifyPhoneNumberChangeOtpController::class)->middleware('throttle:auth-phone-change-verify');
    Route::post('/v1/auth/resend-phone-number-change-otp', ResendPhoneNumberChangeOtpController::class)->middleware('throttle:auth-phone-change-issue');

    Route::get('/v1/profile', GetProfileController::class);
    Route::patch('/v1/profile', UpdateProfileController::class);

    Route::get('/v1/cart', GetCartController::class);
    Route::post('/v1/cart/items', AddCartItemController::class);
    Route::patch('/v1/cart/items/{item}', UpdateCartItemController::class);
    Route::delete('/v1/cart/items/{item}', RemoveCartItemController::class);
    Route::delete('/v1/cart', ClearCartController::class);

    Route::get('/v1/checkout', GetCheckoutController::class);
    Route::put('/v1/checkout/location', SaveCheckoutLocationController::class);
    Route::get('/v1/checkout/appointment-slots', GetAppointmentSlotsController::class);
    Route::post('/v1/checkout/appointment-hold', CreateAppointmentHoldController::class);
    Route::delete('/v1/checkout/appointment-hold', ReleaseAppointmentHoldController::class);

    Route::post('/v1/payments', CreatePaymentController::class);
    Route::get('/v1/payments/{payment}', GetPaymentController::class);

    Route::get('/v1/bookings', ListBookingsController::class);
    Route::get('/v1/bookings/{booking}', GetBookingController::class);
    Route::post('/v1/bookings/{booking}/cancel', CancelBookingController::class);

    // BLUE V1 Phase 10A - Customer Properties.
    Route::get('/v1/properties', ListPropertiesController::class);
    Route::post('/v1/properties', CreatePropertyController::class);
    Route::get('/v1/properties/{property}', GetPropertyController::class);
    Route::patch('/v1/properties/{property}', UpdatePropertyController::class);
    Route::delete('/v1/properties/{property}', DeletePropertyController::class);

    // BLUE V1 Phase 10D - Customer Service Contracts. `requests` is a
    // literal segment (never a {contract} uuid), matching the
    // /v1/checkout/appointment-hold-style "specific action, not a
    // resource id" naming already used elsewhere in this file.
    Route::get('/v1/contracts', ListContractsController::class);
    Route::post('/v1/contracts/requests', RequestContractController::class);
    Route::get('/v1/contracts/{contract}', GetContractController::class);
    Route::post('/v1/contracts/{contract}/accept', AcceptContractController::class);
    Route::post('/v1/contracts/{contract}/services/{contractItem}/book', CreateContractBookingController::class);

    // BLUE V1 Phase 11 - Service Contract Stripe Billing (Subscriptions).
    // No request body: every monetary/provider value is server-authoritative
    // - see CreateContractBillingCheckoutController.
    Route::post('/v1/contracts/{contract}/billing/checkout', CreateContractBillingCheckoutController::class);
});

// Deliberately outside the auth.customer group - the caller is the payment
// provider's server, authenticated by webhook signature only.
Route::post('/v1/payments/webhooks/stripe', PaymentWebhookController::class);

// BLUE V1 Phase 11 - a SEPARATE Stripe webhook endpoint (own signing
// secret) from the one above: Subscription/Invoice/Checkout Session
// events only, never a PaymentIntent one. Deliberately outside the
// auth.customer group for the same reason as the Payment webhook above.
Route::post('/v1/contracts/billing/webhooks/stripe', ContractBillingWebhookController::class);

// BLUE V1 Phase 9A - Admin authentication & authorization foundation.
// A valid Customer access token never grants access to these routes, and a
// valid Admin access token never grants access to the auth.customer routes
// above: AuthenticateAdmin/AuthenticateCustomer each re-check current role
// membership in the database on every request, independent of any `role`
// claim embedded in the token itself, AND require the backing session's own
// client_type_id to be ADMIN_WEB / a mobile type respectively. The
// client_type check is what keeps this boundary intact even for a user who
// holds both a Customer and an Admin role at once - role membership alone
// would let that user's mobile-issued token work here too.
//
// Admin logout / logout-all deliberately reuse the existing
// /v1/auth/logout and /v1/auth/logout-all routes above - LogoutAction and
// LogoutAllAction only require a valid session belonging to an ACTIVE user
// and never check role, so they already work unchanged for Admin sessions.
// BLUE V1 Phase A2.3 - Mandatory Admin MFA login. Stage 1 (password) never
// creates a session - see App\Actions\Auth\AdminLoginAction. Stage 2
// (WebAuthn assertion) is the ONLY place a session is created - see
// App\Actions\Auth\AdminMfaVerifyAction. The first-credential bootstrap
// (mfa/enroll) never issues a session either - see
// App\Actions\Auth\AdminMfaEnrollAction. There is exactly one canonical
// production Admin login flow; no password-only session-issuing route
// exists anywhere.
Route::post('/v1/admin/auth/login', AdminLoginController::class)->middleware('throttle:admin-auth-login');
Route::post('/v1/admin/auth/mfa/enroll', AdminMfaEnrollController::class)->middleware('throttle:admin-auth-mfa-enroll');
Route::post('/v1/admin/auth/mfa/verify', AdminMfaVerifyController::class)->middleware('throttle:admin-auth-mfa-verify');
Route::post('/v1/admin/auth/refresh', AdminRefreshController::class)->middleware('throttle:auth-refresh');

// BLUE V1 Phase A2.5 - WebAuthn step-up authentication. Unlike the four
// routes directly above (all reachable by an unauthenticated caller mid-
// login), both of these REQUIRE auth.admin - a step-up ceremony re-verifies
// an already-authenticated Admin's identity for a sensitive operation, it
// is never itself a login path. See App\Actions\Auth\AdminStepUpRequestAction/
// AdminStepUpVerifyAction and App\Http\Middleware\EnsureAdminStepUpIsFresh
// (`admin.stepup`, applied to contracts.cancel below).
Route::post('/v1/admin/auth/step-up/request', AdminStepUpRequestController::class)
    ->middleware(['auth.admin', 'throttle:admin-auth-step-up-request']);
Route::post('/v1/admin/auth/step-up/verify', AdminStepUpVerifyController::class)
    ->middleware(['auth.admin', 'throttle:admin-auth-step-up-verify']);

Route::middleware('auth.admin')->group(function () {
    // No capability gate: every authenticated Admin/Super Admin may read
    // their own identity regardless of which capabilities they hold.
    Route::get('/v1/admin/me', AdminMeController::class);

    // BLUE V1 Phase 9B - Admin Operations APIs: a thin, auth.admin-only
    // transport layer over the already-tested Phase 7B/8A/8B domain Actions.
    // No route here re-implements assignment/lifecycle business logic - see
    // docs/api-contracts/admin-operations-v1.md.
    // Booking-level lifecycle mutation (assign/start/complete on `bookings`
    // itself) is deliberately NOT exposed here - see
    // docs/api-contracts/admin-operations-v1.md "Booking-Level Lifecycle"
    // for why BR-16-based completion cannot be safely built without first
    // resolving the still-open ASSIGNED/IN_PROGRESS aggregation decision
    // bookings-v1.md already flags. Only the read APIs are exposed.
    //
    // BLUE V1 Phase A1 - every route below now also carries an
    // admin.capability:<code> gate (see docs/api-contracts/admin-authorization-v1.md).
    // auth.admin (authentication) and admin.capability (authorization) are
    // deliberately two separate middleware - authentication alone never
    // grants operational capability.
    Route::get('/v1/admin/bookings', ListAdminBookingsController::class)
        ->middleware(AdminCapability::BOOKINGS_VIEW->middleware());
    Route::get('/v1/admin/bookings/{booking}', GetAdminBookingController::class)
        ->middleware(AdminCapability::BOOKINGS_VIEW->middleware());

    Route::get('/v1/admin/technicians', ListAdminTechniciansController::class)
        ->middleware(AdminCapability::TECHNICIANS_VIEW->middleware());

    Route::get('/v1/admin/booking-items/{bookingItem}/technician-candidates', ListTechnicianCandidatesController::class)
        ->middleware(AdminCapability::TECHNICIANS_VIEW->middleware());
    Route::post('/v1/admin/booking-items/{bookingItem}/assign-technician', AssignTechnicianController::class)
        ->middleware(AdminCapability::TECHNICIANS_ASSIGN->middleware());
    Route::post('/v1/admin/booking-items/{bookingItem}/reassign-technician', ReassignTechnicianController::class)
        ->middleware(AdminCapability::TECHNICIANS_ASSIGN->middleware());
    Route::post('/v1/admin/booking-items/{bookingItem}/start-work', StartWorkController::class)
        ->middleware(AdminCapability::TECHNICIANS_ASSIGN->middleware());
    Route::post('/v1/admin/booking-items/{bookingItem}/complete-work', CompleteWorkController::class)
        ->middleware(AdminCapability::TECHNICIANS_ASSIGN->middleware());

    // BLUE V1 Phase 10E - Admin Service Contract management. Never
    // exposes customer-private/provider/payment internals - see
    // App\Support\Admin\AdminContractPresenter.
    Route::get('/v1/admin/contracts', ListAdminContractsController::class)
        ->middleware(AdminCapability::CONTRACTS_VIEW->middleware());
    Route::get('/v1/admin/contracts/{contract}', GetAdminContractController::class)
        ->middleware(AdminCapability::CONTRACTS_VIEW->middleware());
    Route::post('/v1/admin/contracts/{contract}/approve', ApproveContractController::class)
        ->middleware(AdminCapability::CONTRACTS_MANAGE->middleware());
    Route::post('/v1/admin/contracts/{contract}/send-for-acceptance', SendContractForAcceptanceController::class)
        ->middleware(AdminCapability::CONTRACTS_MANAGE->middleware());
    Route::post('/v1/admin/contracts/{contract}/suspend', SuspendContractController::class)
        ->middleware(AdminCapability::CONTRACTS_MANAGE->middleware());
    // BLUE V1 Phase A2.5 - the first admin.stepup-protected route: a fresh
    // WebAuthn re-proof (see App\Http\Middleware\EnsureAdminStepUpIsFresh)
    // is required IN ADDITION to the existing contracts.cancel capability
    // check above - step-up never replaces authorization, it only adds an
    // identity-freshness precondition on top of it (hence admin.capability
    // running first in this list, admin.stepup second).
    Route::post('/v1/admin/contracts/{contract}/cancel', CancelContractController::class)
        ->middleware([AdminCapability::CONTRACTS_CANCEL->middleware(), 'admin.stepup']);

    // BLUE V1 Phase B5 - read-only global Admin visibility into one-off
    // Payment Attempts and recurring Service Contract Billing state. Never
    // exposes provider secrets/raw webhook payloads - see
    // App\Support\Admin\AdminPaymentPresenter / AdminContractBillingPresenter.
    // No mutation endpoint exists here (no refund/retry/status-override) -
    // this module is deliberately monitoring-only.
    Route::get('/v1/admin/payments', ListAdminPaymentsController::class)
        ->middleware(AdminCapability::PAYMENTS_VIEW->middleware());
    Route::get('/v1/admin/payments/{payment}', GetAdminPaymentController::class)
        ->middleware(AdminCapability::PAYMENTS_VIEW->middleware());
    Route::get('/v1/admin/contract-billings', ListAdminContractBillingsController::class)
        ->middleware(AdminCapability::BILLING_VIEW->middleware());
    Route::get('/v1/admin/contract-billings/{billing}', GetAdminContractBillingController::class)
        ->middleware(AdminCapability::BILLING_VIEW->middleware());

    // BLUE V1 Phase B6 - read-only global Admin visibility into Customers
    // and their Properties. A Property is always Customer-owned, so it
    // shares the same `customers.view` capability rather than a separate
    // `properties.view` one - see AdminCapability::CUSTOMERS_VIEW. No
    // mutation endpoint exists here - see App\Support\Admin\
    // AdminCustomerPresenter's docblock.
    Route::get('/v1/admin/customers', ListAdminCustomersController::class)
        ->middleware(AdminCapability::CUSTOMERS_VIEW->middleware());
    Route::get('/v1/admin/customers/{customer}', GetAdminCustomerController::class)
        ->middleware(AdminCapability::CUSTOMERS_VIEW->middleware());
    Route::get('/v1/admin/properties/{property}', GetAdminPropertyController::class)
        ->middleware(AdminCapability::CUSTOMERS_VIEW->middleware());
});

Route::get('/v1/reference-data/registration', ReferenceDataController::class);

Route::get('/v1/service-categories', ListServiceCategoriesController::class);
Route::get('/v1/service-categories/{category}/services', ListCategoryServicesController::class);
Route::get('/v1/services/{service}', GetServiceDetailsController::class);

Route::get('/v1/health', function () {
    try {
        DB::select('SELECT 1');

        return response()->json([
            'success' => true,
            'message' => 'BLUE API is running',
            'database' => 'connected',
        ]);
    } catch (Throwable $exception) {
        report($exception);

        return response()->json([
            'success' => false,
            'message' => 'BLUE API is running, but the database is unavailable',
            'database' => 'disconnected',
        ], 503);
    }
});
