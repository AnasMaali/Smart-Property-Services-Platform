@extends('admin.layouts.app')

@section('title', 'Payment — BLUE Admin')
@section('page-title', 'Payment detail')

@section('content')

<div data-payment-detail-page data-payment-uuid="{{ $paymentUuid }}" class="space-y-6">

    <a
        href="/admin/payments"
        class="inline-flex items-center gap-1.5 text-sm font-medium
               text-slate-500 hover:text-slate-800">
        &larr; Back to payments
    </a>

    <div data-payment-loading class="rounded-2xl border border-slate-200 bg-white p-10
                text-center text-sm text-slate-500">
        Loading payment...
    </div>

    <div data-payment-error class="hidden rounded-2xl border border-red-200 bg-red-50
                p-10 text-center text-sm text-red-700"></div>

    <div data-payment-content style="display: none;" class="flex flex-col gap-6">

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Payment</p>
                    <h2 data-field="checkout_reference" class="mt-1 text-2xl font-semibold text-slate-950"></h2>
                    <p class="mt-1 text-xs text-slate-400">
                        Provider <span data-field="provider"></span>
                        &middot; Created <span data-field="created_at"></span>
                    </p>
                </div>

                <span data-field="status" data-status-badge
                      class="rounded-full px-3 py-1.5 text-xs font-semibold"></span>
            </div>
        </div>

        <div data-failure-box style="display: none;" class="rounded-2xl border border-red-200
                    bg-red-50 p-6 text-sm text-red-700">
            <p class="font-medium">Failure: <span data-field="failure_code"></span></p>
            <p data-field="failure_message" class="mt-1"></p>
        </div>

        <div data-reconciliation-box style="display: none;" class="rounded-2xl border
                    border-amber-200 bg-amber-50 p-6 text-sm text-amber-800">
            <p class="font-medium">Requires reconciliation: <span data-field="reconciliation_reason_code"></span></p>
            <p class="mt-1">Reconciled at <span data-field="reconciled_at"></span></p>
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
                <h3 class="text-sm font-semibold text-slate-900">Amount</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Requested</dt>
                        <dd data-field="requested_amount" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Confirmed</dt>
                        <dd data-field="confirmed_amount" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Method</dt>
                        <dd data-field="payment_method_type" class="font-medium text-slate-900"></dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h3 class="text-sm font-semibold text-slate-900">Related booking</h3>
                <div data-booking-link-wrapper>
                    <p data-no-booking class="text-sm text-slate-400">No booking created from this payment.</p>
                    <a data-booking-link style="display: none;" class="text-sm font-medium text-blue-600 hover:text-blue-800">
                        <span data-field="booking_number"></span> &rarr;
                    </a>
                </div>
            </div>

        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Provider &amp; lifecycle</h3>
            <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Session reference</dt>
                    <dd data-field="provider_session_reference" class="font-medium text-slate-900"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Transaction reference</dt>
                    <dd data-field="provider_transaction_reference" class="font-medium text-slate-900"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Provider status</dt>
                    <dd data-field="provider_status_code" class="font-medium text-slate-900"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Expires at</dt>
                    <dd data-field="expires_at" class="font-medium text-slate-900"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Successful at</dt>
                    <dd data-field="successful_at" class="font-medium text-slate-900"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Finalized at</dt>
                    <dd data-field="finalized_at" class="font-medium text-slate-900"></dd>
                </div>
            </dl>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-6 py-4">
                <h3 class="text-sm font-semibold text-slate-900">Recent webhook events</h3>
            </div>
            <div data-webhook-events-empty class="hidden px-6 py-6 text-sm text-slate-500">
                No webhook events recorded for this payment yet.
            </div>
            <div data-webhook-events class="divide-y divide-slate-100 text-sm"></div>
        </div>

    </div>

</div>

<template data-webhook-event-row-template>
    <div class="flex flex-wrap items-center justify-between gap-3 p-4">
        <div>
            <p data-field="event_type" class="font-medium text-slate-900"></p>
            <p class="mt-0.5 text-xs text-slate-500">
                <span data-field="provider_event_id"></span>
                &middot; Received <span data-field="received_at"></span>
            </p>
            <p data-field="error" class="mt-0.5 text-xs text-red-600"></p>
        </div>
        <span data-field="status" data-status-badge class="rounded-full px-2.5 py-1 text-xs font-semibold"></span>
    </div>
</template>

@endsection
