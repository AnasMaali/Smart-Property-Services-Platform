@extends('admin.layouts.app')

@section('title', 'Ratings — BLUE Admin')
@section('page-title', 'Ratings')

@section('content')

<div data-ratings-page class="space-y-6">

    <form data-ratings-filter-form class="rounded-2xl border border-slate-200 bg-white p-5">

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Rating</label>
                <select
                    name="rating_value"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
                    <option value="">All ratings</option>
                    <option value="5">5 stars</option>
                    <option value="4">4 stars</option>
                    <option value="3">3 stars</option>
                    <option value="2">2 stars</option>
                    <option value="1">1 star</option>
                </select>
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Max rating</label>
                <select
                    name="max_rating"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
                    <option value="">No maximum</option>
                    <option value="2">2 stars or fewer (review low ratings)</option>
                    <option value="3">3 stars or fewer</option>
                </select>
            </div>

            <div class="sm:col-span-2">
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
                data-ratings-clear-filters
                class="rounded-lg border border-slate-200 px-4 py-2 text-sm
                       font-medium text-slate-600 hover:bg-slate-50">
                Clear
            </button>
        </div>

    </form>


    <div class="rounded-2xl border border-slate-200 bg-white">

        <div data-ratings-loading class="p-10 text-center text-sm text-slate-500">
            Loading ratings...
        </div>

        <div data-ratings-error class="hidden p-10 text-center text-sm text-red-600"></div>

        <div data-ratings-empty class="hidden p-10 text-center text-sm text-slate-500">
            No ratings match these filters.
        </div>

        <div data-ratings-table-wrapper class="hidden overflow-x-auto">
            <table class="w-full min-w-[900px] text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs font-medium uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Booking</th>
                        <th class="px-5 py-3">Customer</th>
                        <th class="px-5 py-3">Rating</th>
                        <th class="px-5 py-3">Comment</th>
                        <th class="px-5 py-3">Submitted</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody data-ratings-body class="divide-y divide-slate-100"></tbody>
            </table>
        </div>

        <div
            data-ratings-pagination
            style="display: none;"
            class="items-center justify-between border-t
                    border-slate-100 px-5 py-4 text-sm text-slate-600">
            <span data-ratings-pagination-summary></span>

            <div class="flex gap-2">
                <button
                    type="button"
                    data-ratings-prev-page
                    class="rounded-lg border border-slate-200 px-3 py-1.5
                           text-sm font-medium text-slate-700 hover:bg-slate-50
                           disabled:cursor-not-allowed disabled:opacity-50">
                    Previous
                </button>

                <button
                    type="button"
                    data-ratings-next-page
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
