@extends('admin.layouts.app')

@section('title', 'Customer — BLUE Admin')
@section('page-title', 'Customer detail')

@section('content')

<div data-customer-detail-page data-customer-uuid="{{ $customerUuid }}" class="space-y-6">

    <a
        href="/admin/customers"
        class="inline-flex items-center gap-1.5 text-sm font-medium
               text-slate-500 hover:text-slate-800">
        &larr; Back to customers
    </a>

    <div data-customer-loading class="rounded-2xl border border-slate-200 bg-white p-10
                text-center text-sm text-slate-500">
        Loading customer...
    </div>

    <div data-customer-error class="hidden rounded-2xl border border-red-200 bg-red-50
                p-10 text-center text-sm text-red-700"></div>

    <div data-customer-content style="display: none;" class="flex flex-col gap-6">

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Customer</p>
                    <h2 data-field="full_name" class="mt-1 text-2xl font-semibold text-slate-950"></h2>
                    <p class="mt-1 text-xs text-slate-400">
                        Customer since <span data-field="created_at"></span>
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <span data-field="account_status" data-status-badge
                          class="rounded-full px-3 py-1.5 text-xs font-semibold"></span>
                    <span data-deletion-badge style="display: none;"
                          class="rounded-full bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700">
                        Deletion pending
                    </span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h3 class="text-sm font-semibold text-slate-900">Contact</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Phone</dt>
                        <dd data-field="phone_number" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Phone verified</dt>
                        <dd data-field="phone_verified" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Email</dt>
                        <dd data-field="email" class="font-medium text-slate-900"></dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h3 class="text-sm font-semibold text-slate-900">Account</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Last login</dt>
                        <dd data-field="last_login_at" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Updated</dt>
                        <dd data-field="updated_at" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Deletion requested</dt>
                        <dd data-field="deletion_requested_at" class="font-medium text-slate-900"></dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h3 class="text-sm font-semibold text-slate-900">Profile</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Location</dt>
                        <dd data-field="location" class="text-right font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Relationship</dt>
                        <dd data-field="property_relationship" class="font-medium text-slate-900"></dd>
                    </div>
                </dl>
            </div>

        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Operational links</h3>
            <div class="mt-3 flex flex-wrap gap-2" data-operational-links></div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-6 py-4">
                <h3 class="text-sm font-semibold text-slate-900">
                    Properties (<span data-field="properties_count"></span>)
                </h3>
            </div>
            <div data-properties-empty class="hidden px-6 py-6 text-sm text-slate-500">
                This customer has not added any properties yet.
            </div>
            <div data-properties class="divide-y divide-slate-100"></div>
        </div>

    </div>

</div>

<template data-property-row-template>
    <a data-property-link class="flex flex-wrap items-center justify-between gap-3 p-5 hover:bg-slate-50">
        <div>
            <p data-field="label" class="font-medium text-slate-900"></p>
            <p data-field="address_summary" class="mt-0.5 text-xs text-slate-500"></p>
        </div>
        <div class="flex items-center gap-2">
            <span data-field="relationship_type" class="text-xs text-slate-400"></span>
            <span data-field="is_active" class="rounded-full px-2.5 py-1 text-xs font-semibold"></span>
        </div>
    </a>
</template>

@endsection
