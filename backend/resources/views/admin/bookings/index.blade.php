@extends('admin.layouts.app')

@section('title', 'Bookings — BLUE Admin')
@section('page-title', 'Bookings')

@section('content')

<div data-bookings-page class="space-y-6">

    {{-- ============================================================
        PAGE HEADER
    ============================================================ --}}
    <section
        class="relative overflow-hidden rounded-3xl border border-slate-200
               bg-white px-6 py-6 shadow-sm lg:px-8 lg:py-7">

        <div
            class="pointer-events-none absolute -right-20 -top-28 h-72 w-72
                   rounded-full bg-blue-50 blur-3xl">
        </div>

        <div
            class="pointer-events-none absolute -bottom-28 left-1/3 h-56 w-56
                   rounded-full bg-indigo-50 blur-3xl">
        </div>

        <div class="relative flex flex-col gap-6 xl:flex-row xl:items-center xl:justify-between">

            <div class="flex items-start gap-4">

                <div
                    class="hidden h-12 w-12 shrink-0 items-center justify-center
                           rounded-2xl bg-slate-950 text-white shadow-sm sm:flex">

                    <svg viewBox="0 0 24 24" fill="none" class="h-6 w-6">
                        <path
                            d="M7 3.75h10A2.25 2.25 0 0 1 19.25 6v12A2.25 2.25 0 0 1 17 20.25H7A2.25 2.25 0 0 1 4.75 18V6A2.25 2.25 0 0 1 7 3.75Z"
                            stroke="currentColor"
                            stroke-width="1.6"
                        />
                        <path
                            d="M8 8h8M8 12h8M8 16h5"
                            stroke="currentColor"
                            stroke-width="1.6"
                            stroke-linecap="round"
                        />
                    </svg>
                </div>

                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-2xl font-bold tracking-tight text-slate-950">
                            Booking Operations
                        </h1>

                        <span
                            class="inline-flex items-center gap-1.5 rounded-full
                                   border border-blue-100 bg-blue-50 px-2.5 py-1
                                   text-[11px] font-semibold text-blue-700">
                            <span class="h-1.5 w-1.5 rounded-full bg-blue-500"></span>
                            Live operations
                        </span>
                    </div>

                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">
                        Monitor every booking, track assignment and service progress,
                        inspect payment state, and open the full booking workspace.
                    </p>

                    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-400">
                        <span>
                            Last refreshed:
                            <span data-bookings-last-refreshed class="font-medium text-slate-600">—</span>
                        </span>

                        <span class="hidden h-1 w-1 rounded-full bg-slate-300 sm:inline-block"></span>

                        <span>
                            Results:
                            <span data-bookings-results-count class="font-semibold text-slate-700">—</span>
                        </span>
                    </div>
                </div>

            </div>

            <div class="flex flex-wrap items-center gap-2">

                <a
                    href="/admin"
                    class="inline-flex items-center gap-2 rounded-xl border border-slate-200
                           bg-white px-4 py-2.5 text-sm font-semibold text-slate-700
                           shadow-sm transition hover:border-slate-300 hover:bg-slate-50">

                    <svg viewBox="0 0 20 20" fill="none" class="h-4 w-4 text-slate-500">
                        <path
                            d="M3 10h14M3 10l4-4M3 10l4 4"
                            stroke="currentColor"
                            stroke-width="1.6"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>

                    Dashboard
                </a>

                <button
                    type="button"
                    data-bookings-refresh
                    class="inline-flex items-center gap-2 rounded-xl bg-slate-950
                           px-4 py-2.5 text-sm font-semibold text-white shadow-sm
                           transition hover:bg-slate-800">

                    <svg viewBox="0 0 20 20" fill="none" class="h-4 w-4">
                        <path
                            d="M16.5 10a6.5 6.5 0 1 1-2.032-4.723M16.5 3v4h-4"
                            stroke="currentColor"
                            stroke-width="1.6"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>

                    Refresh
                </button>

            </div>

        </div>

    </section>


    {{-- ============================================================
        OPERATIONAL SUMMARY
    ============================================================ --}}
    <section>

        <div class="mb-3 flex flex-wrap items-end justify-between gap-3">

            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">
                    Operational overview
                </p>

                <h2 class="mt-1 text-base font-semibold text-slate-900">
                    What needs your attention
                </h2>
            </div>

            <p class="text-xs text-slate-400">
                Live values from BLUE operations
            </p>

        </div>

        <div
            data-summary-cards
            class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        </div>

    </section>


    {{-- ============================================================
        QUICK QUEUES
    ============================================================ --}}
    <section
        class="rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">

        <div
            class="flex gap-1 overflow-x-auto"
            aria-label="Booking quick filters">

            <a
                href="/admin/bookings"
                class="inline-flex shrink-0 items-center gap-2 rounded-xl px-4 py-2.5
                       text-sm font-semibold transition
                       {{ !request()->query('status') && !request()->query('assignment_state')
                            ? 'bg-slate-950 text-white shadow-sm'
                            : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950' }}">

                <svg viewBox="0 0 20 20" fill="none" class="h-4 w-4">
                    <rect x="3" y="3" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/>
                    <rect x="12" y="3" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/>
                    <rect x="3" y="12" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/>
                    <rect x="12" y="12" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/>
                </svg>

                All bookings
            </a>

            <a
                href="/admin/bookings?assignment_state=PENDING"
                class="inline-flex shrink-0 items-center gap-2 rounded-xl px-4 py-2.5
                       text-sm font-semibold transition
                       {{ request()->query('assignment_state') === 'PENDING'
                            ? 'bg-amber-50 text-amber-800 ring-1 ring-inset ring-amber-200'
                            : 'text-slate-600 hover:bg-amber-50 hover:text-amber-800' }}">

                <span class="h-2 w-2 rounded-full bg-amber-500"></span>

                Needs assignment
            </a>

            <a
                href="/admin/bookings?status=IN_PROGRESS"
                class="inline-flex shrink-0 items-center gap-2 rounded-xl px-4 py-2.5
                       text-sm font-semibold transition
                       {{ request()->query('status') === 'IN_PROGRESS'
                            ? 'bg-blue-50 text-blue-800 ring-1 ring-inset ring-blue-200'
                            : 'text-slate-600 hover:bg-blue-50 hover:text-blue-800' }}">

                <span class="h-2 w-2 rounded-full bg-blue-500"></span>

                In progress
            </a>

            <a
                href="/admin/bookings?status=ASSIGNED"
                class="inline-flex shrink-0 items-center gap-2 rounded-xl px-4 py-2.5
                       text-sm font-semibold transition
                       {{ request()->query('status') === 'ASSIGNED'
                            ? 'bg-indigo-50 text-indigo-800 ring-1 ring-inset ring-indigo-200'
                            : 'text-slate-600 hover:bg-indigo-50 hover:text-indigo-800' }}">

                <span class="h-2 w-2 rounded-full bg-indigo-500"></span>

                Assigned
            </a>

            <a
                href="/admin/bookings?status=COMPLETED"
                class="inline-flex shrink-0 items-center gap-2 rounded-xl px-4 py-2.5
                       text-sm font-semibold transition
                       {{ request()->query('status') === 'COMPLETED'
                            ? 'bg-emerald-50 text-emerald-800 ring-1 ring-inset ring-emerald-200'
                            : 'text-slate-600 hover:bg-emerald-50 hover:text-emerald-800' }}">

                <span class="h-2 w-2 rounded-full bg-emerald-500"></span>

                Completed
            </a>

            <a
                href="/admin/bookings?status=CANCELLED"
                class="inline-flex shrink-0 items-center gap-2 rounded-xl px-4 py-2.5
                       text-sm font-semibold transition
                       {{ request()->query('status') === 'CANCELLED'
                            ? 'bg-red-50 text-red-800 ring-1 ring-inset ring-red-200'
                            : 'text-slate-600 hover:bg-red-50 hover:text-red-800' }}">

                <span class="h-2 w-2 rounded-full bg-red-400"></span>

                Cancelled
            </a>

        </div>

    </section>


    {{-- ============================================================
        FILTERS
    ============================================================ --}}
    <section
        class="overflow-hidden rounded-2xl border border-slate-200
               bg-white shadow-sm">

        <div
            class="flex flex-wrap items-center justify-between gap-3
                   border-b border-slate-100 px-5 py-4">

            <div class="flex items-center gap-3">

                <div
                    class="flex h-9 w-9 items-center justify-center
                           rounded-xl bg-slate-100 text-slate-600">

                    <svg viewBox="0 0 20 20" fill="none" class="h-4 w-4">
                        <path
                            d="M3 5h14M5.5 10h9M8 15h4"
                            stroke="currentColor"
                            stroke-width="1.6"
                            stroke-linecap="round"
                        />
                    </svg>
                </div>

                <div>
                    <h2 class="text-sm font-semibold text-slate-900">
                        Search & filters
                    </h2>

                    <p class="mt-0.5 text-xs text-slate-400">
                        Find a booking or narrow the operational queue.
                    </p>
                </div>

            </div>

            <button
                type="button"
                data-advanced-filters-toggle
                class="inline-flex items-center gap-2 rounded-lg px-3 py-2
                       text-sm font-semibold text-blue-600 transition
                       hover:bg-blue-50 hover:text-blue-800">

                <svg viewBox="0 0 20 20" fill="none" class="h-4 w-4">
                    <path
                        d="M4 5h12M4 10h12M4 15h12"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                    />
                    <circle cx="7" cy="5" r="1.5" fill="white" stroke="currentColor" stroke-width="1.4"/>
                    <circle cx="13" cy="10" r="1.5" fill="white" stroke="currentColor" stroke-width="1.4"/>
                    <circle cx="9" cy="15" r="1.5" fill="white" stroke="currentColor" stroke-width="1.4"/>
                </svg>

                Advanced filters
            </button>

        </div>


        <form data-bookings-filter-form class="p-5">

            {{-- Primary filters --}}
            <div class="grid grid-cols-1 gap-4 xl:grid-cols-12">

                <div class="xl:col-span-5">

                    <label
                        class="mb-1.5 block text-xs font-semibold
                               text-slate-600">
                        Booking number
                    </label>

                    <div class="relative">

                        <svg
                            viewBox="0 0 20 20"
                            fill="none"
                            class="pointer-events-none absolute left-3.5 top-1/2
                                   h-4 w-4 -translate-y-1/2 text-slate-400">

                            <circle
                                cx="8.5"
                                cy="8.5"
                                r="5.5"
                                stroke="currentColor"
                                stroke-width="1.6"
                            />

                            <path
                                d="m13 13 4 4"
                                stroke="currentColor"
                                stroke-width="1.6"
                                stroke-linecap="round"
                            />

                        </svg>

                        <input
                            type="text"
                            name="booking_number"
                            autocomplete="off"
                            placeholder="Search booking number, e.g. BLU-78214"
                            class="w-full rounded-xl border border-slate-300 bg-white
                                   py-2.5 pl-10 pr-3 text-sm text-slate-900
                                   outline-none transition placeholder:text-slate-400
                                   hover:border-slate-400
                                   focus:border-blue-500 focus:ring-4
                                   focus:ring-blue-100">

                    </div>

                </div>


                <div class="xl:col-span-3">

                    <label
                        class="mb-1.5 block text-xs font-semibold
                               text-slate-600">
                        Booking status
                    </label>

                    <select
                        name="status"
                        class="w-full rounded-xl border border-slate-300 bg-white
                               px-3 py-2.5 text-sm text-slate-900 outline-none
                               transition hover:border-slate-400
                               focus:border-blue-500 focus:ring-4 focus:ring-blue-100">

                        <option value="">All statuses</option>
                        <option value="PAID">Paid</option>
                        <option value="ASSIGNED">Assigned</option>
                        <option value="IN_PROGRESS">In progress</option>
                        <option value="COMPLETED">Completed</option>
                        <option value="CANCELLED">Cancelled</option>

                    </select>

                </div>


                <div class="xl:col-span-2">

                    <label
                        class="mb-1.5 block text-xs font-semibold
                               text-slate-600">
                        Assignment
                    </label>

                    <select
                        name="assignment_state"
                        class="w-full rounded-xl border border-slate-300 bg-white
                               px-3 py-2.5 text-sm text-slate-900 outline-none
                               transition hover:border-slate-400
                               focus:border-blue-500 focus:ring-4 focus:ring-blue-100">

                        <option value="">Any state</option>
                        <option value="PENDING">Needs assignment</option>
                        <option value="PARTIAL">Partially assigned</option>
                        <option value="FULL">Fully assigned</option>

                    </select>

                </div>


                <div class="xl:col-span-2">

                    <label
                        class="mb-1.5 block text-xs font-semibold
                               text-slate-600">
                        Appointment
                    </label>

                    <input
                        type="date"
                        name="appointment_date"
                        class="w-full rounded-xl border border-slate-300 bg-white
                               px-3 py-2.5 text-sm text-slate-900 outline-none
                               transition hover:border-slate-400
                               focus:border-blue-500 focus:ring-4 focus:ring-blue-100">

                </div>

            </div>


            {{-- Advanced filters --}}
            <div
                data-advanced-filters
                class="mt-5 hidden border-t border-slate-100 pt-5">

                <div class="mb-4">

                    <h3 class="text-sm font-semibold text-slate-900">
                        Advanced lookup
                    </h3>

                    <p class="mt-1 text-xs leading-5 text-slate-400">
                        Exact identifiers are intended for support and technical lookup.
                        We will replace these with searchable Customer, Technician and
                        Service selectors in the next UX step.
                    </p>

                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">

                    <div>

                        <label
                            class="mb-1.5 block text-xs font-semibold
                                   text-slate-600">
                            Customer ID
                        </label>

                        <input
                            type="text"
                            name="customer_uuid"
                            placeholder="Customer UUID"
                            class="w-full rounded-xl border border-slate-300 bg-white
                                   px-3 py-2.5 font-mono text-xs text-slate-900
                                   outline-none transition placeholder:text-slate-400
                                   hover:border-slate-400 focus:border-blue-500
                                   focus:ring-4 focus:ring-blue-100">

                    </div>


                    <div>

                        <label
                            class="mb-1.5 block text-xs font-semibold
                                   text-slate-600">
                            Technician ID
                        </label>

                        <input
                            type="text"
                            name="technician_uuid"
                            placeholder="Technician UUID"
                            class="w-full rounded-xl border border-slate-300 bg-white
                                   px-3 py-2.5 font-mono text-xs text-slate-900
                                   outline-none transition placeholder:text-slate-400
                                   hover:border-slate-400 focus:border-blue-500
                                   focus:ring-4 focus:ring-blue-100">

                    </div>


                    <div>

                        <label
                            class="mb-1.5 block text-xs font-semibold
                                   text-slate-600">
                            Service ID
                        </label>

                        <input
                            type="text"
                            name="service_uuid"
                            placeholder="Service UUID"
                            class="w-full rounded-xl border border-slate-300 bg-white
                                   px-3 py-2.5 font-mono text-xs text-slate-900
                                   outline-none transition placeholder:text-slate-400
                                   hover:border-slate-400 focus:border-blue-500
                                   focus:ring-4 focus:ring-blue-100">

                    </div>


                    <div>

                        <label
                            class="mb-1.5 block text-xs font-semibold
                                   text-slate-600">
                            Created range
                        </label>

                        <div class="grid grid-cols-2 gap-2">

                            <input
                                type="date"
                                name="from"
                                aria-label="Created from"
                                class="w-full rounded-xl border border-slate-300 bg-white
                                       px-2.5 py-2.5 text-xs text-slate-900
                                       outline-none transition hover:border-slate-400
                                       focus:border-blue-500 focus:ring-4
                                       focus:ring-blue-100">

                            <input
                                type="date"
                                name="to"
                                aria-label="Created to"
                                class="w-full rounded-xl border border-slate-300 bg-white
                                       px-2.5 py-2.5 text-xs text-slate-900
                                       outline-none transition hover:border-slate-400
                                       focus:border-blue-500 focus:ring-4
                                       focus:ring-blue-100">

                        </div>

                    </div>

                </div>

            </div>


            {{-- Form actions --}}
            <div
                class="mt-5 flex flex-wrap items-center gap-3
                       border-t border-slate-100 pt-4">

                <button
                    type="submit"
                    class="inline-flex items-center justify-center gap-2
                           rounded-xl bg-blue-600 px-5 py-2.5
                           text-sm font-semibold text-white shadow-sm
                           transition hover:bg-blue-700">

                    <svg viewBox="0 0 20 20" fill="none" class="h-4 w-4">
                        <path
                            d="M4 5h12M6.5 10h7M8.5 15h3"
                            stroke="currentColor"
                            stroke-width="1.6"
                            stroke-linecap="round"
                        />
                    </svg>

                    Apply filters
                </button>

                <button
                    type="button"
                    data-bookings-clear-filters
                    class="inline-flex items-center justify-center gap-2
                           rounded-xl border border-slate-200 bg-white
                           px-4 py-2.5 text-sm font-semibold text-slate-600
                           transition hover:border-slate-300 hover:bg-slate-50
                           hover:text-slate-900">

                    <svg viewBox="0 0 20 20" fill="none" class="h-4 w-4">
                        <path
                            d="M5 5l10 10M15 5 5 15"
                            stroke="currentColor"
                            stroke-width="1.6"
                            stroke-linecap="round"
                        />
                    </svg>

                    Clear filters
                </button>

            </div>

        </form>

    </section>


    {{-- ============================================================
        BOOKING RESULTS
    ============================================================ --}}
    <section
        class="overflow-hidden rounded-2xl border border-slate-200
               bg-white shadow-sm">

        {{-- Results header --}}
        <div
            class="flex flex-wrap items-center justify-between gap-3
                   border-b border-slate-100 px-5 py-4">

            <div>

                <div class="flex items-center gap-2">

                    <h2 class="text-sm font-semibold text-slate-900">
                        Booking queue
                    </h2>

                    <span
                        class="rounded-full bg-slate-100 px-2 py-0.5
                               text-[10px] font-semibold uppercase
                               tracking-wide text-slate-500">
                        Operations
                    </span>

                </div>

                <p data-bookings-active-filter-summary class="mt-1 text-xs text-slate-400">
                    All bookings
                </p>

            </div>

            <div class="hidden items-center gap-4 text-xs text-slate-400 md:flex">

                <div class="flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-amber-400"></span>
                    Needs assignment
                </div>

                <div class="flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-blue-500"></span>
                    Active work
                </div>

                <div class="flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                    Completed
                </div>

            </div>

        </div>


        {{-- Loading --}}
        <div
            data-bookings-loading
            class="px-6 py-16 text-center">

            <div
                class="mx-auto flex h-11 w-11 items-center justify-center
                       rounded-2xl bg-slate-100">

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    class="h-5 w-5 animate-spin text-slate-500">

                    <path
                        d="M12 3a9 9 0 1 1-8.314 5.55"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                    />

                </svg>

            </div>

            <p class="mt-3 text-sm font-medium text-slate-700">
                Loading bookings
            </p>

            <p class="mt-1 text-xs text-slate-400">
                Retrieving the latest operational data...
            </p>

        </div>


        {{-- Error --}}
        <div
            data-bookings-error
            class="hidden px-6 py-14 text-center">

            <div class="mx-auto max-w-md rounded-2xl border border-red-100 bg-red-50 px-5 py-4">
                <p data-bookings-error-message class="text-sm font-medium text-red-700"></p>

                <button
                    type="button"
                    data-bookings-error-retry
                    class="mt-3 rounded-lg bg-white px-3 py-2 text-xs font-semibold
                           text-red-700 shadow-sm ring-1 ring-inset ring-red-200
                           transition hover:bg-red-100">
                    Try again
                </button>
            </div>

        </div>


        {{-- Empty --}}
        <div
            data-bookings-empty
            class="hidden px-6 py-16 text-center">

            <div
                class="mx-auto flex h-12 w-12 items-center justify-center
                       rounded-2xl bg-slate-100 text-slate-500">

                <svg viewBox="0 0 24 24" fill="none" class="h-6 w-6">
                    <path
                        d="M5 4.75h14v14.5H5V4.75Z"
                        stroke="currentColor"
                        stroke-width="1.5"
                    />
                    <path
                        d="M8 9h8M8 13h5"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                    />
                </svg>

            </div>

            <h3 class="mt-4 text-sm font-semibold text-slate-900">
                No bookings found
            </h3>

            <p class="mt-1 text-sm text-slate-500">
                No bookings match the filters you selected.
            </p>

            <button
                type="button"
                data-bookings-empty-clear
                class="mt-4 rounded-xl border border-slate-200 bg-white
                       px-4 py-2 text-xs font-semibold text-slate-700 shadow-sm
                       transition hover:bg-slate-50">
                Clear filters
            </button>

        </div>


        {{-- Desktop table + mobile cards --}}
        <div
            data-bookings-table-wrapper
            class="hidden overflow-x-auto">

            <table
                class="hidden w-full min-w-[1120px] text-left text-sm lg:table">

                <thead class="bg-slate-50/80">

                    <tr
                        class="border-b border-slate-200 text-[11px]
                               font-semibold uppercase tracking-[0.08em]
                               text-slate-500">

                        <th class="px-5 py-3.5">
                            Booking
                        </th>

                        <th class="px-5 py-3.5">
                            Customer
                        </th>

                        <th class="px-5 py-3.5">
                            Services
                        </th>

                        <th class="px-5 py-3.5">
                            Appointment
                        </th>

                        <th class="px-5 py-3.5">
                            Payment
                        </th>

                        <th class="px-5 py-3.5">
                            Assignment
                        </th>

                        <th class="px-5 py-3.5">
                            Booking status
                        </th>

                        <th class="px-5 py-3.5">
                            Operational state
                        </th>

                        <th class="px-5 py-3.5">
                            Created
                        </th>

                        <th class="w-12 px-5 py-3.5"></th>

                    </tr>

                </thead>

                <tbody
                    data-bookings-body
                    class="divide-y divide-slate-100">
                </tbody>

            </table>


            <div
                data-bookings-cards
                class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:hidden">
            </div>

        </div>


        {{-- Pagination --}}
        <div
            data-bookings-pagination
            style="display: none;"
            class="items-center justify-between gap-4 border-t
                   border-slate-100 bg-slate-50/50 px-5 py-4
                   text-sm text-slate-600">

            <span
                data-bookings-pagination-summary
                class="text-xs font-medium text-slate-500">
            </span>

            <div class="flex gap-2">

                <button
                    type="button"
                    data-bookings-prev-page
                    class="inline-flex items-center gap-1.5 rounded-lg
                           border border-slate-200 bg-white px-3.5 py-2
                           text-xs font-semibold text-slate-700 shadow-sm
                           transition hover:border-slate-300 hover:bg-slate-50
                           disabled:cursor-not-allowed disabled:opacity-40">

                    <svg viewBox="0 0 20 20" fill="none" class="h-3.5 w-3.5">
                        <path
                            d="m12 5-5 5 5 5"
                            stroke="currentColor"
                            stroke-width="1.6"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>

                    Previous
                </button>

                <button
                    type="button"
                    data-bookings-next-page
                    class="inline-flex items-center gap-1.5 rounded-lg
                           border border-slate-200 bg-white px-3.5 py-2
                           text-xs font-semibold text-slate-700 shadow-sm
                           transition hover:border-slate-300 hover:bg-slate-50
                           disabled:cursor-not-allowed disabled:opacity-40">

                    Next

                    <svg viewBox="0 0 20 20" fill="none" class="h-3.5 w-3.5">
                        <path
                            d="m8 5 5 5-5 5"
                            stroke="currentColor"
                            stroke-width="1.6"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>

                </button>

            </div>

        </div>

    </section>

</div>


{{-- ================================================================
    SUMMARY CARD TEMPLATE
================================================================ --}}
<template data-summary-card-template>

    <div
        class="group relative overflow-hidden rounded-2xl border
               border-slate-200 bg-white p-5 shadow-sm transition
               duration-200 hover:-translate-y-0.5 hover:border-slate-300
               hover:shadow-md">

        <div
            class="absolute right-0 top-0 h-20 w-20 translate-x-7
                   -translate-y-7 rounded-full bg-blue-50
                   transition group-hover:scale-125">
        </div>

        <div class="relative">

            <div class="flex items-center justify-between gap-4">

                <p
                    data-field="title"
                    class="text-[11px] font-semibold uppercase
                           tracking-[0.12em] text-slate-500">
                </p>

                <div
                    class="flex h-8 w-8 items-center justify-center
                           rounded-xl border border-slate-100 bg-slate-50
                           text-slate-500">

                    <svg viewBox="0 0 20 20" fill="none" class="h-4 w-4">
                        <path
                            d="M4 14.5V10M8 14.5V6M12 14.5V8M16 14.5V3.5"
                            stroke="currentColor"
                            stroke-width="1.5"
                            stroke-linecap="round"
                        />
                    </svg>

                </div>

            </div>

            <p
                data-field="value"
                class="mt-4 text-3xl font-bold tracking-tight
                       text-slate-950">
            </p>

            <div
                class="mt-4 h-1 w-8 rounded-full bg-blue-600
                       transition-all duration-200 group-hover:w-12">
            </div>

        </div>

    </div>

</template>


{{-- ================================================================
    DESKTOP BOOKING ROW TEMPLATE
================================================================ --}}
<template data-booking-row-template>

    <tr
        class="group cursor-pointer align-middle
               transition-colors hover:bg-blue-50/30">

        {{-- Booking --}}
        <td class="px-5 py-4.5">

            <div class="flex items-start gap-3">

                <div
                    class="mt-0.5 flex h-9 w-9 shrink-0 items-center
                           justify-center rounded-xl border border-slate-200
                           bg-slate-50 text-slate-500 transition
                           group-hover:border-blue-100 group-hover:bg-blue-50
                           group-hover:text-blue-600">

                    <svg viewBox="0 0 20 20" fill="none" class="h-4 w-4">
                        <path
                            d="M5 3.75h10A1.25 1.25 0 0 1 16.25 5v10A1.25 1.25 0 0 1 15 16.25H5A1.25 1.25 0 0 1 3.75 15V5A1.25 1.25 0 0 1 5 3.75Z"
                            stroke="currentColor"
                            stroke-width="1.5"
                        />
                        <path
                            d="M7 7h6M7 10h6M7 13h3.5"
                            stroke="currentColor"
                            stroke-width="1.5"
                            stroke-linecap="round"
                        />
                    </svg>

                </div>

                <div class="min-w-0">

                    <a
                        data-row-link
                        class="font-semibold text-slate-950 transition
                               group-hover:text-blue-700">
                    </a>

                    <p
                        data-field="source"
                        class="mt-0.5 text-xs text-slate-400">
                    </p>

                </div>

            </div>

        </td>


        {{-- Customer --}}
        <td class="px-5 py-4.5">

            <a
                data-customer-link
                class="block max-w-[180px] truncate font-medium
                       text-slate-900 transition hover:text-blue-700">
            </a>

            <p
                data-field="customer_phone"
                class="mt-1 text-xs text-slate-400">
            </p>

        </td>


        {{-- Services --}}
        <td class="px-5 py-4.5">

            <p
                data-field="services"
                class="max-w-[180px] font-medium leading-5 text-slate-700">
            </p>

            <p
                data-field="items_count"
                class="mt-1 text-xs text-slate-400">
            </p>

        </td>


        {{-- Appointment --}}
        <td class="px-5 py-4.5">

            <div class="flex items-start gap-2">

                <svg
                    viewBox="0 0 20 20"
                    fill="none"
                    class="mt-0.5 h-4 w-4 shrink-0 text-slate-400">

                    <rect
                        x="3.5"
                        y="5"
                        width="13"
                        height="11.5"
                        rx="2"
                        stroke="currentColor"
                        stroke-width="1.4"
                    />

                    <path
                        d="M6.5 3.5V6.5M13.5 3.5V6.5M3.5 8.5H16.5"
                        stroke="currentColor"
                        stroke-width="1.4"
                        stroke-linecap="round"
                    />

                </svg>

                <div>

                    <p
                        data-field="appointment_date"
                        class="font-medium text-slate-700">
                    </p>

                    <p
                        data-field="appointment_time"
                        class="mt-0.5 text-xs font-medium text-slate-500">
                    </p>

                    <p
                        data-field="appointment_window"
                        class="mt-0.5 text-[10px] text-slate-400">
                    </p>

                </div>

            </div>

        </td>


        {{-- Payment --}}
        <td class="px-5 py-4.5">

            <span
                data-field="payment"
                data-status-badge
                class="inline-flex rounded-full px-2.5 py-1
                       text-xs font-semibold">
            </span>

            <p
                data-field="total"
                class="mt-1.5 text-xs font-semibold text-slate-700">
            </p>

        </td>


        {{-- Assignment --}}
        <td class="px-5 py-4.5">

            <span
                data-field="assignment"
                data-assignment-badge
                class="inline-flex rounded-full px-2.5 py-1
                       text-xs font-semibold">
            </span>

        </td>


        {{-- Status --}}
        <td class="px-5 py-4.5">

            <span
                data-field="status"
                data-status-badge
                class="inline-flex rounded-full px-2.5 py-1
                       text-xs font-semibold">
            </span>

        </td>


        {{-- Operational state --}}
        <td class="px-5 py-4.5">

            <span
                data-field="attention"
                class="inline-flex items-center gap-1.5 rounded-full
                       px-2.5 py-1 text-xs font-semibold">
            </span>

        </td>


        {{-- Created --}}
        <td class="px-5 py-4.5">

            <p
                data-field="created_at"
                class="whitespace-nowrap text-xs text-slate-500">
            </p>

        </td>


        {{-- Open --}}
        <td class="px-5 py-4.5 text-right">

            <a
                data-row-link
                aria-label="Open booking"
                class="inline-flex h-8 w-8 items-center justify-center
                       rounded-lg text-slate-400 transition
                       group-hover:bg-white group-hover:text-blue-600
                       hover:shadow-sm">

                <svg viewBox="0 0 20 20" fill="none" class="h-4 w-4">
                    <path
                        d="m7.5 5 5 5-5 5"
                        stroke="currentColor"
                        stroke-width="1.7"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>

            </a>

        </td>

    </tr>

</template>


{{-- ================================================================
    MOBILE BOOKING CARD TEMPLATE
================================================================ --}}
<template data-booking-card-template>

    <article
        class="group rounded-2xl border border-slate-200
               bg-white p-4 shadow-sm transition
               hover:border-slate-300 hover:shadow-md">

        <div class="flex items-start justify-between gap-3">

            <div class="min-w-0">

                <div class="flex items-center gap-2">

                    <div
                        class="flex h-8 w-8 shrink-0 items-center
                               justify-center rounded-lg bg-slate-950
                               text-white">

                        <svg viewBox="0 0 20 20" fill="none" class="h-4 w-4">
                            <path
                                d="M5 4h10v12H5V4Z"
                                stroke="currentColor"
                                stroke-width="1.4"
                            />
                            <path
                                d="M7.5 8h5M7.5 11h5"
                                stroke="currentColor"
                                stroke-width="1.4"
                                stroke-linecap="round"
                            />
                        </svg>

                    </div>

                    <div class="min-w-0">

                        <a
                            data-row-link
                            class="block truncate font-semibold text-slate-950
                                   transition hover:text-blue-700">
                        </a>

                        <p
                            data-field="customer_name"
                            class="mt-0.5 truncate text-xs text-slate-500">
                        </p>

                    </div>

                </div>

            </div>

            <span
                data-field="status"
                data-status-badge
                class="shrink-0 rounded-full px-2.5 py-1
                       text-[11px] font-semibold">
            </span>

        </div>


        <div
            data-field="attention_box"
            class="mt-4 rounded-xl border border-slate-100
                   bg-slate-50/70 px-3.5 py-3">

            <div class="flex items-center justify-between gap-3">
                <span class="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">
                    Operational state
                </span>

                <span
                    data-field="attention"
                    class="inline-flex items-center gap-1.5 rounded-full
                           px-2.5 py-1 text-[10px] font-semibold">
                </span>
            </div>

        </div>


        <div
            class="mt-3 rounded-xl border border-slate-100
                   bg-slate-50/70 px-3.5 py-3">

            <dl class="space-y-2.5 text-sm">

                <div class="flex items-start justify-between gap-4">

                    <dt class="text-xs text-slate-400">
                        Services
                    </dt>

                    <dd class="max-w-[65%] text-right">
                        <p
                            data-field="services"
                            class="text-xs font-semibold text-slate-800">
                        </p>

                        <p
                            data-field="items_count"
                            class="mt-0.5 text-[10px] text-slate-400">
                        </p>
                    </dd>

                </div>


                <div class="flex items-start justify-between gap-4">

                    <dt class="text-xs text-slate-400">
                        Appointment
                    </dt>

                    <dd class="text-right">
                        <p
                            data-field="appointment_date"
                            class="text-xs font-semibold text-slate-800">
                        </p>

                        <p
                            data-field="appointment_time"
                            class="mt-0.5 text-[10px] text-slate-500">
                        </p>
                    </dd>

                </div>


                <div class="flex items-center justify-between gap-4">

                    <dt class="text-xs text-slate-400">
                        Payment
                    </dt>

                    <dd>
                        <span
                            data-field="payment"
                            data-status-badge
                            class="rounded-full px-2 py-0.5
                                   text-[10px] font-semibold">
                        </span>

                        <p
                            data-field="total"
                            class="mt-1 text-[10px] font-semibold text-slate-600">
                        </p>
                    </dd>

                </div>


                <div class="flex items-center justify-between gap-4">

                    <dt class="text-xs text-slate-400">
                        Assignment
                    </dt>

                    <dd>
                        <span
                            data-field="assignment"
                            data-assignment-badge
                            class="rounded-full px-2 py-0.5
                                   text-[10px] font-semibold">
                        </span>
                    </dd>

                </div>

            </dl>

        </div>


        <a
            data-row-link
            class="mt-4 inline-flex w-full items-center
                   justify-between rounded-xl bg-slate-950
                   px-4 py-2.5 text-xs font-semibold text-white
                   transition hover:bg-slate-800">

            <span>Open booking workspace</span>

            <svg viewBox="0 0 20 20" fill="none" class="h-4 w-4">
                <path
                    d="m7.5 5 5 5-5 5"
                    stroke="currentColor"
                    stroke-width="1.6"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </svg>

        </a>

    </article>

</template>

@endsection