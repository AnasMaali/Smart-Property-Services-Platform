@extends('admin.layouts.app')

@section('title', 'Service Category — BLUE Admin')
@section('page-title', 'Service Category detail')

@section('content')

<div data-category-detail-page data-category-id="{{ $categoryId }}" class="space-y-6">

    <a
        href="/admin/service-categories"
        class="inline-flex items-center gap-1.5 text-sm font-medium
               text-slate-500 hover:text-slate-800">
        &larr; Back to service categories
    </a>

    <div data-category-loading class="rounded-2xl border border-slate-200 bg-white p-10
                text-center text-sm text-slate-500">
        Loading service category...
    </div>

    <div data-category-error class="hidden rounded-2xl border border-red-200 bg-red-50
                p-10 text-center text-sm text-red-700"></div>

    <div data-category-content style="display: none;" class="flex flex-col gap-6">

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">
                        Category <span data-field="code"></span>
                    </p>
                    <h2 data-field="name" class="mt-1 text-2xl font-semibold text-slate-950"></h2>
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
                        maxlength="120"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                               text-sm text-slate-900 outline-none focus:border-blue-500
                               focus:ring-4 focus:ring-blue-100">
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Description</label>
                    <textarea
                        name="description"
                        rows="3"
                        maxlength="500"
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

        <div class="rounded-2xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-6 py-4">
                <h3 class="text-sm font-semibold text-slate-900">
                    Services (<span data-field="services_count"></span>)
                </h3>
            </div>
            <div data-services-empty class="hidden px-6 py-6 text-sm text-slate-500">
                This category has no services yet.
            </div>
            <div data-services class="divide-y divide-slate-100"></div>
        </div>

    </div>

</div>

<template data-service-row-template>
    <a data-service-link class="flex flex-wrap items-center justify-between gap-3 p-5 hover:bg-slate-50">
        <div>
            <p data-field="name" class="font-medium text-slate-900"></p>
            <p data-field="code" class="mt-0.5 text-xs text-slate-500"></p>
        </div>
        <div class="flex items-center gap-2">
            <span data-field="display_order" class="text-xs text-slate-400"></span>
            <span data-field="is_active" class="rounded-full px-2.5 py-1 text-xs font-semibold"></span>
        </div>
    </a>
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
