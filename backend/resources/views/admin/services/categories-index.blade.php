@extends('admin.layouts.app')

@section('title', 'Service Categories — BLUE Admin')
@section('page-title', 'Service Categories')

@section('content')

<div data-categories-page class="space-y-6">

    <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-5">
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

        <a
            href="/admin/services"
            class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm
                   font-semibold text-slate-700 hover:bg-slate-50">
            View all Services &rarr;
        </a>
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

@endsection
