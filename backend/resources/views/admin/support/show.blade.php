@extends('admin.layouts.app')

@section('title', 'Support request — BLUE Admin')
@section('page-title', 'Support request detail')

@section('content')

<div data-support-detail-page data-support-request-uuid="{{ $supportRequestUuid }}" class="space-y-6">

    <a
        href="/admin/support"
        class="inline-flex items-center gap-1.5 text-sm font-medium
               text-slate-500 hover:text-slate-800">
        &larr; Back to support requests
    </a>

    <div data-support-loading class="rounded-2xl border border-slate-200 bg-white p-10
                text-center text-sm text-slate-500">
        Loading support request...
    </div>

    <div data-support-error class="hidden rounded-2xl border border-red-200 bg-red-50
                p-10 text-center text-sm text-red-700"></div>

    <div data-support-content style="display: none;" class="flex flex-col gap-6">

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">
                        Request <span data-field="request_number"></span>
                    </p>
                    <h2 data-field="subject" class="mt-1 text-2xl font-semibold text-slate-950"></h2>
                    <p class="mt-1 text-xs text-slate-400">
                        Opened <span data-field="created_at"></span>
                    </p>
                </div>

                <span data-field="status" data-status-badge
                      class="rounded-full px-3 py-1.5 text-xs font-semibold"></span>
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
                <a data-customer-link
                   class="mt-4 inline-block text-xs font-semibold text-blue-600 hover:text-blue-800">
                    View customer &rarr;
                </a>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h3 class="text-sm font-semibold text-slate-900">Booking</h3>
                <div data-booking-none class="hidden text-sm text-slate-500">
                    No booking is linked to this request.
                </div>
                <dl data-booking-details class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Booking number</dt>
                        <dd data-field="booking_number" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Status</dt>
                        <dd data-field="booking_status" class="font-medium text-slate-900"></dd>
                    </div>
                </dl>
                <a data-booking-link
                   class="mt-4 hidden text-xs font-semibold text-blue-600 hover:text-blue-800">
                    View booking &rarr;
                </a>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h3 class="text-sm font-semibold text-slate-900">Lifecycle</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Assigned admin</dt>
                        <dd data-field="assigned_admin" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Status changed</dt>
                        <dd data-field="status_changed_at" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Resolved</dt>
                        <dd data-field="resolved_at" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Closed</dt>
                        <dd data-field="closed_at" class="font-medium text-slate-900"></dd>
                    </div>
                </dl>

                <div class="mt-4 border-t border-slate-100 pt-4">
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Change status</label>
                    <div class="flex gap-2">
                        <select data-status-select class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                               text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                            <option value="OPEN">Open</option>
                            <option value="IN_PROGRESS">In progress</option>
                            <option value="RESOLVED">Resolved</option>
                            <option value="CLOSED">Closed</option>
                        </select>
                        <button type="button" data-apply-status
                                class="shrink-0 rounded-lg bg-slate-950 px-3 py-2 text-xs font-semibold
                                       text-white transition hover:bg-slate-800">
                            Update
                        </button>
                    </div>
                    <p data-status-error class="mt-2 hidden text-sm text-red-600"></p>
                </div>

                <div class="mt-4 border-t border-slate-100 pt-4">
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Assign admin</label>
                    <div class="flex gap-2">
                        <input type="text" data-assign-admin-uuid placeholder="Admin uuid"
                               class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                                      text-sm text-slate-900 outline-none placeholder:text-slate-400
                                      focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                        <button type="button" data-apply-assign
                                class="shrink-0 rounded-lg bg-slate-950 px-3 py-2 text-xs font-semibold
                                       text-white transition hover:bg-slate-800">
                            Assign
                        </button>
                    </div>
                    <div class="mt-2 flex gap-3">
                        <button type="button" data-assign-to-me
                                class="text-xs font-semibold text-blue-600 hover:text-blue-800">
                            Assign to me
                        </button>
                        <button type="button" data-unassign
                                class="text-xs font-semibold text-slate-500 hover:text-slate-800">
                            Unassign
                        </button>
                    </div>
                    <p data-assign-error class="mt-2 hidden text-sm text-red-600"></p>
                </div>
            </div>

        </div>

        <div class="rounded-2xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-6 py-4">
                <h3 class="text-sm font-semibold text-slate-900">Conversation</h3>
            </div>
            <div data-messages-empty class="hidden px-6 py-6 text-sm text-slate-500">
                No messages have been exchanged on this request yet.
            </div>
            <div data-messages class="divide-y divide-slate-100"></div>

            <form data-reply-form class="border-t border-slate-100 p-6">
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Reply as Admin</label>
                <textarea
                    name="message_body"
                    rows="4"
                    maxlength="5000"
                    required
                    placeholder="Write a reply to the customer..."
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none placeholder:text-slate-400
                           focus:border-blue-500 focus:ring-4 focus:ring-blue-100"></textarea>

                <div class="mt-3 flex items-center justify-between gap-4">
                    <p data-reply-error class="hidden text-sm text-red-600"></p>
                    <button
                        type="submit"
                        data-reply-submit
                        class="ml-auto rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold
                               text-white transition hover:bg-slate-800
                               disabled:cursor-not-allowed disabled:opacity-50">
                        Send reply
                    </button>
                </div>
            </form>
        </div>

    </div>

</div>

<template data-message-template>
    <div data-message-row class="p-5">
        <div class="flex items-center justify-between gap-3">
            <span data-field="sender_label" class="text-sm font-semibold text-slate-900"></span>
            <span data-field="created_at" class="text-xs text-slate-400"></span>
        </div>
        <p data-field="message_body" class="mt-2 whitespace-pre-wrap text-sm text-slate-700"></p>
    </div>
</template>

@endsection
