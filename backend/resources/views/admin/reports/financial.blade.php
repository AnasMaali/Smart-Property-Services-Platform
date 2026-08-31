@extends('admin.layouts.app')

@section('title', 'Financial Summary Report — BLUE Admin')
@section('page-title', 'Financial Summary Report')

@section('content')

<div data-report-page="financial" class="space-y-6">

    <div class="print-only mb-4">
        <h2 class="text-lg font-bold">BLUE — Financial Summary Report</h2>
        <p data-print-range class="text-sm text-slate-600"></p>
    </div>

    <div class="no-print flex items-center justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-950">Financial Summary Report</h2>
            <p class="mt-1 text-sm text-slate-500">Gross revenue, refunds, net revenue and payment-method breakdown for a selected period</p>
        </div>
    </div>

    <form data-report-filter-form class="no-print rounded-2xl border border-slate-200 bg-white p-5">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Range</label>
                <select name="range" data-range-select class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                    <option value="TODAY">Today</option>
                    <option value="LAST_7_DAYS">Last 7 days</option>
                    <option value="THIS_MONTH" selected>This month</option>
                    <option value="CUSTOM">Custom range</option>
                </select>
            </div>
            <div data-custom-range-fields class="hidden">
                <label class="mb-1.5 block text-xs font-medium text-slate-600">From</label>
                <input type="date" name="from" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>
            <div data-custom-range-fields class="hidden">
                <label class="mb-1.5 block text-xs font-medium text-slate-600">To</label>
                <input type="date" name="to" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button type="submit" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">Apply</button>
            <button type="button" data-report-reset class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">Reset</button>
            <span class="mx-1 h-5 w-px bg-slate-200"></span>
            <button type="button" data-report-print class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Print</button>
            <button type="button" data-export-csv class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Export CSV</button>
            <button type="button" data-export-pdf class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Export PDF</button>
            <span data-report-range-summary class="text-xs text-slate-500"></span>
        </div>
    </form>

    <div data-report-loading class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500">Loading report...</div>
    <div data-report-error class="hidden rounded-2xl border border-red-200 bg-red-50 p-10 text-center text-sm text-red-700"></div>

    <div data-report-content style="display: none;" class="flex flex-col gap-6">
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
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Credit Card</p>
                <p data-field="credit_card" class="mt-2 text-2xl font-semibold text-slate-950"></p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Apple Pay</p>
                <p data-field="apple_pay" class="mt-2 text-2xl font-semibold text-slate-950"></p>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-6 py-4">
                <h3 class="text-sm font-semibold text-slate-900">Pay on Site</h3>
            </div>
            <div class="grid grid-cols-1 gap-4 p-6 sm:grid-cols-2">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Collected</p>
                    <p data-field="pay_on_site_collected" class="mt-1 text-lg font-semibold text-slate-950"></p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Pending (current)</p>
                    <p data-field="pay_on_site_pending" class="mt-1 text-lg font-semibold text-amber-600"></p>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white overflow-x-auto">
            <div class="border-b border-slate-100 px-6 py-4">
                <h3 class="text-sm font-semibold text-slate-900">Daily breakdown</h3>
            </div>
            <div data-breakdown-truncated class="hidden px-6 py-4 text-xs text-amber-700"></div>
            <table class="w-full min-w-[700px] text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs font-medium uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Date</th>
                        <th class="px-5 py-3">Gross Revenue</th>
                        <th class="px-5 py-3">Refunds</th>
                        <th class="px-5 py-3">Net Revenue</th>
                    </tr>
                </thead>
                <tbody data-breakdown-body class="divide-y divide-slate-100"></tbody>
            </table>
        </div>
    </div>

</div>

@endsection
