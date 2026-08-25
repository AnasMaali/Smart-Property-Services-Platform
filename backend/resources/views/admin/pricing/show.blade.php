@extends('admin.layouts.app')

@section('title', 'Pricing scheme — BLUE Admin')
@section('page-title', 'Pricing scheme detail')

@section('content')

<div data-pricing-detail-page data-scheme-uuid="{{ $schemeUuid }}" class="space-y-6">

    <a
        href="/admin/pricing"
        class="inline-flex items-center gap-1.5 text-sm font-medium
               text-slate-500 hover:text-slate-800">
        &larr; Back to pricing
    </a>

    <div data-scheme-loading class="rounded-2xl border border-slate-200 bg-white p-10
                text-center text-sm text-slate-500">
        Loading pricing scheme...
    </div>

    <div data-scheme-error class="hidden rounded-2xl border border-red-200 bg-red-50
                p-10 text-center text-sm text-red-700"></div>

    <div data-scheme-content style="display: none;" class="flex flex-col gap-6">

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">
                        Pricing scheme version
                    </p>
                    <h2 class="mt-1 text-2xl font-semibold text-slate-950">
                        <a data-service-link class="hover:underline"></a>
                    </h2>
                    <p class="mt-1 text-xs text-slate-400">
                        Currency <span data-field="currency"></span>
                    </p>
                </div>

                <span data-field="status" class="rounded-full px-3 py-1.5 text-xs font-semibold"></span>
            </div>

            <dl class="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-xs text-slate-500">Effective from</dt>
                    <dd data-field="effective_from" class="font-medium text-slate-900"></dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Effective to</dt>
                    <dd data-field="effective_to" class="font-medium text-slate-900"></dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Published</dt>
                    <dd data-field="published_at" class="font-medium text-slate-900"></dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Updated</dt>
                    <dd data-field="updated_at" class="font-medium text-slate-900"></dd>
                </div>
            </dl>
        </div>

        <div data-publish-card class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Publish this draft</h3>
            <p class="mt-1 text-xs text-slate-400">
                Makes this version live for real customer price calculations. Requires a fresh security-key
                verification.
            </p>

            <form data-publish-form class="mt-3 flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Effective from</label>
                    <input
                        type="datetime-local"
                        name="effective_from"
                        required
                        class="rounded-lg border border-slate-300 bg-white px-3 py-2
                               text-sm text-slate-900 outline-none focus:border-blue-500
                               focus:ring-4 focus:ring-blue-100">
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Effective to (optional)</label>
                    <input
                        type="datetime-local"
                        name="effective_to"
                        class="rounded-lg border border-slate-300 bg-white px-3 py-2
                               text-sm text-slate-900 outline-none focus:border-blue-500
                               focus:ring-4 focus:ring-blue-100">
                </div>

                <button
                    type="submit"
                    data-publish-submit
                    class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold
                           text-white transition hover:bg-slate-800
                           disabled:cursor-not-allowed disabled:opacity-50">
                    Publish
                </button>
            </form>

            <div data-publish-error class="hidden mt-3 rounded-xl border border-red-200 bg-red-50
                        px-4 py-3 text-sm text-red-700"></div>
        </div>

        <div data-add-rule-card class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Add a rule</h3>
            <p class="mt-1 text-xs text-slate-400">
                Covers a single unconditional effect per rule. For conditional rules (option/context-based)
                or multi-tier ADD_PER_UNIT pricing, use the Admin Pricing API directly - see
                docs/api-contracts/admin-operations-v1.md.
            </p>

            <form data-add-rule-form class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Rule code</label>
                    <input type="text" name="rule_code" required maxlength="80"
                           class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Label</label>
                    <input type="text" name="label" required maxlength="160"
                           class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Priority</label>
                    <input type="number" name="priority" required min="0" max="65535"
                           class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Effect type</label>
                    <select name="effect_type" data-effect-type-select required
                            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                        <option value="SET_PRICE">Set price</option>
                        <option value="ADD_FIXED">Add fixed amount</option>
                        <option value="MULTIPLY">Multiply</option>
                        <option value="MIN_TOTAL">Minimum total</option>
                        <option value="MAX_TOTAL">Maximum total</option>
                        <option value="QUOTE_REQUIRED">Quote required</option>
                    </select>
                </div>

                <div data-effect-amount-field>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Amount</label>
                    <input type="number" step="0.000001" name="effect_amount"
                           class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                </div>

                <div class="flex items-center gap-2 pt-6">
                    <input type="checkbox" name="stop_processing" id="stop_processing"
                           class="h-4 w-4 rounded border-slate-300">
                    <label for="stop_processing" class="text-sm text-slate-700">Stop processing further rules</label>
                </div>

                <div class="sm:col-span-2 lg:col-span-4 flex items-center gap-4">
                    <p data-add-rule-error class="hidden text-sm text-red-600"></p>
                    <button type="submit" data-add-rule-submit
                            class="ml-auto rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50">
                        Add rule
                    </button>
                </div>
            </form>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-6 py-4">
                <h3 class="text-sm font-semibold text-slate-900">
                    Rules (<span data-field="rules_count"></span>)
                </h3>
            </div>
            <div data-rules-empty class="hidden px-6 py-6 text-sm text-slate-500">
                No rules on this scheme version yet.
            </div>
            <div data-rules class="divide-y divide-slate-100"></div>
        </div>

    </div>

</div>

<template data-rule-card-template>
    <div data-rule-card class="p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p data-field="label" class="font-medium text-slate-900"></p>
                <p class="mt-0.5 text-xs text-slate-400">
                    <span data-field="rule_code"></span> &middot; priority <span data-field="priority"></span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span data-field="effect_summary" class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700"></span>
                <span data-field="stop_processing" style="display: none;" class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">Stops processing</span>
                <button type="button" data-delete-rule-button style="display: none;"
                        class="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50">
                    Delete
                </button>
            </div>
        </div>

        <div data-condition-groups class="mt-3 space-y-1 text-xs text-slate-500"></div>
        <div data-tiers class="mt-3"></div>
    </div>
</template>

@endsection
