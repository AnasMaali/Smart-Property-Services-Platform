<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('admin')->name('admin.')->group(function () {
    Route::view('/login', 'admin.auth.login')->name('login');

    // Temporary frontend shell route.
    // Real Admin API authorization remains entirely server-side in /api/v1/admin/*.
    Route::view('/', 'admin.dashboard.index')->name('dashboard');
});
