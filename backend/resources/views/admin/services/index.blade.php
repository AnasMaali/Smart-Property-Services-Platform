@extends('admin.layouts.app')

@section('title', 'Services — BLUE Admin')
@section('page-title', 'Services')

@section('content')

<div data-services-page class="space-y-6">

    <div class="flex justify-end">
        <button
            type="button"
            data-add-service-open
            class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold
                   text-white transition hover:bg-slate-800">
            + Add service
        </button>
    </div>

    <form data-services-filter-form class="rounded-2xl border border-slate-200 bg-white p-5">

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Category</label>
                <select
                    name="category_id"
                    data-category-select
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
                    <option value="">All categories</option>
                </select>
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Status</label>
                <select
                    name="is_active"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
                    <option value="">All services</option>
                    <option value="1">Active only</option>
                    <option value="0">Inactive only</option>
                </select>
            </div>

            <div class="sm:col-span-2">
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Name search</label>
                <input
                    type="text"
                    name="search"
                    placeholder="Service name contains..."
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
                data-services-clear-filters
                class="rounded-lg border border-slate-200 px-4 py-2 text-sm
                       font-medium text-slate-600 hover:bg-slate-50">
                Clear
            </button>
        </div>

    </form>


    <div class="rounded-2xl border border-slate-200 bg-white">

        <div data-services-loading class="p-10 text-center text-sm text-slate-500">
            Loading services...
        </div>

        <div data-services-error class="hidden p-10 text-center text-sm text-red-600"></div>

        <div data-services-empty class="hidden p-10 text-center text-sm text-slate-500">
            No services match these filters.
        </div>

        <div data-services-table-wrapper class="hidden overflow-x-auto">
            <table class="w-full min-w-[900px] text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs font-medium uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Service</th>
                        <th class="px-5 py-3">Category</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Price (AED)</th>
                        <th class="px-5 py-3">Display order</th>
                        <th class="px-5 py-3">Capabilities</th>
                        <th class="px-5 py-3">Updated</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody data-services-body class="divide-y divide-slate-100"></tbody>
            </table>
        </div>

        <div
            data-services-pagination
            style="display: none;"
            class="items-center justify-between border-t
                    border-slate-100 px-5 py-4 text-sm text-slate-600">
            <span data-services-pagination-summary></span>

            <div class="flex gap-2">
                <button
                    type="button"
                    data-services-prev-page
                    class="rounded-lg border border-slate-200 px-3 py-1.5
                           text-sm font-medium text-slate-700 hover:bg-slate-50
                           disabled:cursor-not-allowed disabled:opacity-50">
                    Previous
                </button>

                <button
                    type="button"
                    data-services-next-page
                    class="rounded-lg border border-slate-200 px-3 py-1.5
                           text-sm font-medium text-slate-700 hover:bg-slate-50
                           disabled:cursor-not-allowed disabled:opacity-50">
                    Next
                </button>
            </div>
        </div>

    </div>

</div>

<div data-add-service-modal style="display: none;" class="fixed inset-0 z-50 items-center justify-center bg-slate-950/60 p-4">
    <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl">
        <h2 class="text-lg font-semibold text-slate-950">Add service</h2>
        <p class="mt-1 text-xs text-slate-500">
            Created inactive - configure category, pricing, specialization and options, then activate it.
        </p>

        <form data-add-service-form class="mt-4 space-y-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Category</label>
                <select name="category_id" data-add-service-category-select required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Code</label>
                    <input type="text" name="code" required minlength="2" maxlength="80" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Slug</label>
                    <input type="text" name="slug" required minlength="2" maxlength="160" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Name</label>
                <input type="text" name="name" required minlength="2" maxlength="160" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Short description</label>
                <input type="text" name="short_description" maxlength="300" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Description</label>
                <textarea name="description" rows="3" maxlength="5000" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
            </div>
            <div class="max-w-xs">
                <label class="mb-1 block text-xs font-medium text-slate-600">Display order</label>
                <input type="number" name="display_order" min="0" max="65535" value="0" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>

            <p data-add-service-error class="hidden text-sm text-red-600"></p>

            <div class="mt-2 flex justify-end gap-3">
                <button type="button" data-add-service-cancel class="rounded-xl px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" data-add-service-submit class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Create service</button>
            </div>
        </form>
    </div>
</div>

@endsection
