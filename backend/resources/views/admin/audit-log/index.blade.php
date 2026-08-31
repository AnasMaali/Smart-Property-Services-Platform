@extends('admin.layouts.app')

@section('title', 'Audit Log — BLUE Admin')
@section('page-title', 'Audit Log')

@section('content')

<div data-audit-log-page class="space-y-6">

    <form data-audit-log-filter-form class="no-print rounded-2xl border border-slate-200 bg-white p-5">

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Action code</label>
                <input
                    type="text"
                    name="action_code"
                    placeholder="e.g. CONTRACT_CANCELLED"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none placeholder:text-slate-400
                           focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Entity type</label>
                <input
                    type="text"
                    name="entity_type"
                    placeholder="e.g. SERVICE_CONTRACT"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none placeholder:text-slate-400
                           focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Entity identifier</label>
                <input
                    type="text"
                    name="entity_identifier"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Outcome</label>
                <select
                    name="was_successful"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
                    <option value="">All</option>
                    <option value="1">Successful only</option>
                    <option value="0">Failed only</option>
                </select>
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Actor UUID</label>
                <input
                    type="text"
                    name="actor_uuid"
                    placeholder="Exact admin uuid"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none placeholder:text-slate-400
                           focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">From</label>
                <input
                    type="datetime-local"
                    name="from"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">To</label>
                <input
                    type="datetime-local"
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
                Apply filters
            </button>

            <button
                type="button"
                data-audit-log-clear-filters
                class="rounded-lg border border-slate-200 px-4 py-2 text-sm
                       font-medium text-slate-600 hover:bg-slate-50">
                Clear
            </button>

            <span class="mx-1 h-5 w-px bg-slate-200"></span>

            <button
                type="button"
                data-audit-log-print
                class="rounded-lg border border-slate-300 px-4 py-2 text-sm
                       font-medium text-slate-700 hover:bg-slate-50">
                Print
            </button>

            <button
                type="button"
                data-audit-log-export-csv
                class="rounded-lg border border-slate-300 px-4 py-2 text-sm
                       font-medium text-slate-700 hover:bg-slate-50">
                Export CSV
            </button>

            <button
                type="button"
                data-audit-log-export-pdf
                class="rounded-lg border border-slate-300 px-4 py-2 text-sm
                       font-medium text-slate-700 hover:bg-slate-50">
                Export PDF
            </button>
        </div>

    </form>

    <div class="print-only mb-4">
        <h2 class="text-lg font-bold">BLUE — Audit Log Export</h2>
        <p class="text-sm text-slate-600">Current filtered view</p>
    </div>


    <div class="rounded-2xl border border-slate-200 bg-white">

        <div data-audit-log-loading class="p-10 text-center text-sm text-slate-500">
            Loading audit log...
        </div>

        <div data-audit-log-error class="hidden p-10 text-center text-sm text-red-600"></div>

        <div data-audit-log-empty class="hidden p-10 text-center text-sm text-slate-500">
            No audit log entries match these filters.
        </div>

        <div data-audit-log-table-wrapper class="hidden overflow-x-auto">
            <table class="w-full min-w-[900px] text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs font-medium uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Action</th>
                        <th class="px-5 py-3">Entity</th>
                        <th class="px-5 py-3">Actor</th>
                        <th class="px-5 py-3">Outcome</th>
                        <th class="px-5 py-3">When</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody data-audit-log-body class="divide-y divide-slate-100"></tbody>
            </table>
        </div>

        <div
            data-audit-log-pagination
            style="display: none;"
            class="no-print items-center justify-between border-t
                    border-slate-100 px-5 py-4 text-sm text-slate-600">
            <span data-audit-log-pagination-summary></span>

            <div class="flex gap-2">
                <button
                    type="button"
                    data-audit-log-prev-page
                    class="rounded-lg border border-slate-200 px-3 py-1.5
                           text-sm font-medium text-slate-700 hover:bg-slate-50
                           disabled:cursor-not-allowed disabled:opacity-50">
                    Previous
                </button>

                <button
                    type="button"
                    data-audit-log-next-page
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
