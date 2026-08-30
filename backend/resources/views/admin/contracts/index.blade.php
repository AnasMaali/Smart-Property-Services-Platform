@extends('admin.layouts.app')

@section('title', 'Contracts — BLUE Admin')
@section('page-title', 'Contracts')

@section('content')

<div data-contracts-page class="space-y-6">

    <form data-contracts-filter-form class="rounded-2xl border border-slate-200 bg-white p-5">

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Status</label>
                <select
                    name="status"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
                    <option value="">All statuses</option>
                    <option value="REQUESTED">Requested</option>
                    <option value="APPROVED">Approved</option>
                    <option value="PENDING_CUSTOMER_ACCEPTANCE">Pending customer acceptance</option>
                    <option value="PENDING_PAYMENT">Pending payment</option>
                    <option value="ACTIVE">Active</option>
                    <option value="SUSPENDED">Suspended</option>
                    <option value="EXPIRED">Expired</option>
                    <option value="CANCELLED">Cancelled</option>
                </select>
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Contract number</label>
                <input
                    type="text"
                    name="contract_number"
                    placeholder="CTR-..."
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none placeholder:text-slate-400
                           focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>

            <div class="sm:col-span-2 lg:col-span-2">
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Customer UUID</label>
                <input
                    type="text"
                    name="customer_uuid"
                    placeholder="Exact customer uuid"
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
                data-contracts-clear-filters
                class="rounded-lg border border-slate-200 px-4 py-2 text-sm
                       font-medium text-slate-600 hover:bg-slate-50">
                Clear
            </button>
        </div>

    </form>


    <div class="rounded-2xl border border-slate-200 bg-white">

        <div data-contracts-loading class="p-10 text-center text-sm text-slate-500">
            Loading contracts...
        </div>

        <div data-contracts-error class="hidden p-10 text-center text-sm text-red-600"></div>

        <div data-contracts-empty class="hidden p-10 text-center text-sm text-slate-500">
            No contracts match these filters.
        </div>

        <div data-contracts-table-wrapper class="hidden overflow-x-auto">
            <table class="w-full min-w-[900px] text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs font-medium uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Contract</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Customer</th>
                        <th class="px-5 py-3">Items</th>
                        <th class="px-5 py-3">Term</th>
                        <th class="px-5 py-3">Created</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody data-contracts-body class="divide-y divide-slate-100"></tbody>
            </table>
        </div>

        <div
            data-contracts-pagination
            style="display: none;"
            class="items-center justify-between border-t
                    border-slate-100 px-5 py-4 text-sm text-slate-600">
            <span data-contracts-pagination-summary></span>

            <div class="flex gap-2">
                <button
                    type="button"
                    data-contracts-prev-page
                    class="rounded-lg border border-slate-200 px-3 py-1.5
                           text-sm font-medium text-slate-700 hover:bg-slate-50
                           disabled:cursor-not-allowed disabled:opacity-50">
                    Previous
                </button>

                <button
                    type="button"
                    data-contracts-next-page
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
