@extends('admin.layouts.app')

@section('title', 'Dashboard — BLUE Admin')
@section('page-title', 'Dashboard')

@section('content')

<div data-dashboard-page class="space-y-6">

    <div>
        <h2 class="text-lg font-semibold text-slate-950">Dashboard</h2>
        <p class="mt-1 text-sm text-slate-500">Operational overview of BLUE</p>
    </div>

    <div data-dashboard-loading class="rounded-2xl border border-slate-200 bg-white p-10
                text-center text-sm text-slate-500">
        Loading dashboard...
    </div>

    <div data-dashboard-error class="hidden rounded-2xl border border-red-200 bg-red-50
                p-10 text-center text-sm text-red-700"></div>

    <div data-dashboard-content style="display: none;" class="flex flex-col gap-6">

        <div data-summary-cards class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"></div>

        <div data-financial-snapshot class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-900">Financial snapshot (last 24h)</h3>
                <a href="/admin/finance" class="text-xs font-semibold text-blue-600 hover:text-blue-800">View Financial Dashboard →</a>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Gross Revenue</p>
                    <p data-field="gross_revenue" class="mt-1 text-lg font-semibold text-slate-950"></p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Net Revenue</p>
                    <p data-field="net_revenue" class="mt-1 text-lg font-semibold text-slate-950"></p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Refunds</p>
                    <p data-field="refunds" class="mt-1 text-lg font-semibold text-slate-950"></p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Pay on Site Pending</p>
                    <p data-field="pay_on_site_pending" class="mt-1 text-lg font-semibold text-slate-950"></p>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-6 py-4">
                <h3 class="text-sm font-semibold text-slate-900">Needs attention</h3>
            </div>
            <div data-attention-empty class="hidden px-6 py-6 text-sm text-slate-500">
                Nothing needs Admin attention right now.
            </div>
            <div data-attention-groups class="divide-y divide-slate-100"></div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-6 py-4">
                <h3 class="text-sm font-semibold text-slate-900">Recent activity</h3>
            </div>
            <div data-activity-empty class="hidden px-6 py-6 text-sm text-slate-500">
                No recorded Admin activity yet.
            </div>
            <div data-activity-list class="divide-y divide-slate-100"></div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Quick access</h3>
            <div class="mt-3 flex flex-wrap gap-2">
                <a href="/admin/bookings" class="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Bookings</a>
                <a href="/admin/technicians" class="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Technicians</a>
                <a href="/admin/contracts" class="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Contracts</a>
                <a href="/admin/finance" class="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Financial Dashboard</a>
                <a href="/admin/finance/ledger" class="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Financial Ledger</a>
                <a href="/admin/payments" class="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Payments</a>
                <a href="/admin/billing" class="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Contract Billing</a>
                <a href="/admin/customers" class="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Customers</a>
                <a href="/admin/support" class="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Support</a>
                <a href="/admin/service-categories" class="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Services</a>
                <a href="/admin/pricing" class="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Pricing</a>
            </div>
        </div>

    </div>

</div>

<template data-summary-card-template>
    <div class="rounded-2xl border border-slate-200 bg-white p-5">
        <p data-field="title" class="text-xs font-medium uppercase tracking-wide text-slate-500"></p>
        <dl class="mt-3 space-y-1.5" data-metrics></dl>
    </div>
</template>

<template data-metric-row-template>
    <div class="flex items-baseline justify-between gap-3">
        <dt data-field="label" class="text-sm text-slate-600"></dt>
        <dd data-field="value" class="text-lg font-semibold text-slate-950"></dd>
    </div>
</template>

<template data-attention-group-template>
    <div class="px-6 py-5">
        <h4 data-field="title" class="text-sm font-semibold text-slate-900"></h4>
        <p data-empty-note class="mt-1 hidden text-xs text-slate-400">None right now.</p>
        <ul data-items class="mt-2 space-y-2"></ul>
    </div>
</template>

<template data-attention-item-template>
    <li>
        <a data-item-link class="flex flex-wrap items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50">
            <span data-field="primary" class="font-medium text-blue-600"></span>
            <span data-field="secondary" class="text-xs text-slate-400"></span>
        </a>
    </li>
</template>

<template data-activity-row-template>
    <div class="flex flex-wrap items-center justify-between gap-2 px-6 py-3 text-sm">
        <div>
            <span data-field="description" class="font-medium text-slate-900"></span>
            <span data-field="failure" class="ml-2 hidden rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700">Failed</span>
        </div>
        <span data-field="meta" class="text-xs text-slate-400"></span>
    </div>
</template>

@endsection
