@extends('admin.layouts.app')

@section('title', 'Appointment Schedule — BLUE Admin')
@section('page-title', 'Appointment Schedule')

@section('content')

<div data-schedule-page class="space-y-6">

    <div>
        <h2 class="text-lg font-semibold text-slate-950">Appointment Schedule</h2>
        <p class="mt-1 text-sm text-slate-500">Manage customer booking availability, capacities, and working periods.</p>
    </div>

    <div data-schedule-error class="hidden rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"></div>

    <div class="flex gap-2 border-b border-slate-200">
        <button type="button" data-tab-button="schedule" class="border-b-2 border-slate-950 px-3 py-2.5 text-sm font-medium text-slate-950">Schedule</button>
        <button type="button" data-tab-button="time-windows" class="border-b-2 border-transparent px-3 py-2.5 text-sm font-medium text-slate-500 hover:text-slate-900">Time Windows</button>
    </div>

    {{-- ============================= Schedule tab ============================= --}}
    <div data-tab-panel="schedule" class="space-y-6">

        <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4">
            <button type="button" data-day-prev class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">&larr; Previous Day</button>
            <input type="date" data-day-input class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            <button type="button" data-day-today class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Today</button>
            <button type="button" data-day-next class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Next Day &rarr;</button>
            <span class="mx-1 h-5 w-px bg-slate-200"></span>
            <button type="button" data-open-generate-modal class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Generate Schedule</button>
        </div>

        <div data-schedule-loading class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500">Loading schedule...</div>
        <div data-schedule-empty style="display: none;" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500">
            No appointment slots exist for this date yet. Use "Generate Schedule" to create them from the active Time Window templates.
        </div>

        <div data-schedule-grid style="display: none;" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"></div>

    </div>

    {{-- ============================= Time Windows tab ============================= --}}
    <div data-tab-panel="time-windows" style="display: none;" class="space-y-4">

        <div class="flex justify-end">
            <button type="button" data-open-window-modal class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Add Time Window</button>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white overflow-x-auto">
            <table class="w-full min-w-[700px] text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs font-medium uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Window</th>
                        <th class="px-5 py-3">Code</th>
                        <th class="px-5 py-3">Order</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody data-windows-table-body class="divide-y divide-slate-100"></tbody>
            </table>
        </div>

    </div>

</div>

{{-- ============================= Generate Schedule modal ============================= --}}
<div data-generate-modal style="display: none;" class="fixed inset-0 z-50 items-center justify-center bg-slate-950/60 p-4">
    <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl">
        <h2 class="text-lg font-semibold text-slate-950">Generate Appointment Schedule</h2>
        <p class="mt-1 text-sm text-slate-500">Creates one dated slot per active Time Window template for every day in the range. Existing slots are never overwritten.</p>

        <div data-generate-error class="mt-4 hidden rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"></div>
        <div data-generate-result style="display: none;" class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"></div>

        <form data-generate-form class="mt-4 space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">From Date</label>
                    <input type="date" name="from" required class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">To Date</label>
                    <input type="date" name="to" required class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                </div>
            </div>
            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Default Capacity</label>
                <input type="number" name="booking_capacity" min="1" max="10000" value="3" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>
            <div class="flex justify-end gap-3 pt-1">
                <button type="button" data-generate-cancel class="rounded-xl px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Close</button>
                <button type="submit" data-generate-submit class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60">Generate</button>
            </div>
        </form>
    </div>
</div>

{{-- ============================= Time Window create/edit modal ============================= --}}
<div data-window-modal style="display: none;" class="fixed inset-0 z-50 items-center justify-center bg-slate-950/60 p-4">
    <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl">
        <h2 data-window-modal-title class="text-lg font-semibold text-slate-950">Add Time Window</h2>

        <div data-window-error class="mt-4 hidden rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"></div>

        <form data-window-form class="mt-4 space-y-4">
            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Name</label>
                <input type="text" name="name" required minlength="2" maxlength="120" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>
            <div data-window-code-field>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Code</label>
                <input type="text" name="code" minlength="2" maxlength="60" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Start Time</label>
                    <input type="time" name="start_time" required class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">End Time</label>
                    <input type="time" name="end_time" required class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                </div>
            </div>
            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Display Order</label>
                <input type="number" name="display_order" min="0" max="65535" value="0" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>
            <div class="flex justify-end gap-3 pt-1">
                <button type="button" data-window-cancel class="rounded-xl px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" data-window-submit class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60">Save</button>
            </div>
        </form>
    </div>
</div>

{{-- ============================= Slot detail modal ============================= --}}
<div data-slot-modal style="display: none;" class="fixed inset-0 z-50 items-center justify-center bg-slate-950/60 p-4">
    <div class="w-full max-w-xl rounded-2xl bg-white p-6 shadow-xl max-h-[85vh] overflow-y-auto">
        <h2 data-slot-modal-title class="text-lg font-semibold text-slate-950"></h2>
        <p data-slot-modal-status class="mt-1 text-sm text-slate-500"></p>

        <div data-slot-error class="mt-4 hidden rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"></div>
        <div data-slot-warning style="display: none;" class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"></div>

        <form data-slot-capacity-form class="mt-4 grid grid-cols-2 gap-4 items-end rounded-xl border border-slate-200 bg-slate-50 p-4">
            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Capacity</label>
                <input type="number" name="booking_capacity" min="1" max="10000" required class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>
            <div>
                <button type="submit" data-slot-capacity-submit class="w-full rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60">Update Capacity</button>
            </div>
            <div class="col-span-2">
                <label class="mb-1.5 block text-xs font-medium text-slate-600">Internal Note</label>
                <input type="text" name="internal_note" maxlength="500" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
            </div>
        </form>

        <div class="mt-4 grid grid-cols-3 gap-3 text-center">
            <div class="rounded-xl border border-slate-200 bg-white p-3">
                <p class="text-xs text-slate-500">Capacity</p>
                <p data-slot-field="booking_capacity" class="mt-1 text-lg font-semibold text-slate-950"></p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-3">
                <p class="text-xs text-slate-500">Booked/Held</p>
                <p data-slot-field="occupied_capacity" class="mt-1 text-lg font-semibold text-slate-950"></p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-3">
                <p class="text-xs text-slate-500">Remaining</p>
                <p data-slot-field="remaining_capacity" class="mt-1 text-lg font-semibold text-slate-950"></p>
            </div>
        </div>

        <div class="mt-4 flex justify-end gap-3">
            <button type="button" data-slot-close-toggle class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Close Slot</button>
        </div>

        <div class="mt-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Active Holds (<span data-slot-active-hold-count>0</span>)</p>
            <div data-slot-holds-empty class="mt-2 text-sm text-slate-500">No active holds.</div>
            <ul data-slot-holds-list class="mt-2 space-y-1 text-sm text-slate-700"></ul>
        </div>

        <div class="mt-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Bookings in this slot</p>
            <div data-slot-bookings-empty class="mt-2 text-sm text-slate-500">No Bookings in this slot.</div>
            <ul data-slot-bookings-list class="mt-2 space-y-1.5 text-sm"></ul>
        </div>

        <div class="mt-5 flex justify-end">
            <button type="button" data-slot-close class="rounded-xl px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Close</button>
        </div>
    </div>
</div>

@endsection
