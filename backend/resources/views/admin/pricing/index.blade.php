@extends('admin.layouts.app')

@section('title', 'Pricing — BLUE Admin')
@section('page-title', 'Pricing')

@section('content')

<div data-pricing-page class="space-y-6">

    <form data-pricing-filter-form class="rounded-2xl border border-slate-200 bg-white p-5">

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Status</label>
                <select
                    name="status"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
                    <option value="">All statuses</option>
                    <option value="DRAFT">Draft</option>
                    <option value="PUBLISHED">Published</option>
                    <option value="RETIRED">Retired</option>
                </select>
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Currency</label>
                <input
                    type="text"
                    name="currency"
                    placeholder="e.g. AED"
                    maxlength="3"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none placeholder:text-slate-400
                           focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>

            <div class="sm:col-span-2">
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Service UUID</label>
                <input
                    type="text"
                    name="service_uuid"
                    placeholder="Exact service uuid"
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
                data-pricing-clear-filters
                class="rounded-lg border border-slate-200 px-4 py-2 text-sm
                       font-medium text-slate-600 hover:bg-slate-50">
                Clear
            </button>
        </div>

    </form>

    <div class="rounded-2xl border border-slate-200 bg-white p-6">
        <h3 class="text-sm font-semibold text-slate-900">Create a Pricing Draft</h3>
        <p class="mt-1 text-xs text-slate-400">
            Creates a new DRAFT pricing scheme version for a Service + currency. Add rules and publish it
            from the scheme's detail page.
        </p>

        <form data-create-draft-form class="mt-3 flex flex-wrap items-end gap-3">
            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Service UUID</label>
                <input
                    type="text"
                    name="service_uuid"
                    required
                    class="w-80 rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Currency</label>
                <input
                    type="text"
                    name="currency_code"
                    required
                    maxlength="3"
                    value="AED"
                    class="w-28 rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
            </div>

            <button
                type="submit"
                data-create-draft-submit
                class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold
                       text-white transition hover:bg-slate-800
                       disabled:cursor-not-allowed disabled:opacity-50">
                Create draft
            </button>
        </form>

        <p data-create-draft-error class="hidden mt-2 text-sm text-red-600"></p>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white">

        <div data-pricing-loading class="p-10 text-center text-sm text-slate-500">
            Loading pricing schemes...
        </div>

        <div data-pricing-error class="hidden p-10 text-center text-sm text-red-600"></div>

        <div data-pricing-empty class="hidden p-10 text-center text-sm text-slate-500">
            No pricing scheme versions match these filters.
        </div>

        <div data-pricing-table-wrapper class="hidden overflow-x-auto">
            <table class="w-full min-w-[900px] text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs font-medium uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Service</th>
                        <th class="px-5 py-3">Currency</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Effective from</th>
                        <th class="px-5 py-3">Effective to</th>
                        <th class="px-5 py-3">Rules</th>
                        <th class="px-5 py-3">Updated</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody data-pricing-body class="divide-y divide-slate-100"></tbody>
            </table>
        </div>

        <div
            data-pricing-pagination
            style="display: none;"
            class="items-center justify-between border-t
                    border-slate-100 px-5 py-4 text-sm text-slate-600">
            <span data-pricing-pagination-summary></span>

            <div class="flex gap-2">
                <button
                    type="button"
                    data-pricing-prev-page
                    class="rounded-lg border border-slate-200 px-3 py-1.5
                           text-sm font-medium text-slate-700 hover:bg-slate-50
                           disabled:cursor-not-allowed disabled:opacity-50">
                    Previous
                </button>

                <button
                    type="button"
                    data-pricing-next-page
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
