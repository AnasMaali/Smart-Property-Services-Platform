@extends('admin.layouts.app')

@section('title', 'Contract Billing — BLUE Admin')
@section('page-title', 'Contract Billing detail')

@section('content')

<div data-billing-detail-page data-billing-uuid="{{ $billingUuid }}" class="space-y-6">

    <a
        href="/admin/billing"
        class="inline-flex items-center gap-1.5 text-sm font-medium
               text-slate-500 hover:text-slate-800">
        &larr; Back to contract billing
    </a>

    <div data-billing-loading class="rounded-2xl border border-slate-200 bg-white p-10
                text-center text-sm text-slate-500">
        Loading contract billing...
    </div>

    <div data-billing-error class="hidden rounded-2xl border border-red-200 bg-red-50
                p-10 text-center text-sm text-red-700"></div>

    <div data-billing-content style="display: none;" class="flex flex-col gap-6">

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Contract billing</p>
                    <a data-contract-link class="mt-1 block text-2xl font-semibold text-blue-600 hover:text-blue-800">
                        <span data-field="contract_number"></span>
                    </a>
                    <p class="mt-1 text-xs text-slate-400">
                        Provider <span data-field="provider"></span>
                        &middot; Created <span data-field="created_at"></span>
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
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h3 class="text-sm font-semibold text-slate-900">Billing terms</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Interval</dt>
                        <dd data-field="billing_interval" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Recurring amount</dt>
                        <dd data-field="recurring_amount" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Contract status</dt>
                        <dd data-field="contract_status" class="font-medium text-slate-900"></dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h3 class="text-sm font-semibold text-slate-900">Current period</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Starts</dt>
                        <dd data-field="current_period_start" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Ends</dt>
                        <dd data-field="current_period_end" class="font-medium text-slate-900"></dd>
                    </div>
                </dl>
            </div>

        </div>

        <div data-past-due-box style="display: none;" class="rounded-2xl border border-red-200
                    bg-red-50 p-6 text-sm text-red-700">
            Past due since <span data-field="past_due_since"></span>
        </div>

        <div data-cancellation-box style="display: none;" class="rounded-2xl border
                    border-amber-200 bg-amber-50 p-6 text-sm text-amber-800">
            <p class="font-medium">Cancellation state</p>
            <dl class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                <div class="flex justify-between gap-4">
                    <dt>Cancel at</dt>
                    <dd data-field="cancel_at" class="font-medium"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt>Cancelled at</dt>
                    <dd data-field="cancelled_at" class="font-medium"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt>Cancellation requested at</dt>
                    <dd data-field="provider_cancellation_requested_at" class="font-medium"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt>Last cancellation attempt</dt>
                    <dd data-field="provider_cancellation_last_attempt_at" class="font-medium"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt>Attempt count</dt>
                    <dd data-field="provider_cancellation_attempt_count" class="font-medium"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt>Billing suspended at</dt>
                    <dd data-field="billing_suspended_at" class="font-medium"></dd>
                </div>
            </dl>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Stripe references</h3>
            <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Customer</dt>
                    <dd data-field="stripe_customer_id" class="font-medium text-slate-900"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Subscription</dt>
                    <dd data-field="stripe_subscription_id" class="font-medium text-slate-900"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Price</dt>
                    <dd data-field="stripe_price_id" class="font-medium text-slate-900"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Product</dt>
                    <dd data-field="stripe_product_id" class="font-medium text-slate-900"></dd>
                </div>
            </dl>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-6 py-4">
                <h3 class="text-sm font-semibold text-slate-900">Recent webhook events</h3>
            </div>
            <div data-webhook-events-empty class="hidden px-6 py-6 text-sm text-slate-500">
                No webhook events recorded for this contract billing yet.
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
