@extends('admin.layouts.app')

@section('title', 'Service — BLUE Admin')
@section('page-title', 'Service detail')

@section('content')

<div data-service-detail-page data-service-uuid="{{ $serviceUuid }}" class="space-y-6">

    <a
        href="/admin/services"
        class="inline-flex items-center gap-1.5 text-sm font-medium
               text-slate-500 hover:text-slate-800">
        &larr; Back to services
    </a>

    <div data-service-loading class="rounded-2xl border border-slate-200 bg-white p-10
                text-center text-sm text-slate-500">
        Loading service...
    </div>

    <div data-service-error class="hidden rounded-2xl border border-red-200 bg-red-50
                p-10 text-center text-sm text-red-700"></div>

    <div data-service-content style="display: none;" class="flex flex-col gap-6">

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">
                        Service <span data-field="code"></span> &middot; <span data-field="slug"></span>
                    </p>
                    <h2 data-field="name" class="mt-1 text-2xl font-semibold text-slate-950"></h2>
                    <p class="mt-1 text-xs text-slate-400">
                        Category: <a data-category-link class="font-medium text-blue-600 hover:text-blue-800"></a>
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <span data-field="is_active" class="rounded-full px-3 py-1.5 text-xs font-semibold"></span>
                    <button
                        type="button"
                        data-toggle-active-button
                        class="rounded-lg border border-slate-300 bg-white px-3.5 py-2
                               text-xs font-semibold text-slate-700 hover:bg-slate-50">
                    </button>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Edit metadata</h3>

            <form data-metadata-form class="mt-3 space-y-4">
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Name</label>
                    <input
                        type="text"
                        name="name"
                        required
                        minlength="2"
                        maxlength="160"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                               text-sm text-slate-900 outline-none focus:border-blue-500
                               focus:ring-4 focus:ring-blue-100">
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Short description</label>
                    <input
                        type="text"
                        name="short_description"
                        maxlength="300"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                               text-sm text-slate-900 outline-none focus:border-blue-500
                               focus:ring-4 focus:ring-blue-100">
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Description</label>
                    <textarea
                        name="description"
                        rows="4"
                        maxlength="5000"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                               text-sm text-slate-900 outline-none focus:border-blue-500
                               focus:ring-4 focus:ring-blue-100"></textarea>
                </div>

                <div class="max-w-xs">
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Display order</label>
                    <input
                        type="number"
                        name="display_order"
                        required
                        min="0"
                        max="65535"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                               text-sm text-slate-900 outline-none focus:border-blue-500
                               focus:ring-4 focus:ring-blue-100">
                </div>

                <div class="flex items-center gap-4">
                    <p data-metadata-error class="hidden text-sm text-red-600"></p>
                    <button
                        type="submit"
                        data-metadata-submit
                        class="ml-auto rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold
                               text-white transition hover:bg-slate-800
                               disabled:cursor-not-allowed disabled:opacity-50">
                        Save changes
                    </button>
                </div>
            </form>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h3 class="text-sm font-semibold text-slate-900">Capabilities</h3>
                <p class="mt-1 text-xs text-slate-400">
                    Read-only - gates real Cart/Contract eligibility behavior.
                </p>
                <div data-capabilities-empty class="hidden mt-3 text-sm text-slate-500">No capabilities.</div>
                <div data-capabilities class="mt-3 flex flex-wrap gap-2"></div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h3 class="text-sm font-semibold text-slate-900">Required specializations</h3>
                <p class="mt-1 text-xs text-slate-400">
                    Read-only - determines technician-candidate eligibility.
                </p>
                <div data-specializations-empty class="hidden mt-3 text-sm text-slate-500">No specializations linked.</div>
                <ul data-specializations class="mt-3 space-y-1.5 text-sm"></ul>
            </div>

        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Options</h3>
            <p class="mt-1 text-xs text-slate-400">
                Read-only - validated by the Cart and priced by the pricing engine; no safe mutation policy exists yet.
            </p>
            <div data-options-empty class="hidden mt-3 text-sm text-slate-500">No options configured.</div>
            <div data-options class="mt-3 divide-y divide-slate-100"></div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Media</h3>
            <p class="mt-1 text-xs text-slate-400">
                Read-only - no existing secure upload pipeline to manage this from the Admin panel.
            </p>
            <div data-media-empty class="hidden mt-3 text-sm text-slate-500">No media uploaded.</div>
            <div data-media class="mt-3 divide-y divide-slate-100"></div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Pricing</h3>
            <p class="mt-1 text-xs text-slate-400">
                Which pricing scheme version applies, in <span data-field="pricing_currency"></span> - full pricing
                rule authoring is a separate, future Admin module.
            </p>
            <div data-pricing-empty class="hidden mt-3 text-sm text-slate-500">No pricing scheme configured yet.</div>
            <ul data-pricing-versions class="mt-3 space-y-1.5 text-sm"></ul>
        </div>

    </div>

</div>

<template data-option-row-template>
    <div data-option-row class="py-4 first:pt-0 last:pb-0">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p data-field="name" class="font-medium text-slate-900"></p>
            <div class="flex items-center gap-2">
                <span data-field="type" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600"></span>
                <span data-field="required" class="rounded-full px-2.5 py-1 text-xs font-semibold"></span>
                <span data-field="is_active" class="rounded-full px-2.5 py-1 text-xs font-semibold"></span>
            </div>
        </div>
        <p data-field="rule_summary" class="mt-1 text-xs text-slate-500"></p>
        <ul data-choices class="mt-2 space-y-1 text-xs text-slate-500"></ul>
    </div>
</template>

<template data-media-row-template>
    <div class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
        <div>
            <p data-field="alt_text" class="text-sm font-medium text-slate-900"></p>
            <p data-field="mime_type" class="text-xs text-slate-500"></p>
        </div>
        <div class="flex items-center gap-2">
            <span data-field="is_primary" class="hidden rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">Primary</span>
            <span data-field="is_active" class="rounded-full px-2.5 py-1 text-xs font-semibold"></span>
        </div>
    </div>
</template>

<div
    data-toggle-active-modal
    style="display: none;"
    class="fixed inset-0 z-50 items-center justify-center bg-slate-950/60 p-4">

    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">

        <h2 data-toggle-active-title class="text-lg font-semibold text-slate-950"></h2>
        <p data-toggle-active-message class="mt-2 text-sm leading-6 text-slate-500"></p>

        <div data-toggle-active-error class="mt-4 hidden rounded-xl border border-red-200
                    bg-red-50 px-4 py-3 text-sm text-red-700"></div>

        <div class="mt-6 flex justify-end gap-3">
            <button
                type="button"
                data-toggle-active-cancel
                class="rounded-xl px-4 py-2.5 text-sm font-medium
                       text-slate-600 hover:bg-slate-50">
                Cancel
            </button>

            <button
                type="button"
                data-toggle-active-confirm
                class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm
                       font-semibold text-white transition
                       hover:bg-slate-800 disabled:cursor-not-allowed
                       disabled:opacity-60">
                Confirm
            </button>
        </div>

    </div>

</div>

@endsection
