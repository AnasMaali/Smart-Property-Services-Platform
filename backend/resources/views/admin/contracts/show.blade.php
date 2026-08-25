@extends('admin.layouts.app')

@section('title', 'Contract — BLUE Admin')
@section('page-title', 'Contract detail')

@section('content')

<div data-contract-detail-page data-contract-uuid="{{ $contractUuid }}" class="space-y-6">

    <a
        href="/admin/contracts"
        class="inline-flex items-center gap-1.5 text-sm font-medium
               text-slate-500 hover:text-slate-800">
        &larr; Back to contracts
    </a>

    <div data-contract-loading class="rounded-2xl border border-slate-200 bg-white p-10
                text-center text-sm text-slate-500">
        Loading contract...
    </div>

    <div data-contract-error class="hidden rounded-2xl border border-red-200 bg-red-50
                p-10 text-center text-sm text-red-700"></div>

    <div data-contract-content style="display: none;" class="flex flex-col gap-6">

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Contract</p>
                    <h2 data-field="contract_number" class="mt-1 text-2xl font-semibold text-slate-950"></h2>
                    <p class="mt-1 text-xs text-slate-400">
                        Created <span data-field="created_at"></span>
                        &middot; Updated <span data-field="updated_at"></span>
                    </p>
                </div>

                <span data-field="status" data-status-badge
                      class="rounded-full px-3 py-1.5 text-xs font-semibold"></span>
            </div>

            <div data-contract-actions class="mt-5 flex flex-wrap gap-2"></div>
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
                <h3 class="text-sm font-semibold text-slate-900">Term</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Starts</dt>
                        <dd data-field="starts_at" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Ends</dt>
                        <dd data-field="ends_at" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Term (months)</dt>
                        <dd data-field="term_months" class="font-medium text-slate-900"></dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h3 class="text-sm font-semibold text-slate-900">Acceptance</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Accepted</dt>
                        <dd data-field="accepted" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Accepted at</dt>
                        <dd data-field="accepted_at" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Quoted amount</dt>
                        <dd data-field="quoted_amount" class="font-medium text-slate-900"></dd>
                    </div>
                </dl>
            </div>

        </div>

        <div data-billing-card style="display: none;" class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-900">Billing</h3>
                <div class="flex items-center gap-3">
                    <span data-field="billing_status" data-status-badge
                          class="rounded-full px-2.5 py-1 text-xs font-semibold"></span>
                    <a data-billing-detail-link class="text-xs font-medium text-blue-600 hover:text-blue-800">
                        View billing detail &rarr;
                    </a>
                </div>
            </div>

            <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Provider</dt>
                    <dd data-field="billing_provider" class="font-medium text-slate-900"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Recurring amount</dt>
                    <dd data-field="billing_recurring_amount" class="font-medium text-slate-900"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Interval</dt>
                    <dd data-field="billing_interval" class="font-medium text-slate-900"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Current period</dt>
                    <dd data-field="billing_current_period" class="font-medium text-slate-900"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Past due since</dt>
                    <dd data-field="billing_past_due_since" class="font-medium text-slate-900"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Cancel at</dt>
                    <dd data-field="billing_cancel_at" class="font-medium text-slate-900"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Stripe subscription</dt>
                    <dd data-field="billing_stripe_subscription_id" class="font-medium text-slate-900"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Stripe customer</dt>
                    <dd data-field="billing_stripe_customer_id" class="font-medium text-slate-900"></dd>
                </div>
            </dl>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-6 py-4">
                <h3 class="text-sm font-semibold text-slate-900">Covered services</h3>
            </div>
            <div data-covered-services-empty class="hidden px-6 py-6 text-sm text-slate-500">
                No covered services yet - this contract has not been approved.
            </div>
            <div data-covered-services class="divide-y divide-slate-100"></div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Original request</h3>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Requested all services</dt>
                    <dd data-field="requested_all_services" class="font-medium text-slate-900"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Requested start date</dt>
                    <dd data-field="requested_starts_on" class="font-medium text-slate-900"></dd>
                </div>
            </dl>
            <div class="mt-4">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Customer note</p>
                <p data-field="customer_note" class="mt-1 text-sm leading-6 text-slate-600"></p>
            </div>
            <div class="mt-4">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Internal note</p>
                <p data-field="internal_note" class="mt-1 text-sm leading-6 text-slate-600"></p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

            <div class="rounded-2xl border border-slate-200 bg-white">
                <div class="border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">Status history</h3>
                </div>
                <div data-status-history class="divide-y divide-slate-100 text-sm"></div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white">
                <div class="border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">Linked bookings</h3>
                </div>
                <div data-linked-bookings-empty class="hidden px-6 py-6 text-sm text-slate-500">
                    No bookings created from this contract yet.
                </div>
                <div data-linked-bookings class="divide-y divide-slate-100 text-sm"></div>
            </div>

        </div>

    </div>

</div>


<template data-covered-service-template>
    <div class="flex flex-wrap items-center justify-between gap-3 p-5">
        <div>
            <p data-field="service_name" class="font-medium text-slate-900"></p>
            <p class="mt-0.5 text-xs text-slate-500">Service code <span data-field="service_code"></span></p>
        </div>
        <div class="text-right text-sm">
            <p data-field="entitlement_summary" class="font-medium text-slate-900"></p>
        </div>
    </div>
</template>

<template data-status-history-row-template>
    <div class="p-4">
        <p class="text-sm">
            <span data-field="from_status" class="font-medium text-slate-700"></span>
            &rarr;
            <span data-field="to_status" class="font-medium text-slate-900"></span>
        </p>
        <p data-field="reason" class="mt-0.5 text-xs text-slate-500"></p>
        <p data-field="changed_at" class="mt-0.5 text-xs text-slate-400"></p>
    </div>
</template>

<template data-linked-booking-row-template>
    <a data-booking-link class="flex items-center justify-between gap-3 p-4 hover:bg-slate-50">
        <div>
            <p data-field="booking_number" class="font-medium text-slate-900"></p>
            <p data-field="created_at" class="mt-0.5 text-xs text-slate-400"></p>
        </div>
        <span data-field="status" data-status-badge class="rounded-full px-2.5 py-1 text-xs font-semibold"></span>
    </a>
</template>


<div
    data-approve-modal
    style="display: none;"
    class="fixed inset-0 z-50 items-center justify-center overflow-y-auto bg-slate-950/60 p-4">

    <div class="my-8 w-full max-w-2xl rounded-2xl bg-white p-6 shadow-xl">

        <h2 class="text-lg font-semibold text-slate-950">Approve contract</h2>
        <p class="mt-1 text-sm text-slate-500">
            Finalize the term, covered services and recurring billing terms for this contract.
        </p>

        <div data-approve-modal-error class="mt-4 hidden rounded-xl border border-red-200
                    bg-red-50 px-4 py-3 text-sm text-red-700"></div>

        <form data-approve-modal-form class="mt-4 max-h-[70vh] space-y-5 overflow-y-auto pr-1">

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Starts at</label>
                    <input name="starts_at" type="date" required
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm
                               outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Ends at</label>
                    <input name="ends_at" type="date" required
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm
                               outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Term (months, optional)</label>
                    <input name="term_months" type="number" min="1" max="120"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm
                               outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between">
                    <label class="block text-xs font-medium text-slate-600">Covered services</label>
                    <button type="button" data-approve-add-service
                        class="text-xs font-semibold text-blue-600 hover:text-blue-800">
                        + Add service
                    </button>
                </div>
                <div data-approve-services class="mt-2 space-y-2"></div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Quoted amount (optional)</label>
                    <input name="quoted_amount" type="number" step="0.01" min="0"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm
                               outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Quoted currency (optional)</label>
                    <input name="currency_code" type="text" maxlength="3" placeholder="AED"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm
                               uppercase outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                </div>
            </div>

            <div class="rounded-xl border border-slate-100 bg-slate-50 p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">
                    Recurring billing terms
                </p>

                <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-slate-600">Interval</label>
                        <select name="billing_interval" required
                            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm
                                   outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                            <option value="MONTHLY">Monthly</option>
                            <option value="YEARLY">Yearly</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-slate-600">Recurring amount</label>
                        <input name="recurring_amount" type="number" step="0.01" min="0.01" required
                            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm
                                   outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-slate-600">Billing currency</label>
                        <input name="billing_currency_code" type="text" maxlength="3" placeholder="AED" required
                            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm
                                   uppercase outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                    </div>
                </div>
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Internal note (optional)</label>
                <textarea name="internal_note" rows="2"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm
                           outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100"></textarea>
            </div>

            <div class="flex justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" data-approve-modal-cancel
                    class="rounded-xl px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">
                    Cancel
                </button>
                <button type="submit" data-approve-modal-submit
                    class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white
                           transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60">
                    Approve contract
                </button>
            </div>

        </form>

    </div>

</div>

<template data-approve-service-row-template>
    <div class="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 p-2" data-service-row>
        <select data-role="service_uuid" required
            class="min-w-[220px] flex-1 rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm
                   outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            <option value="">Loading services...</option>
        </select>
        <select data-role="entitlement_mode"
            class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm
                   outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            <option value="LIMITED_VISITS">Limited visits</option>
            <option value="UNLIMITED">Unlimited</option>
        </select>
        <input data-role="included_visits" type="number" min="1" max="1000" placeholder="Visits"
            class="w-24 rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm
                   outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
        <button type="button" data-role="remove"
            class="rounded-lg px-2 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50">
            Remove
        </button>
    </div>
</template>


<div
    data-confirm-action-modal
    style="display: none;"
    class="fixed inset-0 z-50 items-center justify-center bg-slate-950/60 p-4">

    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">

        <h2 data-confirm-action-title class="text-lg font-semibold text-slate-950"></h2>
        <p data-confirm-action-message class="mt-2 text-sm leading-6 text-slate-500"></p>

        <div data-confirm-action-error class="mt-4 hidden rounded-xl border border-red-200
                    bg-red-50 px-4 py-3 text-sm text-red-700"></div>

        <div data-confirm-action-reason-field class="mt-3">
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
