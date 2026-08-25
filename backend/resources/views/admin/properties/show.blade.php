@extends('admin.layouts.app')

@section('title', 'Property — BLUE Admin')
@section('page-title', 'Property detail')

@section('content')

<div data-property-detail-page data-property-uuid="{{ $propertyUuid }}" class="space-y-6">

    <a data-back-to-customer class="inline-flex items-center gap-1.5 text-sm font-medium
               text-slate-500 hover:text-slate-800">
        &larr; Back to customer
    </a>

    <div data-property-loading class="rounded-2xl border border-slate-200 bg-white p-10
                text-center text-sm text-slate-500">
        Loading property...
    </div>

    <div data-property-error class="hidden rounded-2xl border border-red-200 bg-red-50
                p-10 text-center text-sm text-red-700"></div>

    <div data-property-content style="display: none;" class="flex flex-col gap-6">

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Property</p>
                    <h2 data-field="label" class="mt-1 text-2xl font-semibold text-slate-950"></h2>
                    <p class="mt-1 text-xs text-slate-400">
                        Added <span data-field="created_at"></span>
                        &middot; Updated <span data-field="updated_at"></span>
                    </p>
                </div>

                <span data-field="is_active" class="rounded-full px-3 py-1.5 text-xs font-semibold"></span>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h3 class="text-sm font-semibold text-slate-900">Customer</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Name</dt>
                        <dd data-field="customer_name" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Phone</dt>
                        <dd data-field="customer_phone" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Relationship</dt>
                        <dd data-field="relationship_type" class="font-medium text-slate-900"></dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h3 class="text-sm font-semibold text-slate-900">Property type</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Type</dt>
                        <dd data-field="property_type" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Other type</dt>
                        <dd data-field="other_property_type_name" class="font-medium text-slate-900"></dd>
                    </div>
                </dl>
            </div>

        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Location</h3>
            <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Area</dt>
                    <dd data-field="area" class="font-medium text-slate-900"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Street</dt>
                    <dd data-field="street_name" class="font-medium text-slate-900"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Building</dt>
                    <dd data-field="building_name_or_number" class="font-medium text-slate-900"></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Floor / Unit</dt>
                    <dd data-field="floor_unit" class="font-medium text-slate-900"></dd>
                </div>
            </dl>
            <p data-field="address_line" class="mt-3 text-sm leading-6 text-slate-600"></p>
            <p data-field="nearby_landmark" class="mt-1 text-xs text-slate-400"></p>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h3 class="text-sm font-semibold text-slate-900">Visit contact</h3>
                <p data-field="visit_contact_phone" class="mt-2 text-sm font-medium text-slate-900"></p>
                <p class="mt-4 text-xs font-medium uppercase tracking-wide text-slate-500">Additional notes</p>
                <p data-field="additional_location_notes" class="mt-1 text-sm leading-6 text-slate-600"></p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white">
                <div class="border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">Contracts at this property</h3>
                </div>
                <div data-contracts-empty class="hidden px-6 py-6 text-sm text-slate-500">
                    No Service Contracts have been requested for this property.
                </div>
                <div data-contracts class="divide-y divide-slate-100 text-sm"></div>
            </div>

        </div>

    </div>

</div>

<template data-contract-row-template>
    <a data-contract-link class="flex items-center justify-between gap-3 p-4 hover:bg-slate-50">
        <div>
            <p data-field="contract_number" class="font-medium text-slate-900"></p>
            <p data-field="term" class="mt-0.5 text-xs text-slate-400"></p>
        </div>
        <span data-field="status" data-status-badge class="rounded-full px-2.5 py-1 text-xs font-semibold"></span>
    </a>
</template>

@endsection
