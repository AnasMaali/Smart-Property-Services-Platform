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
                    <p class="mt-1 text-xs text-slate-400">
                        Created <span data-field="created_at"></span>
                        &middot; Source <span data-field="source"></span>
                    </p>
                </div>

                <span data-field="status" data-status-badge
                      class="rounded-full px-3 py-1.5 text-xs font-semibold"></span>
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
                        <dd data-field="customer_name" class="font-medium text-slate-900"></dd>
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
            </div>

        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Location</h3>
            <p data-field="location_summary" class="mt-2 text-sm leading-6 text-slate-600"></p>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-6 py-4">
                <h3 class="text-sm font-semibold text-slate-900">
                    Booking items (<span data-field="items_count"></span>) &middot;
                    Total <span data-field="total"></span>
                </h3>
            </div>

            <div data-booking-items class="divide-y divide-slate-100"></div>
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

        <div class="mt-4 rounded-xl border border-slate-100 bg-slate-50 p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">
                Technician assignment
            </p>

            <p data-field="assignment_summary" class="mt-1.5 text-sm text-slate-700"></p>

            <div data-technician-actions class="mt-3 flex flex-wrap gap-2"></div>
        </div>
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

@endsection
