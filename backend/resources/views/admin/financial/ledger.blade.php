@extends('admin.layouts.app')

@section('title', 'Financial Ledger — BLUE Admin')
@section('page-title', 'Financial Ledger')

@section('content')

<div data-ledger-page class="space-y-6">

    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-lg font-semibold text-slate-950">Financial Ledger</h2>
            <p class="mt-1 text-sm text-slate-500">Chronological, read-only record of real money movement</p>
        </div>

        <a href="/admin/finance" class="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
            View Dashboard
        </a>
    </div>

    <form data-ledger-filter-form class="rounded-2xl border border-slate-200 bg-white p-5">

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Event type</label>
                <select
                    name="event_type"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
                    <option value="">All event types</option>
                    <option value="CARD_PAYMENT">Card payment</option>
                    <option value="APPLE_PAY_PAYMENT">Apple Pay payment</option>
                    <option value="PAY_ON_SITE_COLLECTION">Pay on Site collection</option>
                    <option value="REFUND">Refund</option>
                    <option value="REPAIR_QUOTE_BALANCE_PAYMENT">Repair quote balance payment</option>
                </select>
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Direction</label>
                <select
                    name="direction"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
                    <option value="">Both</option>
                    <option value="CREDIT">Credit</option>
                    <option value="DEBIT">Debit</option>
                </select>
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">From</label>
                <input
                    type="date"
                    name="from"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">To</label>
                <input
                    type="date"
                    name="to"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
            </div>

            <div class="sm:col-span-2 lg:col-span-2">
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Booking UUID</label>
                <input
                    type="text"
                    name="booking_uuid"
                    placeholder="Exact booking uuid"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none placeholder:text-slate-400
                           focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>

        </div>

        <div class="mt-4 flex items-center gap-3">
            <button
                type="submit"
                class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold
                       text-white transition hover:bg-slate-800">
                Apply filters
            </button>

            <button
                type="button"
                data-ledger-clear-filters
                class="rounded-lg border border-slate-200 px-4 py-2 text-sm
                       font-medium text-slate-600 hover:bg-slate-50">
                Clear
            </button>
        </div>

    </form>


    <div class="rounded-2xl border border-slate-200 bg-white">

        <div data-ledger-loading class="p-10 text-center text-sm text-slate-500">
            Loading ledger...
        </div>

        <div data-ledger-error class="hidden p-10 text-center text-sm text-red-600"></div>

        <div data-ledger-empty class="hidden p-10 text-center text-sm text-slate-500">
            No ledger entries match these filters.
        </div>

        <div data-ledger-table-wrapper class="hidden overflow-x-auto">
            <table class="w-full min-w-[1000px] text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs font-medium uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Occurred</th>
                        <th class="px-5 py-3">Event</th>
                        <th class="px-5 py-3">Direction</th>
                        <th class="px-5 py-3">Method</th>
                        <th class="px-5 py-3">Amount</th>
                        <th class="px-5 py-3">Customer</th>
                        <th class="px-5 py-3">Booking</th>
                    </tr>
                </thead>
                <tbody data-ledger-body class="divide-y divide-slate-100"></tbody>
            </table>
        </div>

        <div
            data-ledger-pagination
            style="display: none;"
            class="items-center justify-between border-t
                    border-slate-100 px-5 py-4 text-sm text-slate-600">
            <span data-ledger-pagination-summary></span>

            <div class="flex gap-2">
                <button
                    type="button"
                    data-ledger-prev-page
                    class="rounded-lg border border-slate-200 px-3 py-1.5
                           text-sm font-medium text-slate-700 hover:bg-slate-50
                           disabled:cursor-not-allowed disabled:opacity-50">
                    Previous
                </button>

                <button
                    type="button"
                    data-ledger-next-page
                    class="rounded-lg border border-slate-200 px-3 py-1.5
                           text-sm font-medium text-slate-700 hover:bg-slate-50
                           disabled:cursor-not-allowed disabled:opacity-50">
                    Next
                </button>
            </div>
        </div>

    </div>

</div>

@endsection
