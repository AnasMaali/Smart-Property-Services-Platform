@extends('admin.layouts.app')

@section('title', 'Contract Billing — BLUE Admin')
@section('page-title', 'Contract Billing')

@section('content')

<div data-billing-page class="space-y-6">

    <form data-billing-filter-form class="rounded-2xl border border-slate-200 bg-white p-5">

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Status</label>
                <select
                    name="status"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
                    <option value="">All statuses</option>
                    <option value="PENDING_CHECKOUT">Pending checkout</option>
                    <option value="INCOMPLETE">Incomplete</option>
                    <option value="ACTIVE">Active</option>
                    <option value="PAST_DUE">Past due</option>
                    <option value="CANCEL_AT_PERIOD_END">Cancel at period end</option>
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
                data-billing-clear-filters
                class="rounded-lg border border-slate-200 px-4 py-2 text-sm
                       font-medium text-slate-600 hover:bg-slate-50">
                Clear
            </button>
        </div>

    </form>


    <div class="rounded-2xl border border-slate-200 bg-white">

        <div data-billing-loading class="p-10 text-center text-sm text-slate-500">
            Loading contract billings...
        </div>

        <div data-billing-error class="hidden p-10 text-center text-sm text-red-600"></div>

        <div data-billing-empty class="hidden p-10 text-center text-sm text-slate-500">
            No contract billings match these filters.
        </div>

        <div data-billing-table-wrapper class="hidden overflow-x-auto">
            <table class="w-full min-w-[900px] text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs font-medium uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Contract</th>
                        <th class="px-5 py-3">Customer</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Recurring amount</th>
                        <th class="px-5 py-3">Current period end</th>
                        <th class="px-5 py-3">Cancel at</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody data-billing-body class="divide-y divide-slate-100"></tbody>
            </table>
        </div>

        <div
            data-billing-pagination
            style="display: none;"
            class="items-center justify-between border-t
                    border-slate-100 px-5 py-4 text-sm text-slate-600">
            <span data-billing-pagination-summary></span>

            <div class="flex gap-2">
                <button
                    type="button"
                    data-billing-prev-page
                    class="rounded-lg border border-slate-200 px-3 py-1.5
                           text-sm font-medium text-slate-700 hover:bg-slate-50
                           disabled:cursor-not-allowed disabled:opacity-50">
                    Previous
                </button>

                <button
                    type="button"
                    data-billing-next-page
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
