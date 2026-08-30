@extends('admin.layouts.app')

@section('title', 'Technician — BLUE Admin')
@section('page-title', 'Technician Profile')

@section('content')

<div data-technician-page data-technician-uuid="{{ $technicianUuid }}" class="space-y-6">

    <a href="/admin/technicians" class="text-sm font-medium text-slate-500 hover:text-slate-800">&larr; Back to Technicians</a>

    <div data-technician-loading class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500">
        Loading technician...
    </div>

    <div data-technician-error class="hidden rounded-2xl border border-red-200 bg-red-50 p-6 text-sm text-red-700"></div>

    <div data-technician-content class="hidden space-y-6">

        <!-- Overview -->
        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3">
                        <h2 data-field-full_name class="text-xl font-semibold text-slate-900"></h2>
                        <span data-status-badge class="rounded-full px-2.5 py-1 text-xs font-semibold"></span>
                        <span data-assignable-badge class="text-xs text-slate-400"></span>
                    </div>
                    <p class="mt-1 text-sm text-slate-500">Employee code: <span data-field-employee_code></span></p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="button" data-open-edit class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Edit Technician</button>
                    <select data-status-select class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700">
                        <option value="AVAILABLE">Available</option>
                        <option value="BUSY">Busy</option>
                        <option value="ON_LEAVE">On leave</option>
                        <option value="INACTIVE">Inactive (Archive)</option>
                    </select>
                    <button type="button" data-apply-status class="rounded-lg bg-slate-950 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800">Set status</button>
                </div>
            </div>

            <p data-status-error class="mt-3 hidden text-sm text-red-600"></p>
            <p class="mt-2 text-xs text-slate-400">
                Deactivating (Archive) keeps all historical bookings, assignments, and ratings available - it only stops new assignments.
            </p>

            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-slate-400">Phone</div>
                    <div data-field-phone_number class="mt-1 text-sm text-slate-800"></div>
                </div>
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-slate-400">Email</div>
                    <div data-field-email class="mt-1 text-sm text-slate-800"></div>
                </div>
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-slate-400">Phone visible to customer</div>
                    <div data-field-visible class="mt-1 text-sm text-slate-800"></div>
                </div>
            </div>

            <div class="mt-4">
                <div class="text-xs font-medium uppercase tracking-wide text-slate-400">Internal note</div>
                <div data-field-internal_note class="mt-1 text-sm text-slate-600"></div>
            </div>
        </div>

        <!-- Performance -->
        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Performance</h3>
            <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-5">
                <div class="rounded-xl bg-slate-50 p-4">
                    <div class="text-xs text-slate-500">Average rating</div>
                    <div data-perf-rating class="mt-1 text-lg font-semibold text-slate-900"></div>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <div class="text-xs text-slate-500">Ratings</div>
                    <div data-perf-rating_count class="mt-1 text-lg font-semibold text-slate-900"></div>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <div class="text-xs text-slate-500">Completed jobs</div>
                    <div data-perf-completed_jobs class="mt-1 text-lg font-semibold text-slate-900"></div>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <div class="text-xs text-slate-500">Active jobs</div>
                    <div data-perf-active_jobs class="mt-1 text-lg font-semibold text-slate-900"></div>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <div class="text-xs text-slate-500">In progress</div>
                    <div data-perf-in_progress_jobs class="mt-1 text-lg font-semibold text-slate-900"></div>
                </div>
            </div>
        </div>

        <!-- Specializations -->
        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Specializations</h3>

            <div data-specializations-list class="mt-4 flex flex-wrap gap-2"></div>

            <form data-add-specialization-form class="mt-4 flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Specialization</label>
                    <select name="specialization_id" required data-specialization-select class="rounded-lg border border-slate-300 px-3 py-2 text-sm"></select>
                </div>
                <label class="flex items-center gap-2 pb-2 text-sm text-slate-700">
                    <input type="checkbox" name="is_primary" class="h-4 w-4 rounded border-slate-300">
                    Primary
                </label>
                <button type="submit" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Add / Update</button>
            </form>
            <p data-specialization-error class="mt-2 hidden text-sm text-red-600"></p>
        </div>

        <!-- Current Work -->
        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Current Work</h3>
            <div data-current-work-empty class="mt-3 text-sm text-slate-500">No active assignments.</div>
            <div data-current-work-wrapper class="mt-3 hidden overflow-x-auto">
                <table class="w-full min-w-[700px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-xs font-medium uppercase tracking-wide text-slate-500">
                            <th class="px-3 py-2">Booking</th>
                            <th class="px-3 py-2">Service</th>
                            <th class="px-3 py-2">Item status</th>
                            <th class="px-3 py-2">Appointment</th>
                        </tr>
                    </thead>
                    <tbody data-current-work-body class="divide-y divide-slate-100"></tbody>
                </table>
            </div>
        </div>

        <!-- Job history -->
        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Booking / Job History</h3>
            <div data-jobs-empty class="mt-3 hidden text-sm text-slate-500">No assignment history yet.</div>
            <div data-jobs-wrapper class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[900px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-xs font-medium uppercase tracking-wide text-slate-500">
                            <th class="px-3 py-2">Booking</th>
                            <th class="px-3 py-2">Service</th>
                            <th class="px-3 py-2">Item status</th>
                            <th class="px-3 py-2">Assigned</th>
                            <th class="px-3 py-2">Released</th>
                            <th class="px-3 py-2">Release reason</th>
                        </tr>
                    </thead>
                    <tbody data-jobs-body class="divide-y divide-slate-100"></tbody>
                </table>
            </div>
            <div data-jobs-pagination class="mt-3 flex items-center justify-between text-sm text-slate-600">
                <span data-jobs-pagination-summary></span>
                <div class="flex gap-2">
                    <button type="button" data-jobs-prev class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50">Previous</button>
                    <button type="button" data-jobs-next class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50">Next</button>
                </div>
            </div>
        </div>

        <!-- Ratings -->
        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Ratings</h3>
            <p class="mt-1 text-xs text-slate-400">
                Ratings are submitted per Booking, not per Technician. A rating counts toward this Technician's average only when they were the sole Technician assigned to that Booking.
            </p>
            <div data-ratings-empty class="mt-3 hidden text-sm text-slate-500">No ratings yet.</div>
            <div data-ratings-list class="mt-3 space-y-3"></div>
            <div data-ratings-pagination class="mt-3 flex items-center justify-between text-sm text-slate-600">
                <span data-ratings-pagination-summary></span>
                <div class="flex gap-2">
                    <button type="button" data-ratings-prev class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50">Previous</button>
                    <button type="button" data-ratings-next class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50">Next</button>
                </div>
            </div>
        </div>

    </div>
</div>

<div data-edit-technician-modal style="display: none;" class="fixed inset-0 z-50 items-center justify-center bg-slate-950/60 p-4">
    <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl">
        <h2 class="text-lg font-semibold text-slate-900">Edit Technician</h2>

        <form data-edit-technician-form class="mt-4 space-y-3">
            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Full name</label>
                <input type="text" name="full_name" required maxlength="150" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Phone number</label>
                <input type="text" name="phone_number" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Email</label>
                <input type="email" name="email" maxlength="254" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="is_phone_visible_to_customer" class="h-4 w-4 rounded border-slate-300">
                Show phone number to the customer
            </label>
            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Internal note</label>
                <textarea name="internal_note" rows="2" maxlength="1000" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
            </div>

            <p data-edit-technician-error class="hidden text-sm text-red-600"></p>

            <div class="mt-2 flex justify-end gap-2">
                <button type="button" data-edit-technician-cancel class="rounded-xl px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" data-edit-technician-submit class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Save changes</button>
            </div>
        </form>
    </div>
</div>

@endsection
