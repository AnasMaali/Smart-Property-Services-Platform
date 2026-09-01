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

    // BLUE V1 Phase B27 - Appointment Schedule Management shell route. Same
    // "empty Blade shell, all data/authorization from the API client-side"
    // convention as every other module in this file. One workspace covers
    // both dated slots (day view) and reusable Time Window templates (a
    // tab within the same page) - no separate page for each, per BLUE
    // V1's "keep navigation simple" guidance.
    Route::view('/appointment-schedule', 'admin.appointment-schedule.index')->name('appointment-schedule.index');

    // BLUE V1 Phase B3 - Technicians shell route. Same "empty Blade shell,
    // all data/authorization from the API client-side" convention as above.
    Route::view('/technicians', 'admin.technicians.index')->name('technicians.index');

    // BLUE V1 Phase B4 - Contracts shell routes. Same convention as
    // Bookings/Technicians above.
    Route::view('/contracts', 'admin.contracts.index')->name('contracts.index');

    Route::get('/contracts/{contract}', function (string $contract) {
        return view('admin.contracts.show', ['contractUuid' => $contract]);
    })->name('contracts.show');

    // BLUE V1 Phase B5 - Payments/Billing shell routes. Same convention as
    // Bookings/Technicians/Contracts above.
    Route::view('/payments', 'admin.payments.index')->name('payments.index');

    Route::get('/payments/{payment}', function (string $payment) {
        return view('admin.payments.show', ['paymentUuid' => $payment]);
    })->name('payments.show');

    Route::view('/billing', 'admin.billing.index')->name('billing.index');

    Route::get('/billing/{billing}', function (string $billing) {
        return view('admin.billing.show', ['billingUuid' => $billing]);
    })->name('billing.show');

    // BLUE V1 Admin Financial Dashboard + Ledger shell routes. Same
    // convention as every other module above.
    Route::view('/finance', 'admin.financial.dashboard')->name('financial.dashboard');
    Route::view('/finance/ledger', 'admin.financial.ledger')->name('financial.ledger');

    // BLUE V1 Phase B6 - Customers/Properties shell routes. Same convention
    // as every other module above. There is no global Properties index
    // route - a Property is always reached from its owning Customer's
    // detail page, so the sidebar has no separate "Properties" entry; its
    // "Customers" item's active-state check already covers /admin/properties*.
    Route::view('/customers', 'admin.customers.index')->name('customers.index');

    Route::get('/customers/{customer}', function (string $customer) {
        return view('admin.customers.show', ['customerUuid' => $customer]);
    })->name('customers.show');

    Route::get('/properties/{property}', function (string $property) {
        return view('admin.properties.show', ['propertyUuid' => $property]);
    })->name('properties.show');

    // BLUE V1 Phase B7 - Support Requests/Messages shell routes. Same
    // convention as every other module above.
    Route::view('/support', 'admin.support.index')->name('support.index');

    Route::get('/support/{supportRequest}', function (string $supportRequest) {
        return view('admin.support.show', ['supportRequestUuid' => $supportRequest]);
    })->name('support.show');

    // BLUE V1 Phase B8 - Service Catalog shell routes. Same convention as
    // every other module above. The sidebar's "Services" link points at
    // the Category list (the natural top-level browsing structure for what
    // shows up in the mobile app); the global cross-category Services list
    // is reached from there rather than getting its own sidebar entry, per
    // the "keep navigation simple" guidance.
    Route::view('/service-categories', 'admin.services.categories-index')->name('service-categories.index');

    Route::get('/service-categories/{category}', function (string $category) {
        return view('admin.services.categories-show', ['categoryId' => $category]);
    })->name('service-categories.show');

    Route::view('/services', 'admin.services.index')->name('services.index');

    Route::get('/services/{service}', function (string $service) {
        return view('admin.services.show', ['serviceUuid' => $service]);
    })->name('services.show');

    // BLUE V1 Phase B9 - Pricing shell routes. Same convention as every
    // other module above.
    Route::view('/pricing', 'admin.pricing.index')->name('pricing.index');

    Route::get('/pricing/{scheme}', function (string $scheme) {
        return view('admin.pricing.show', ['schemeUuid' => $scheme]);
    })->name('pricing.show');

    // BLUE V1 Phase B11 - Ratings shell routes. Same convention as every
    // other module above.
    Route::view('/ratings', 'admin.ratings.index')->name('ratings.index');

    Route::get('/ratings/{booking}', function (string $booking) {
        return view('admin.ratings.show', ['bookingUuid' => $booking]);
    })->name('ratings.show');

    // BLUE V1 Phase B12 - Admin Audit Log shell routes. Same convention as
    // every other module above.
    Route::view('/audit-log', 'admin.audit-log.index')->name('audit-log.index');

    Route::get('/audit-log/{auditLog}', function (string $auditLog) {
        return view('admin.audit-log.show', ['auditLogUuid' => $auditLog]);
    })->name('audit-log.show');
});
