@extends('admin.layouts.app')

@section('title', 'Booking — BLUE Admin')
@section('page-title', 'Booking detail')

@section('content')

<div data-booking-detail-page data-booking-uuid="{{ $bookingUuid }}" class="space-y-6">

    <a
        href="/admin/bookings"
        class="inline-flex items-center gap-1.5 text-sm font-medium
               text-slate-500 hover:text-slate-800">
        &larr; Back to bookings
    </a>

    <div data-booking-loading class="rounded-2xl border border-slate-200 bg-white p-10
                text-center text-sm text-slate-500">
        Loading booking...
    </div>

    <div data-booking-error class="hidden rounded-2xl border border-red-200 bg-red-50
                p-10 text-center text-sm text-red-700"></div>

    <div data-booking-content style="display: none;" class="flex flex-col gap-6">

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Booking</p>
                    <h2 data-field="booking_number" class="mt-1 text-2xl font-semibold text-slate-950"></h2>
                    <p class="mt-1.5 text-sm text-slate-500">
                        <a data-customer-link class="font-medium text-blue-600 hover:text-blue-800"></a>
                        &middot; Appointment <span data-field="appointment_summary"></span>
                    </p>
                    <p class="mt-1 text-xs text-slate-400">
                        Created <span data-field="created_at"></span>
                        &middot; Source <span data-field="source"></span>
                    </p>
                </div>

                <div class="flex flex-col items-end gap-2">
                    <span data-field="status" data-status-badge
                          class="rounded-full px-3 py-1.5 text-xs font-semibold"></span>
                    <button
                        type="button"
                        data-cancel-booking-open
                        style="display: none;"
                        class="rounded-lg border border-red-200 bg-white px-3 py-1.5
                               text-xs font-semibold text-red-700 hover:bg-red-50">
                        Cancel booking
                    </button>
                </div>
            </div>

            <div data-refund-due-box style="display: none;" class="mt-5 rounded-xl border
                        border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                Refund due: <span data-field="refund_percentage"></span>% ( <span data-field="refund_amount"></span> ) -
                execution: <span data-field="refund_execution"></span>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h3 class="text-sm font-semibold text-slate-900">Customer</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Name</dt>
                        <dd><a data-customer-link data-field="customer_name" class="font-medium text-blue-600 hover:text-blue-800"></a></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Phone</dt>
                        <dd data-field="customer_phone" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Email</dt>
                        <dd data-field="customer_email" class="font-medium text-slate-900"></dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h3 class="text-sm font-semibold text-slate-900">Appointment</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Window</dt>
                        <dd data-field="appointment_window" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Starts</dt>
                        <dd data-field="appointment_starts_at" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Ends</dt>
                        <dd data-field="appointment_ends_at" class="font-medium text-slate-900"></dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h3 class="text-sm font-semibold text-slate-900">Payment</h3>
                <div data-payment-box>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-500">Status</dt>
                            <dd data-field="payment_status" class="font-medium text-slate-900"></dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-500">Amount</dt>
                            <dd data-field="payment_amount" class="font-medium text-slate-900"></dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-500">Provider</dt>
                            <dd data-field="payment_provider" class="font-medium text-slate-900"></dd>
                        </div>
                    </dl>
                    <a data-payment-link class="mt-3 inline-block text-sm font-medium text-blue-600 hover:text-blue-800">
                        View payment &rarr;
                    </a>
                </div>
                <p data-payment-empty style="display: none;" class="mt-3 text-sm text-slate-500">
                    This booking has no one-off payment - it is covered by a Service Contract.
                </p>
            </div>

        </div>


        <div data-contract-box style="display: none;" class="rounded-2xl border border-blue-200 bg-blue-50 p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Service Contract</h3>
                    <p class="mt-1">
                        <a data-contract-link class="font-medium text-blue-700 hover:text-blue-900"></a>
                    </p>
                </div>
                <span data-field="contract_status" data-status-badge class="rounded-full px-3 py-1.5 text-xs font-semibold"></span>
            </div>
            <p data-field="entitlement_summary" class="mt-3 text-sm text-slate-700"></p>
        </div>


        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex items-start justify-between gap-4">
                <h3 class="text-sm font-semibold text-slate-900">Location</h3>
                <button
                    type="button"
                    data-edit-booking-open
                    style="display: none;"
                    class="shrink-0 rounded-lg border border-slate-300 bg-white px-3 py-1.5
                           text-xs font-semibold text-slate-700 hover:bg-slate-50">
                    Edit booking
                </button>
            </div>
            <p data-field="location_summary" class="mt-2 text-sm leading-6 text-slate-600"></p>
            <p data-field="location_contact" class="mt-1 text-xs text-slate-400"></p>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-6 py-4">
                <h3 class="text-sm font-semibold text-slate-900">
                    Service items (<span data-field="items_count"></span>) &middot;
                    Total <span data-field="total"></span>
                </h3>
            </div>

            <div data-booking-items class="divide-y divide-slate-100"></div>
        </div>

        <div data-rating-box style="display: none;" class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Rating</h3>
            <div class="mt-3 flex items-center gap-2">
                <span data-field="rating_stars" class="text-lg text-amber-500"></span>
                <span data-field="rating_value" class="text-sm font-semibold text-slate-900"></span>
            </div>
            <p data-field="rating_comment" class="mt-2 text-sm leading-6 text-slate-600"></p>
            <p data-field="rating_created_at" class="mt-1 text-xs text-slate-400"></p>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-6 py-4">
                <h3 class="text-sm font-semibold text-slate-900">Booking history</h3>
            </div>
            <div data-status-history class="divide-y divide-slate-100"></div>
        </div>

    </div>

</div>


<template data-booking-item-template>
    <div class="p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p data-field="service_name" class="font-medium text-slate-900"></p>
                <p class="mt-0.5 text-xs text-slate-500">
                    Service code <span data-field="service_code"></span>
                    &middot; Qty <span data-field="quantity"></span>
                </p>
            </div>

            <div class="flex items-center gap-2">
                <span data-field="item_status" data-status-badge
                      class="rounded-full px-2.5 py-1 text-xs font-semibold"></span>
                <span data-field="line_total" class="text-sm font-semibold text-slate-900"></span>
            </div>
        </div>

        <div data-item-selections class="mt-3 hidden flex-wrap gap-1.5"></div>

        <div class="mt-4 rounded-xl border border-slate-100 bg-slate-50 p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">
                Technician assignment
            </p>

            <p data-field="assignment_summary" class="mt-1.5 text-sm text-slate-700"></p>

            <div data-technician-actions class="mt-3 flex flex-wrap gap-2"></div>
        </div>

        <details data-item-history-box class="mt-3 hidden">
            <summary class="cursor-pointer text-xs font-medium text-slate-500 hover:text-slate-700">
                Item history
            </summary>
            <div data-item-history class="mt-2 space-y-1.5 text-xs text-slate-500"></div>
        </details>
    </div>
</template>

<template data-selection-chip-template>
    <span class="inline-flex items-center rounded-full border border-slate-200 bg-white
                 px-2.5 py-1 text-xs text-slate-600"></span>
</template>

<template data-history-row-template>
    <div class="flex flex-wrap items-baseline justify-between gap-2 px-6 py-3 text-sm">
        <div>
            <span data-field="transition" class="font-medium text-slate-900"></span>
            <span data-field="reason" class="ml-2 text-slate-500"></span>
        </div>
        <span data-field="changed_at" class="text-xs text-slate-400"></span>
    </div>
</template>

<template data-item-history-row-template>
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <span data-field="transition"></span>
        <span data-field="changed_at" class="text-slate-400"></span>
    </div>
</template>


<div
    data-technician-modal
    style="display: none;"
    class="fixed inset-0 z-50 items-center justify-center bg-slate-950/60 p-4">

    <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl">

        <p data-technician-modal-service class="text-sm font-medium text-blue-600"></p>
        <h2 data-technician-modal-title class="mt-1 text-lg font-semibold text-slate-950"></h2>

        <div data-technician-modal-error class="mt-4 hidden rounded-xl border border-red-200
                    bg-red-50 px-4 py-3 text-sm text-red-700"></div>

        <div data-technician-modal-loading class="mt-4 text-sm text-slate-500">
            Loading eligible technicians...
        </div>

        <div data-technician-modal-empty style="display: none;" class="mt-4 rounded-xl
                    border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500"></div>

        <form data-technician-modal-form style="display: none;" class="mt-4 space-y-4">

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">
                    Technician
                </label>
                <div data-technician-modal-candidates class="max-h-56 space-y-1.5 overflow-y-auto"></div>
            </div>

            <div data-technician-modal-release-reason-field style="display: none;">
                <label class="mb-1.5 block text-xs font-medium text-slate-600">
                    Reason for reassignment
                </label>
                <textarea
                    name="release_reason"
                    rows="2"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100"></textarea>
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">
                    Internal note (optional)
                </label>
                <textarea
                    name="internal_note"
                    rows="2"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100"></textarea>
            </div>

            <div class="flex justify-end gap-3 pt-1">
                <button
                    type="button"
                    data-technician-modal-cancel
                    class="rounded-xl px-4 py-2.5 text-sm font-medium
                           text-slate-600 hover:bg-slate-50">
                    Cancel
                </button>

                <button
                    type="submit"
                    data-technician-modal-submit
                    class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm
                           font-semibold text-white transition
                           hover:bg-slate-800 disabled:cursor-not-allowed
                           disabled:opacity-60">
                    Confirm
                </button>
            </div>

        </form>

    </div>

</div>


<div
    data-confirm-action-modal
    style="display: none;"
    class="fixed inset-0 z-50 items-center justify-center bg-slate-950/60 p-4">

    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">

        <h2 data-confirm-action-title class="text-lg font-semibold text-slate-950"></h2>
        <p data-confirm-action-message class="mt-2 text-sm leading-6 text-slate-500"></p>

        <div data-confirm-action-error class="mt-4 hidden rounded-xl border border-red-200
                    bg-red-50 px-4 py-3 text-sm text-red-700"></div>

        <div class="mt-3">
            <label class="mb-1.5 block text-xs font-medium text-slate-600">
                Reason (optional)
            </label>
            <textarea
                data-confirm-action-reason
                rows="2"
                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                       text-sm text-slate-900 outline-none focus:border-blue-500
                       focus:ring-4 focus:ring-blue-100"></textarea>
        </div>

        <div class="mt-6 flex justify-end gap-3">
            <button
                type="button"
                data-confirm-action-cancel
                class="rounded-xl px-4 py-2.5 text-sm font-medium
                       text-slate-600 hover:bg-slate-50">
                Cancel
            </button>

            <button
                type="button"
                data-confirm-action-confirm
                class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm
                       font-semibold text-white transition
                       hover:bg-slate-800 disabled:cursor-not-allowed
                       disabled:opacity-60">
                Confirm
            </button>
        </div>

    </div>

</div>


<div
    data-edit-booking-modal
    style="display: none;"
    class="fixed inset-0 z-50 items-center justify-center bg-slate-950/60 p-4">

    <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl">

        <h2 class="text-lg font-semibold text-slate-950">Edit booking</h2>
        <p class="mt-1 text-sm text-slate-500">
            Operational visit/location details only. Customer, service, pricing,
            status, and appointment cannot be changed here.
        </p>

        <div data-edit-booking-error class="mt-4 hidden rounded-xl border border-red-200
                    bg-red-50 px-4 py-3 text-sm text-red-700"></div>

        <form data-edit-booking-form class="mt-4 space-y-4">

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Street</label>
                    <input
                        name="street_name"
                        type="text"
                        required
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                               text-sm text-slate-900 outline-none focus:border-blue-500
                               focus:ring-4 focus:ring-blue-100">
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Address</label>
                    <textarea
                        name="address_line"
                        rows="2"
                        required
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                               text-sm text-slate-900 outline-none focus:border-blue-500
                               focus:ring-4 focus:ring-blue-100"></textarea>
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Building</label>
                    <input
                        name="building_name_or_number"
                        type="text"
                        required
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                               text-sm text-slate-900 outline-none focus:border-blue-500
                               focus:ring-4 focus:ring-blue-100">
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Floor</label>
                    <input
                        name="floor_number"
                        type="text"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                               text-sm text-slate-900 outline-none focus:border-blue-500
                               focus:ring-4 focus:ring-blue-100">
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Unit</label>
                    <input
                        name="unit_number"
                        type="text"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                               text-sm text-slate-900 outline-none focus:border-blue-500
                               focus:ring-4 focus:ring-blue-100">
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Nearby landmark</label>
                    <input
                        name="nearby_landmark"
                        type="text"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                               text-sm text-slate-900 outline-none focus:border-blue-500
                               focus:ring-4 focus:ring-blue-100">
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Visit contact phone</label>
                    <input
                        name="visit_contact_phone"
                        type="text"
                        required
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                               text-sm text-slate-900 outline-none focus:border-blue-500
                               focus:ring-4 focus:ring-blue-100">
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Location notes</label>
                    <textarea
                        name="additional_location_notes"
                        rows="2"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                               text-sm text-slate-900 outline-none focus:border-blue-500
                               focus:ring-4 focus:ring-blue-100"></textarea>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-1">
                <button
                    type="button"
                    data-edit-booking-cancel
                    class="rounded-xl px-4 py-2.5 text-sm font-medium
                           text-slate-600 hover:bg-slate-50">
                    Cancel
                </button>

                <button
                    type="submit"
                    data-edit-booking-submit
                    class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm
                           font-semibold text-white transition
                           hover:bg-slate-800 disabled:cursor-not-allowed
                           disabled:opacity-60">
                    Save changes
                </button>
            </div>

        </form>

    </div>

</div>

@endsection
