<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\RefreshController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\ResendOtpController;
use App\Http\Controllers\Api\V1\Auth\VerifyPhoneController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::post('/v1/auth/register', RegisterController::class);
Route::post('/v1/auth/verify-phone', VerifyPhoneController::class);
Route::post('/v1/auth/resend-otp', ResendOtpController::class);
Route::post('/v1/auth/login', LoginController::class);
Route::post('/v1/auth/refresh', RefreshController::class);

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
