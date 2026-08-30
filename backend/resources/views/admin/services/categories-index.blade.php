@extends('admin.layouts.app')

@section('title', 'Service Categories — BLUE Admin')
@section('page-title', 'Service Categories')

@section('content')

<div data-categories-page class="space-y-6">

    <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-5">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Status</label>
                <select
                    data-status-filter
                    class="w-48 rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
                    <option value="">All categories</option>
                    <option value="1">Active only</option>
                    <option value="0">Inactive only</option>
                </select>
            </div>
            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Search</label>
                <input
                    type="text"
                    data-search-filter
                    placeholder="Category name contains..."
                    class="w-56 rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none placeholder:text-slate-400
                           focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button
                type="button"
                data-add-category-open
                class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold
                       text-white transition hover:bg-slate-800">
                + Add category
            </button>
            <a
                href="/admin/services"
                class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm
                       font-semibold text-slate-700 hover:bg-slate-50">
                View all Services &rarr;
            </a>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white">

        <div data-categories-loading class="p-10 text-center text-sm text-slate-500">
            Loading service categories...
        </div>

        <div data-categories-error class="hidden p-10 text-center text-sm text-red-600"></div>

        <div data-categories-empty class="hidden p-10 text-center text-sm text-slate-500">
            No service categories match this filter.
        </div>

        <div data-categories-table-wrapper class="hidden overflow-x-auto">
            <table class="w-full min-w-[700px] text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs font-medium uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Category</th>
                        <th class="px-5 py-3">Code</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Display order</th>
                        <th class="px-5 py-3">Services</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody data-categories-body class="divide-y divide-slate-100"></tbody>
            </table>
        </div>

    </div>

</div>

<div data-add-category-modal style="display: none;" class="fixed inset-0 z-50 items-center justify-center bg-slate-950/60 p-4">
    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
        <h2 class="text-lg font-semibold text-slate-950">Add category</h2>

        <form data-add-category-form class="mt-4 space-y-3">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Code</label>
                    <input type="text" name="code" required minlength="2" maxlength="60" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Display order</label>
                    <input type="number" name="display_order" min="0" max="65535" value="0" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Name</label>
                <input type="text" name="name" required minlength="2" maxlength="120" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Description</label>
                <input type="text" name="description" maxlength="500" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <label class="flex items-center gap-1.5 text-sm">
                <input type="checkbox" name="is_active" checked class="rounded border-slate-300">
                Active
            </label>

            <p data-add-category-error class="hidden text-sm text-red-600"></p>

            <div class="mt-2 flex justify-end gap-3">
                <button type="button" data-add-category-cancel class="rounded-xl px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" data-add-category-submit class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Create category</button>
            </div>
        </form>
    </div>
</div>

@endsection
