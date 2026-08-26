@extends('admin.layouts.app')

@section('title', 'Bookings — BLUE Admin')
@section('page-title', 'Bookings')

@section('content')

<div data-bookings-page class="space-y-6">

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-slate-950">Bookings</h2>
            <p class="mt-1 text-sm text-slate-500">Monitor, assign and manage service bookings.</p>
        </div>

        <button
            type="button"
            data-bookings-refresh
            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white
                   px-3.5 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
            <svg viewBox="0 0 20 20" fill="none" class="h-4 w-4">
                <path d="M16.5 10a6.5 6.5 0 1 1-2.032-4.723M16.5 3v4h-4" stroke="currentColor"
                      stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            Refresh
        </button>
    </div>

    <div data-summary-cards class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4"></div>

    <form data-bookings-filter-form class="rounded-2xl border border-slate-200 bg-white p-5">

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-4">

            <div class="lg:col-span-2">
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Search</label>
                <div class="relative">
                    <svg viewBox="0 0 20 20" fill="none" class="pointer-events-none absolute left-3 top-1/2
                                -translate-y-1/2 h-4 w-4 text-slate-400">
                        <circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.6" />
                        <path d="m17 17-3.5-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                    </svg>
                    <input
                        type="text"
                        name="booking_number"
                        placeholder="Search by booking number (BLU-...)"
                        class="w-full rounded-lg border border-slate-300 bg-white py-2 pl-9 pr-3
                               text-sm text-slate-900 outline-none placeholder:text-slate-400
                               focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                </div>
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Status</label>
                <select
                    name="status"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
                    <option value="">All statuses</option>
                    <option value="PAID">Paid</option>
                    <option value="ASSIGNED">Assigned</option>
                    <option value="IN_PROGRESS">In progress</option>
                    <option value="COMPLETED">Completed</option>
                    <option value="CANCELLED">Cancelled</option>
                </select>
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Assignment</label>
                <select
                    name="assignment_state"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
                    <option value="">Any assignment state</option>
                    <option value="PENDING">Pending assignment</option>
                    <option value="PARTIAL">Partially assigned</option>
                    <option value="FULL">Fully assigned</option>
                </select>
            </div>

        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Appointment date</label>
                <input
                    type="date"
                    name="appointment_date"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none
                           focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>

        </div>

        <div data-advanced-filters class="hidden mt-4 grid grid-cols-1 gap-4 border-t border-slate-100 pt-4 sm:grid-cols-2 lg:grid-cols-4">

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Customer UUID</label>
                <input
                    type="text"
                    name="customer_uuid"
                    placeholder="Exact customer uuid"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none placeholder:text-slate-400
                           focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Technician UUID</label>
                <input
                    type="text"
                    name="technician_uuid"
                    placeholder="Exact technician uuid"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none placeholder:text-slate-400
                           focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Service UUID</label>
                <input
                    type="text"
                    name="service_uuid"
                    placeholder="Exact service uuid"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none placeholder:text-slate-400
                           focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Created from</label>
                    <input
                        type="date"
                        name="from"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                               text-sm text-slate-900 outline-none
                               focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Created to</label>
                    <input
                        type="date"
                        name="to"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                               text-sm text-slate-900 outline-none
                               focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                </div>
            </div>

        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button
                type="submit"
                class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold
                       text-white transition hover:bg-slate-800">
                Apply filters
            </button>

            <button
                type="button"
                data-bookings-clear-filters
                class="rounded-lg border border-slate-200 px-4 py-2 text-sm
                       font-medium text-slate-600 hover:bg-slate-50">
                Clear
            </button>

            <button
                type="button"
                data-advanced-filters-toggle
                class="ml-auto text-sm font-medium text-blue-600 hover:text-blue-800">
                Advanced filters
            </button>
        </div>

    </form>


    <div class="rounded-2xl border border-slate-200 bg-white">

        <div data-bookings-loading class="p-10 text-center text-sm text-slate-500">
            Loading bookings...
        </div>

        <div data-bookings-error class="hidden p-10 text-center text-sm text-red-600"></div>

        <div data-bookings-empty class="hidden p-10 text-center text-sm text-slate-500">
            No bookings match these filters.
        </div>

        <div data-bookings-table-wrapper class="hidden overflow-x-auto">
            <table class="hidden w-full min-w-[980px] text-left text-sm lg:table">
                <thead>
                    <tr class="border-b border-slate-200 text-xs font-medium uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Booking</th>
                        <th class="px-5 py-3">Customer</th>
                        <th class="px-5 py-3">Services</th>
                        <th class="px-5 py-3">Appointment</th>
                        <th class="px-5 py-3">Payment</th>
                        <th class="px-5 py-3">Assignment</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Created</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody data-bookings-body class="divide-y divide-slate-100"></tbody>
            </table>

            <div data-bookings-cards class="grid grid-cols-1 gap-3 p-4 lg:hidden"></div>
        </div>

        <div
            data-bookings-pagination
            style="display: none;"
            class="items-center justify-between border-t
                    border-slate-100 px-5 py-4 text-sm text-slate-600">
            <span data-bookings-pagination-summary></span>

            <div class="flex gap-2">
                <button
                    type="button"
                    data-bookings-prev-page
                    class="rounded-lg border border-slate-200 px-3 py-1.5
                           text-sm font-medium text-slate-700 hover:bg-slate-50
                           disabled:cursor-not-allowed disabled:opacity-50">
                    Previous
                </button>

                <button
                    type="button"
                    data-bookings-next-page
                    class="rounded-lg border border-slate-200 px-3 py-1.5
                           text-sm font-medium text-slate-700 hover:bg-slate-50
                           disabled:cursor-not-allowed disabled:opacity-50">
                    Next
                </button>
            </div>
        </div>

    </div>

</div>


<template data-summary-card-template>
    <div class="rounded-2xl border border-slate-200 bg-white p-5">
        <p data-field="title" class="text-xs font-medium uppercase tracking-wide text-slate-500"></p>
        <p data-field="value" class="mt-2 text-2xl font-semibold text-slate-950"></p>
    </div>
</template>


<template data-booking-row-template>
    <tr class="cursor-pointer align-top hover:bg-slate-50">
        <td class="px-5 py-4">
            <a data-row-link class="font-semibold text-slate-900 hover:text-blue-700"></a>
            <p data-field="source" class="mt-0.5 text-xs text-slate-400"></p>
        </td>
        <td class="px-5 py-4">
            <a data-customer-link class="font-medium text-slate-900 hover:text-blue-700"></a>
            <p data-field="customer_phone" class="mt-0.5 text-xs text-slate-500"></p>
        </td>
        <td class="px-5 py-4">
            <p data-field="services" class="text-slate-700"></p>
        </td>
        <td class="px-5 py-4">
            <p data-field="appointment_date" class="text-slate-700"></p>
            <p data-field="appointment_window" class="mt-0.5 text-xs text-slate-400"></p>
        </td>
        <td class="px-5 py-4">
            <span data-field="payment" data-status-badge class="rounded-full px-2.5 py-1 text-xs font-semibold"></span>
        </td>
        <td class="px-5 py-4">
            <span data-field="assignment" data-assignment-badge class="rounded-full px-2.5 py-1 text-xs font-semibold"></span>
        </td>
        <td class="px-5 py-4">
            <span data-field="status" data-status-badge class="rounded-full px-2.5 py-1 text-xs font-semibold"></span>
        </td>
        <td class="px-5 py-4 text-slate-500">
            <p data-field="created_at"></p>
        </td>
        <td class="px-5 py-4 text-right">
            <a data-row-link class="text-sm font-medium text-blue-600 hover:text-blue-800">View</a>
        </td>
    </tr>
</template>


<template data-booking-card-template>
    <div class="rounded-xl border border-slate-200 p-4">
        <div class="flex items-start justify-between gap-3">
            <div>
                <a data-row-link class="font-semibold text-slate-900"></a>
                <p data-field="customer_name" class="mt-0.5 text-sm text-slate-600"></p>
            </div>
            <span data-field="status" data-status-badge class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold"></span>
        </div>

        <dl class="mt-3 space-y-1.5 text-sm">
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500">Services</dt>
                <dd data-field="services" class="text-right font-medium text-slate-800"></dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500">Appointment</dt>
                <dd data-field="appointment_date" class="text-right font-medium text-slate-800"></dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500">Payment</dt>
                <dd><span data-field="payment" data-status-badge class="rounded-full px-2 py-0.5 text-xs font-semibold"></span></dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500">Assignment</dt>
                <dd><span data-field="assignment" data-assignment-badge class="rounded-full px-2 py-0.5 text-xs font-semibold"></span></dd>
            </div>
        </dl>

        <a data-row-link class="mt-3 inline-block text-sm font-medium text-blue-600 hover:text-blue-800">View details &rarr;</a>
    </div>
</template>

@endsection
