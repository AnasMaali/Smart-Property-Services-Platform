<?php

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
use App\Http\Controllers\Api\V1\Cart\AddCartItemController;
use App\Http\Controllers\Api\V1\Cart\ClearCartController;
use App\Http\Controllers\Api\V1\Cart\GetCartController;
use App\Http\Controllers\Api\V1\Cart\RemoveCartItemController;
use App\Http\Controllers\Api\V1\Cart\UpdateCartItemController;
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
