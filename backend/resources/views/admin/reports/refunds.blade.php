@extends('admin.layouts.app')

@section('title', 'Refund Report — BLUE Admin')
@section('page-title', 'Refund Report')

@section('content')

<div data-report-page="refunds" class="space-y-6">

    <div class="print-only mb-4">
        <h2 class="text-lg font-bold">BLUE — Refund Report</h2>
        <p data-print-range class="text-sm text-slate-600"></p>
    </div>

    <div class="no-print">
        <h2 class="text-lg font-semibold text-slate-950">Refund Report</h2>
        <p class="mt-1 text-sm text-slate-500">Automated Booking refund activity — only confirmed (SUCCEEDED) refunds reduce the confirmed total</p>
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
            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Status</label>
                <select name="status" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                    <option value="">All</option>
                    <option value="PENDING">Pending</option>
                    <option value="SUCCEEDED">Succeeded</option>
                    <option value="FAILED">Failed</option>
                    <option value="RECONCILIATION_REQUIRED">Reconciliation required</option>
                </select>
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
    <div data-report-empty class="hidden rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500">No refunds match these filters.</div>

    <div data-report-content style="display: none;" class="flex flex-col gap-6">
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Confirmed Refunds</p>
                <p data-field="confirmed_count" class="mt-2 text-2xl font-semibold text-slate-950"></p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Confirmed Total</p>
                <p data-field="confirmed_total" class="mt-2 text-2xl font-semibold text-red-600"></p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Pending</p>
                <p data-field="pending_count" class="mt-2 text-2xl font-semibold text-amber-600"></p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Failed</p>
                <p data-field="failed_count" class="mt-2 text-2xl font-semibold text-red-600"></p>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white overflow-x-auto">
            <table class="w-full min-w-[900px] text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs font-medium uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Requested</th>
                        <th class="px-5 py-3">Booking #</th>
                        <th class="px-5 py-3">Original Payment Ref.</th>
                        <th class="px-5 py-3">Amount</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Reason</th>
                    </tr>
                </thead>
                <tbody data-table-body class="divide-y divide-slate-100"></tbody>
            </table>
        </div>

        <div data-pagination class="no-print flex items-center justify-between text-sm text-slate-600">
            <span data-pagination-summary></span>
            <div class="flex gap-2">
                <button type="button" data-prev-page class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50">Previous</button>
                <button type="button" data-next-page class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50">Next</button>
            </div>
        </div>
    </div>

</div>

@endsection
