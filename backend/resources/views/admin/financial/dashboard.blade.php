@extends('admin.layouts.app')

@section('title', 'Financial Dashboard — BLUE Admin')
@section('page-title', 'Financial Dashboard')

@section('content')

<div data-financial-dashboard-page class="space-y-6">

    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-950">Financial Dashboard</h2>
            <p class="mt-1 text-sm text-slate-500">Authoritative collected revenue, refunds, and payment-method breakdown</p>
        </div>

        <a href="/admin/finance/ledger" class="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
            View Ledger
        </a>
    </div>

    <form data-financial-filter-form class="rounded-2xl border border-slate-200 bg-white p-5">

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Range</label>
                <select
                    name="range"
                    data-range-select
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
                    <option value="TODAY">Today</option>
                    <option value="LAST_7_DAYS">Last 7 days</option>
                    <option value="THIS_MONTH">This month</option>
                    <option value="CUSTOM">Custom range</option>
                </select>
            </div>

            <div data-custom-range-fields class="hidden">
                <label class="mb-1.5 block text-xs font-medium text-slate-600">From</label>
                <input
                    type="date"
                    name="from"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
            </div>

            <div data-custom-range-fields class="hidden">
                <label class="mb-1.5 block text-xs font-medium text-slate-600">To</label>
                <input
                    type="date"
                    name="to"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
            </div>

        </div>

        <div class="mt-4 flex items-center gap-3">
            <button
                type="submit"
                class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold
                       text-white transition hover:bg-slate-800">
                Apply
            </button>
            <span data-financial-range-summary class="text-xs text-slate-500"></span>
        </div>

    </form>

    <div data-financial-loading class="rounded-2xl border border-slate-200 bg-white p-10
                text-center text-sm text-slate-500">
        Loading financial summary...
    </div>

    <div data-financial-error class="hidden rounded-2xl border border-red-200 bg-red-50
                p-10 text-center text-sm text-red-700"></div>

    <div data-financial-content style="display: none;" class="flex flex-col gap-6">

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">

            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Gross Revenue</p>
                <p data-field="gross_revenue" class="mt-2 text-2xl font-semibold text-slate-950"></p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Refunds</p>
                <p data-field="refunds" class="mt-2 text-2xl font-semibold text-red-600"></p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Net Revenue</p>
                <p data-field="net_revenue" class="mt-2 text-2xl font-semibold text-emerald-600"></p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Pay on Site Collected</p>
                <p data-field="pay_on_site_collected" class="mt-2 text-2xl font-semibold text-slate-950"></p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Pay on Site Pending</p>
                <p data-field="pay_on_site_pending" class="mt-2 text-2xl font-semibold text-amber-600"></p>
                <p class="mt-1 text-[11px] text-slate-400">Current outstanding — not limited to the selected range</p>
            </div>

        </div>

        <div class="rounded-2xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-6 py-4">
                <h3 class="text-sm font-semibold text-slate-900">Payment method breakdown</h3>
            </div>
            <div class="grid grid-cols-1 gap-4 p-6 sm:grid-cols-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Credit Card</p>
                    <p data-field="breakdown_credit_card" class="mt-1 text-lg font-semibold text-slate-950"></p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Apple Pay</p>
                    <p data-field="breakdown_apple_pay" class="mt-1 text-lg font-semibold text-slate-950"></p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Pay on Site</p>
                    <p data-field="breakdown_pay_on_site" class="mt-1 text-lg font-semibold text-slate-950"></p>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-6 py-4">
                <h3 class="text-sm font-semibold text-slate-900">Bookings</h3>
            </div>
            <div class="grid grid-cols-1 gap-4 p-6 sm:grid-cols-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Paid bookings</p>
                    <p data-field="paid_count" class="mt-1 text-lg font-semibold text-slate-950"></p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Refunded bookings</p>
                    <p data-field="refunded_count" class="mt-1 text-lg font-semibold text-slate-950"></p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Pay-on-site pending bookings</p>
                    <p data-field="pay_on_site_pending_count" class="mt-1 text-lg font-semibold text-slate-950"></p>
                </div>
            </div>
            <div class="border-t border-slate-100 px-6 py-4">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Repair quote balance collected <span class="normal-case text-slate-400">(already included in the breakdown above)</span></p>
                <p data-field="repair_quote_balance_collected" class="mt-1 text-lg font-semibold text-slate-950"></p>
            </div>
        </div>

    </div>

</div>

@endsection
