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
                Set base prices, per-unit/tiered pricing (e.g. "first unit 200, each additional 75", or
                hourly with a discounted rate past a threshold), and conditional pricing (e.g. "when
                Bedrooms = 2, set price to 1250") - all without writing raw JSON.
            </p>

            <form data-add-rule-form class="mt-3 space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
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
                        <p class="mt-1 text-xs text-slate-400">Lower runs first.</p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-slate-600">Pricing effect</label>
                        <select name="effect_type" data-effect-type-select required
                                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                            <option value="SET_PRICE">Set base price</option>
                            <option value="ADD_FIXED">Add fixed amount</option>
                            <option value="ADD_PER_UNIT">Add amount per unit (tiered)</option>
                            <option value="MULTIPLY">Multiply</option>
                            <option value="MIN_TOTAL">Minimum total</option>
                            <option value="MAX_TOTAL">Maximum total</option>
                            <option value="QUOTE_REQUIRED">Quote required</option>
                        </select>
                    </div>
                </div>

                <div data-effect-amount-field>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Amount</label>
                    <input type="number" step="0.000001" name="effect_amount"
                           class="w-full max-w-xs rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                </div>

                <div data-per-unit-fields class="hidden space-y-3 rounded-xl border border-slate-100 bg-slate-50 p-4">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-slate-600">Numeric option this counts</label>
                            <select data-effect-option-select
                                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"></select>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-slate-600">Tier calculation</label>
                            <select data-tier-calculation-select
                                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900">
                                <option value="GRADUATED">Graduated (each tier's own units, e.g. first unit + additional units)</option>
                                <option value="VOLUME">Volume (one flat/rate for the whole quantity's matching band)</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <label class="text-xs font-medium text-slate-600">Tiers</label>
                            <button type="button" data-add-tier-button
                                    class="rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                + Add Tier
                            </button>
                        </div>
                        <div data-tiers-editor class="mt-2 space-y-2"></div>
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-medium text-slate-600">Conditions (optional - "When...")</label>
                        <button type="button" data-add-condition-group-button
                                class="rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                            + Add Condition Group
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-slate-400">
                        Groups are OR'd together; conditions within a group are all AND'd. Leave empty for a rule
                        that always applies.
                    </p>
                    <div data-condition-groups-editor class="mt-2 space-y-2"></div>
                </div>

                <div class="flex items-center gap-2">
                    <input type="checkbox" name="stop_processing" id="stop_processing"
                           class="h-4 w-4 rounded border-slate-300">
                    <label for="stop_processing" class="text-sm text-slate-700">Stop processing further rules</label>
                </div>

                <div class="flex items-center gap-4">
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
                <p class="mt-1 text-xs text-slate-400">
                    Every condition/tier below is the exact structure that will be evaluated - this is the
                    readable summary of what publishing will make live.
                </p>
            </div>
            <div data-rules-empty class="hidden px-6 py-6 text-sm text-slate-500">
                No rules on this scheme version yet.
            </div>
            <div data-rules class="divide-y divide-slate-100"></div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Pricing preview</h3>
            <p class="mt-1 text-xs text-slate-400">
                Verify this configuration (including still-unpublished DRAFT changes above only take effect
                once published) against the exact same pricing calculation Cart/Checkout use. Nothing is saved.
            </p>

            <form data-preview-form class="mt-3 space-y-3">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-slate-600">Quantity</label>
                        <input type="number" name="quantity" value="1" min="1" max="1000"
                               class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900">
                    </div>
                </div>
                <div data-preview-options class="space-y-2"></div>
                <div class="flex items-center gap-4">
                    <p data-preview-error class="hidden text-sm text-red-600"></p>
                    <button type="submit" data-preview-submit
                            class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Preview price
                    </button>
                </div>
            </form>

            <div data-preview-result class="hidden mt-4 rounded-xl border border-slate-100 bg-slate-50 p-4 text-sm"></div>
        </div>

    </div>

</div>

<template data-tier-row-template>
    <div data-tier-row class="grid grid-cols-2 gap-2 rounded-lg border border-slate-200 bg-white p-2 sm:grid-cols-6 sm:items-end">
        <div>
            <label class="mb-1 block text-[11px] font-medium text-slate-500">From</label>
            <input type="number" step="0.000001" min="0" data-tier-from required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
        </div>
        <div>
            <label class="mb-1 block text-[11px] font-medium text-slate-500">To (blank = open-ended)</label>
            <input type="number" step="0.000001" min="0" data-tier-to class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
        </div>
        <div>
            <label class="mb-1 block text-[11px] font-medium text-slate-500">Unit size</label>
            <input type="number" step="0.000001" min="0.000001" data-tier-unit-size value="1" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
        </div>
        <div>
            <label class="mb-1 block text-[11px] font-medium text-slate-500">Rate</label>
            <input type="number" step="0.000001" min="0" data-tier-rate required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
        </div>
        <div>
            <label class="mb-1 block text-[11px] font-medium text-slate-500">Mode</label>
            <select data-tier-mode class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <option value="PER_UNIT">Per unit</option>
                <option value="FLAT">Flat</option>
            </select>
        </div>
        <button type="button" data-remove-tier class="rounded border border-red-200 bg-white px-2 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50">Remove</button>
    </div>
</template>

<template data-condition-group-template>
    <div data-condition-group class="rounded-lg border border-slate-200 bg-white p-3">
        <div class="flex items-center justify-between">
            <p class="text-xs font-semibold text-slate-500">Condition group (AND)</p>
            <div class="flex items-center gap-2">
                <button type="button" data-add-condition class="rounded border border-slate-300 bg-white px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">+ Add Condition</button>
                <button type="button" data-remove-condition-group class="rounded border border-red-200 bg-white px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50">Remove group</button>
            </div>
        </div>
        <div data-conditions class="mt-2 space-y-2"></div>
    </div>
</template>

<template data-condition-row-template>
    <div data-condition-row class="grid grid-cols-2 gap-2 rounded border border-slate-100 bg-slate-50 p-2 sm:grid-cols-5 sm:items-end">
        <div>
            <label class="mb-1 block text-[11px] font-medium text-slate-500">When...</label>
            <select data-condition-subject class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <option value="OPTION_CHOICE">Option choice equals</option>
                <option value="OPTION_NUMERIC_VALUE">Numeric option value</option>
                <option value="OPTION_BOOLEAN_VALUE">Boolean option</option>
                <option value="ITEM_QUANTITY">Item quantity</option>
                <option value="CONTEXT_ATTRIBUTE">Context field</option>
            </select>
        </div>
        <div data-condition-option-field>
            <label class="mb-1 block text-[11px] font-medium text-slate-500">Option</label>
            <select data-condition-option class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></select>
        </div>
        <div data-condition-context-field class="hidden">
            <label class="mb-1 block text-[11px] font-medium text-slate-500">Context field code</label>
            <input type="text" data-condition-context class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm" placeholder="e.g. SERVICE_ZONE">
        </div>
        <div data-condition-operator-field>
            <label class="mb-1 block text-[11px] font-medium text-slate-500">Operator</label>
            <select data-condition-operator class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></select>
        </div>
        <div data-condition-value-field>
            <label class="mb-1 block text-[11px] font-medium text-slate-500">Value</label>
            <select data-condition-value-choice class="hidden w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></select>
            <select data-condition-value-boolean class="hidden w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <option value="true">True</option>
                <option value="false">False</option>
            </select>
            <input type="number" step="0.000001" data-condition-value-number class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
        </div>
        <div data-condition-value-high-field class="hidden">
            <label class="mb-1 block text-[11px] font-medium text-slate-500">And (BETWEEN)</label>
            <input type="number" step="0.000001" data-condition-value-number-high class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
        </div>
        <button type="button" data-remove-condition class="rounded border border-red-200 bg-white px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50">Remove</button>
    </div>
</template>

<template data-preview-option-template>
    <div data-preview-option-row>
        <label data-preview-option-label class="mb-1.5 block text-xs font-medium text-slate-600"></label>
        <input type="number" step="0.000001" data-preview-numeric class="hidden w-full max-w-xs rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
        <select data-preview-boolean class="hidden w-full max-w-xs rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
            <option value="">Not selected</option>
            <option value="true">True</option>
            <option value="false">False</option>
        </select>
        <input type="text" data-preview-text class="hidden w-full max-w-xs rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
        <select data-preview-choice multiple size="3" class="hidden w-full max-w-xs rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"></select>
    </div>
</template>

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
