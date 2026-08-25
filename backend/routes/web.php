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

    // BLUE V1 Phase B2 - Bookings shell routes. Just like every other route
    // in this file, these render an empty Blade shell only - no data, no
    // authorization decision is made here. All real data/authorization
    // comes from GET /api/v1/admin/bookings(/{booking}), called client-side
    // (resources/js/admin/bookings/*.js) once the Admin session is restored.
    Route::view('/bookings', 'admin.bookings.index')->name('bookings.index');

    Route::get('/bookings/{booking}', function (string $booking) {
        return view('admin.bookings.show', ['bookingUuid' => $booking]);
    })->name('bookings.show');

    // BLUE V1 Phase B3 - Technicians shell route. Same "empty Blade shell,
    // all data/authorization from the API client-side" convention as above.
    Route::view('/technicians', 'admin.technicians.index')->name('technicians.index');
});
