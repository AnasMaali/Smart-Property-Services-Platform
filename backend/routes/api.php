<?php

use App\Http\Controllers\Api\V1\Admin\Auth\AdminLoginController;
use App\Http\Controllers\Api\V1\Admin\Auth\AdminRefreshController;
use App\Http\Controllers\Api\V1\Admin\MeController as AdminMeController;
use App\Http\Controllers\Api\V1\Auth\ChangePasswordController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutAllController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RefreshController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\RequestPhoneNumberChangeController;
use App\Http\Controllers\Api\V1\Auth\ResendOtpController;
use App\Http\Controllers\Api\V1\Auth\ResendPhoneNumberChangeOtpController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Auth\VerifyPasswordResetOtpController;
use App\Http\Controllers\Api\V1\Auth\VerifyPhoneController;
use App\Http\Controllers\Api\V1\Auth\VerifyPhoneNumberChangeOtpController;
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
use App\Http\Controllers\Api\V1\Payment\CreatePaymentController;
use App\Http\Controllers\Api\V1\Payment\GetPaymentController;
use App\Http\Controllers\Api\V1\Payment\PaymentWebhookController;
use App\Http\Controllers\Api\V1\Profile\GetProfileController;
use App\Http\Controllers\Api\V1\Profile\UpdateProfileController;
use App\Http\Controllers\Api\V1\ReferenceData\ReferenceDataController;
use App\Http\Controllers\Api\V1\ServiceCatalog\GetServiceDetailsController;
use App\Http\Controllers\Api\V1\ServiceCatalog\ListCategoryServicesController;
use App\Http\Controllers\Api\V1\ServiceCatalog\ListServiceCategoriesController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::post('/v1/auth/register', RegisterController::class);
Route::post('/v1/auth/verify-phone', VerifyPhoneController::class);
Route::post('/v1/auth/resend-otp', ResendOtpController::class);
Route::post('/v1/auth/login', LoginController::class);
Route::post('/v1/auth/refresh', RefreshController::class);
Route::post('/v1/auth/logout', LogoutController::class);
Route::post('/v1/auth/logout-all', LogoutAllController::class);
Route::post('/v1/auth/forgot-password', ForgotPasswordController::class);
Route::post('/v1/auth/verify-password-reset-otp', VerifyPasswordResetOtpController::class);
Route::post('/v1/auth/reset-password', ResetPasswordController::class);

Route::middleware('auth.customer')->group(function () {
    Route::post('/v1/auth/change-password', ChangePasswordController::class);
    Route::post('/v1/auth/change-phone-number', RequestPhoneNumberChangeController::class);
    Route::post('/v1/auth/verify-phone-number-change-otp', VerifyPhoneNumberChangeOtpController::class);
    Route::post('/v1/auth/resend-phone-number-change-otp', ResendPhoneNumberChangeOtpController::class);

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
});

// Deliberately outside the auth.customer group - the caller is the payment
// provider's server, authenticated by webhook signature only.
Route::post('/v1/payments/webhooks/stripe', PaymentWebhookController::class);

// BLUE V1 Phase 9A - Admin authentication & authorization foundation.
// A valid Customer access token never grants access to these routes, and a
// valid Admin access token never grants access to the auth.customer routes
// above: AuthenticateAdmin/AuthenticateCustomer each re-check current role
// membership in the database on every request, independent of any `role`
// claim embedded in the token itself.
//
// Admin logout / logout-all deliberately reuse the existing
// /v1/auth/logout and /v1/auth/logout-all routes above - LogoutAction and
// LogoutAllAction only require a valid session belonging to an ACTIVE user
// and never check role, so they already work unchanged for Admin sessions.
Route::post('/v1/admin/auth/login', AdminLoginController::class)->middleware('throttle:5,1');
Route::post('/v1/admin/auth/refresh', AdminRefreshController::class);

Route::middleware('auth.admin')->group(function () {
    Route::get('/v1/admin/me', AdminMeController::class);
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
