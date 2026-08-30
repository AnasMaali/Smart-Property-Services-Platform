@extends('admin.layouts.app')

@section('title', 'Technicians — BLUE Admin')
@section('page-title', 'Technicians')

@section('content')

<div data-technicians-page class="space-y-6">

    <div class="flex items-center justify-between">
        <p class="text-sm text-slate-500">Manage the Technician roster: profile, status, specializations, and performance.</p>
        <button
            type="button"
            data-add-technician-open
            class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">
            + Add Technician
        </button>
    </div>

    <form data-technicians-filter-form class="rounded-2xl border border-slate-200 bg-white p-5">

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Search</label>
                <input
                    type="text"
                    name="q"
                    placeholder="Name, phone, or email"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none placeholder:text-slate-400
                           focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Status</label>
                <select
                    name="status"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
                    <option value="">All statuses</option>
                    <option value="AVAILABLE">Available</option>
                    <option value="BUSY">Busy</option>
                    <option value="ON_LEAVE">On leave</option>
                    <option value="INACTIVE">Inactive</option>
                </select>
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Assignable</label>
                <select
                    name="assignable"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
                    <option value="">Any</option>
                    <option value="1">Assignable only</option>
                    <option value="0">Not assignable</option>
                </select>
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Specialization code</label>
                <input
                    type="text"
                    name="specialization"
                    placeholder="e.g. AC_TECHNICIAN"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none placeholder:text-slate-400
                           focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Sort by</label>
                <select
                    name="sort"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                           text-sm text-slate-900 outline-none focus:border-blue-500
                           focus:ring-4 focus:ring-blue-100">
                    <option value="name">Name</option>
                    <option value="newest">Newest</option>
                    <option value="rating">Rating</option>
                    <option value="completed_jobs">Completed jobs</option>
                    <option value="active_jobs">Active jobs</option>
                </select>
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
                data-technicians-clear-filters
                class="rounded-lg border border-slate-200 px-4 py-2 text-sm
                       font-medium text-slate-600 hover:bg-slate-50">
                Clear
            </button>
        </div>

    </form>


    <div class="rounded-2xl border border-slate-200 bg-white">

        <div data-technicians-loading class="p-10 text-center text-sm text-slate-500">
            Loading technicians...
        </div>

        <div data-technicians-error class="hidden p-10 text-center text-sm text-red-600"></div>

        <div data-technicians-empty class="hidden p-10 text-center text-sm text-slate-500">
            No technicians match these filters.
        </div>

        <div data-technicians-table-wrapper class="hidden overflow-x-auto">
            <table class="w-full min-w-[1000px] text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs font-medium uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Technician</th>
                        <th class="px-5 py-3">Contact</th>
                        <th class="px-5 py-3">Specializations</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Rating</th>
                        <th class="px-5 py-3">Completed</th>
                        <th class="px-5 py-3">Active jobs</th>
                        <th class="px-5 py-3">Since</th>
                    </tr>
                </thead>
                <tbody data-technicians-body class="divide-y divide-slate-100"></tbody>
            </table>
        </div>

        <div
            data-technicians-pagination
            style="display: none;"
            class="items-center justify-between border-t
                    border-slate-100 px-5 py-4 text-sm text-slate-600">
            <span data-technicians-pagination-summary></span>

            <div class="flex gap-2">
                <button
                    type="button"
                    data-technicians-prev-page
                    class="rounded-lg border border-slate-200 px-3 py-1.5
                           text-sm font-medium text-slate-700 hover:bg-slate-50
                           disabled:cursor-not-allowed disabled:opacity-50">
                    Previous
                </button>

                <button
                    type="button"
                    data-technicians-next-page
                    class="rounded-lg border border-slate-200 px-3 py-1.5
                           text-sm font-medium text-slate-700 hover:bg-slate-50
                           disabled:cursor-not-allowed disabled:opacity-50">
                    Next
                </button>
            </div>
        </div>

    </div>

</div>

<div data-add-technician-modal style="display: none;" class="fixed inset-0 z-50 items-center justify-center bg-slate-950/60 p-4">
    <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl">
        <h2 class="text-lg font-semibold text-slate-900">Add Technician</h2>
        <p class="mt-1 text-sm text-slate-500">
            New technicians start Inactive. Add specializations and activate them from the profile page.
        </p>

        <form data-add-technician-form class="mt-4 space-y-3">
            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Employee code</label>
                <input type="text" name="employee_code" required maxlength="50"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Full name</label>
                <input type="text" name="full_name" required maxlength="150"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Phone number</label>
                <input type="text" name="phone_number" required placeholder="+9715XXXXXXXX"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Email (optional)</label>
                <input type="email" name="email" maxlength="254"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="is_phone_visible_to_customer" class="h-4 w-4 rounded border-slate-300">
                Show phone number to the customer
            </label>
            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Internal note (optional)</label>
                <textarea name="internal_note" rows="2" maxlength="1000"
                          class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
            </div>

            <p data-add-technician-error class="hidden text-sm text-red-600"></p>

            <div class="mt-2 flex justify-end gap-2">
                <button type="button" data-add-technician-cancel class="rounded-xl px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" data-add-technician-submit class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Create technician</button>
            </div>
        </form>
    </div>
</div>

@endsection
