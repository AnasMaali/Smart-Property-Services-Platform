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

            <form data-change-category-form class="mt-4 flex flex-wrap items-end gap-3 border-t border-slate-100 pt-4">
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Move to category</label>
                    <select
                        name="category_id"
                        data-change-category-select
                        class="w-64 rounded-lg border border-slate-300 bg-white px-3 py-2
                               text-sm text-slate-900 outline-none focus:border-blue-500
                               focus:ring-4 focus:ring-blue-100"></select>
                </div>
                <button
                    type="submit"
                    class="rounded-lg border border-slate-300 bg-white px-3.5 py-2
                           text-xs font-semibold text-slate-700 hover:bg-slate-50">
                    Change category
                </button>
                <p data-change-category-error class="hidden text-sm text-red-600"></p>
            </form>
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
                    Forward-looking eligibility configuration only - never affects an already-placed Cart item,
                    an existing Booking/Payment, or an already-approved/active Contract.
                </p>

                <form data-capabilities-form class="mt-3 space-y-3">
                    <div data-capabilities-checkboxes class="flex flex-wrap gap-4 text-sm"></div>
                    <ul data-capabilities-warnings class="space-y-1 text-xs text-amber-700"></ul>
                    <div class="flex items-center gap-4">
                        <button type="submit" class="rounded-lg bg-slate-950 px-3.5 py-2 text-xs font-semibold text-white hover:bg-slate-800">
                            Save Capabilities
                        </button>
                        <p data-capabilities-error class="hidden text-sm text-red-600"></p>
                    </div>
                </form>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h3 class="text-sm font-semibold text-slate-900">Required specializations</h3>
                <p class="mt-1 text-xs text-slate-400">
                    Determines technician-candidate eligibility for FUTURE assignments only - never changes an
                    already-completed assignment.
                </p>
                <div data-specializations-empty class="hidden mt-3 text-sm text-slate-500">No specializations linked.</div>
                <ul data-specializations class="mt-3 space-y-1.5 text-sm"></ul>

                <form data-specialization-form class="mt-4 space-y-3 border-t border-slate-100 pt-4">
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-slate-600">Specialization</label>
                        <select
                            name="specialization_id"
                            data-specialization-select
                            required
                            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                                   text-sm text-slate-900 outline-none focus:border-blue-500
                                   focus:ring-4 focus:ring-blue-100"></select>
                    </div>
                    <div class="flex items-center gap-4 text-sm">
                        <label class="flex items-center gap-1.5">
                            <input type="checkbox" name="is_primary" class="rounded border-slate-300">
                            Primary
                        </label>
                        <label class="flex items-center gap-1.5">
                            <input type="checkbox" name="is_active" checked class="rounded border-slate-300">
                            Active
                        </label>
                    </div>
                    <div class="flex items-center gap-3">
                        <button
                            type="submit"
                            class="rounded-lg bg-slate-950 px-3.5 py-2 text-xs font-semibold
                                   text-white hover:bg-slate-800">
                            Save specialization
                        </button>
                        <p data-specialization-error class="hidden text-sm text-red-600"></p>
                    </div>
                </form>
            </div>

        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Options &amp; choices</h3>
                    <p class="mt-1 text-xs text-slate-400">
                        Validated by the Cart and priced through the canonical pricing engine. Deactivating an
                        option/choice never rewrites an in-progress Cart or a historical Booking snapshot.
                    </p>
                </div>
                <button
                    type="button"
                    data-add-option-open
                    class="shrink-0 rounded-lg border border-slate-300 bg-white px-3.5 py-2
                           text-xs font-semibold text-slate-700 hover:bg-slate-50">
                    Add option
                </button>
            </div>
            <div data-options-empty class="hidden mt-3 text-sm text-slate-500">No options configured.</div>
            <div data-options class="mt-3 divide-y divide-slate-100"></div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Media</h3>
            <p class="mt-1 text-xs text-slate-400">
                JPG/PNG/WebP, up to 5MB. Deactivating an image hides it from the catalog without deleting the file.
            </p>
            <div data-media-empty class="hidden mt-3 text-sm text-slate-500">No media uploaded.</div>
            <div data-media class="mt-3 divide-y divide-slate-100"></div>

            <form data-media-upload-form class="mt-4 space-y-3 border-t border-slate-100 pt-4" enctype="multipart/form-data">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-slate-600">Image file</label>
                        <input
                            type="file"
                            name="file"
                            accept="image/png,image/jpeg,image/webp"
                            required
                            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-slate-600">Alt text</label>
                        <input
                            type="text"
                            name="alt_text"
                            required
                            minlength="2"
                            maxlength="250"
                            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                                   text-sm text-slate-900 outline-none focus:border-blue-500
                                   focus:ring-4 focus:ring-blue-100">
                    </div>
                </div>
                <label class="flex items-center gap-1.5 text-sm">
                    <input type="checkbox" name="is_primary" class="rounded border-slate-300">
                    Set as primary image
                </label>
                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        data-media-upload-submit
                        class="rounded-lg bg-slate-950 px-3.5 py-2 text-xs font-semibold
                               text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50">
                        Upload image
                    </button>
                    <p data-media-upload-error class="hidden text-sm text-red-600"></p>
                </div>
            </form>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Pricing</h3>
            <p class="mt-1 text-xs text-slate-400">
                Original price is display-only commercial metadata. Current price is the actual selling price - the
                SAME one the customer catalog and checkout use, published through the canonical pricing flow.
            </p>

            <div class="mt-4 grid grid-cols-1 gap-6 sm:grid-cols-2">
                <form data-original-price-form class="space-y-2">
                    <label class="block text-xs font-medium text-slate-600">Original / list price (AED)</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="original_price"
                            min="0"
                            step="0.01"
                            placeholder="No list price set"
                            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                                   text-sm text-slate-900 outline-none focus:border-blue-500
                                   focus:ring-4 focus:ring-blue-100">
                        <button
                            type="submit"
                            class="shrink-0 rounded-lg border border-slate-300 bg-white px-3.5 py-2
                                   text-xs font-semibold text-slate-700 hover:bg-slate-50">
                            Save
                        </button>
                    </div>
                    <p data-original-price-error class="hidden text-sm text-red-600"></p>
                </form>

                <form data-current-price-form class="space-y-2">
                    <label class="block text-xs font-medium text-slate-600">Current selling price (AED)</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            name="current_price"
                            min="0.01"
                            step="0.01"
                            required
                            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2
                                   text-sm text-slate-900 outline-none focus:border-blue-500
                                   focus:ring-4 focus:ring-blue-100">
                        <button
                            type="submit"
                            class="shrink-0 rounded-lg bg-slate-950 px-3.5 py-2 text-xs font-semibold
                                   text-white hover:bg-slate-800">
                            Publish
                        </button>
                    </div>
                    <p data-current-price-error class="hidden text-sm text-red-600"></p>
                </form>
            </div>

            <p data-discount-summary class="mt-3 text-sm text-slate-600"></p>

            <div class="mt-4 border-t border-slate-100 pt-4">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">
                    Pricing scheme versions (<span data-field="pricing_currency"></span>) - advanced editing lives in
                    the <a href="/admin/pricing" class="font-medium text-blue-600 hover:text-blue-800">Pricing module</a>.
                </p>
                <div data-pricing-empty class="hidden mt-3 text-sm text-slate-500">No pricing scheme configured yet.</div>
                <ul data-pricing-versions class="mt-3 space-y-1.5 text-sm"></ul>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Catalog &amp; promotion</h3>
            <p class="mt-1 text-xs text-slate-400">
                Display/policy metadata only - never read by the pricing calculation.
            </p>

            <form data-catalog-policy-form class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <label class="flex items-center gap-2 text-sm sm:col-span-2 lg:col-span-4">
                    <input type="checkbox" name="is_featured" class="h-4 w-4 rounded border-slate-300">
                    Featured / Best Seller
                </label>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Estimated duration (minutes)</label>
                    <input type="number" name="estimated_duration_minutes" min="1" max="32767"
                           class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900">
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Min quantity</label>
                    <input type="number" name="min_quantity" min="1" max="1000" required
                           class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900">
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-600">Max quantity</label>
                    <input type="number" name="max_quantity" min="1" max="1000" required
                           class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900">
                </div>
                <div class="flex items-end sm:col-span-2 lg:col-span-4">
                    <button type="submit" class="rounded-lg bg-slate-950 px-3.5 py-2 text-xs font-semibold text-white hover:bg-slate-800">
                        Save catalog policy
                    </button>
                    <p data-catalog-policy-error class="ml-4 hidden text-sm text-red-600"></p>
                </div>
            </form>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Payment policy</h3>
            <p class="mt-1 text-xs text-slate-400">
                At least one method is required. "Requires prepayment online" is automatically true
                whenever Pay on Site is not allowed - it is never set independently.
            </p>

            <form data-payment-policy-form class="mt-4 space-y-3">
                <div data-payment-methods-checkboxes class="flex flex-wrap gap-4 text-sm"></div>
                <p data-payment-policy-requires-prepayment class="text-xs text-slate-500"></p>
                <div class="flex items-center gap-4">
                    <button type="submit" class="rounded-lg bg-slate-950 px-3.5 py-2 text-xs font-semibold text-white hover:bg-slate-800">
                        Save payment policy
                    </button>
                    <p data-payment-policy-error class="hidden text-sm text-red-600"></p>
                </div>
            </form>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Content sections</h3>
                    <p class="mt-1 text-xs text-slate-400">Overview, Recommended For, What's Included, or a custom heading.</p>
                </div>
            </div>
            <div data-content-sections-empty class="hidden mt-3 text-sm text-slate-500">No content sections yet.</div>
            <div data-content-sections class="mt-3 divide-y divide-slate-100"></div>

            <form data-add-content-section-form class="mt-4 space-y-3 border-t border-slate-100 pt-4">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <select name="section_type_code" data-content-section-type-select required
                            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"></select>
                    <input type="text" name="title" required maxlength="160" placeholder="Title"
                           class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 sm:col-span-2">
                </div>
                <textarea name="body" required rows="2" placeholder="Body"
                          class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"></textarea>
                <div class="flex items-center gap-4">
                    <button type="submit" class="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                        Add content section
                    </button>
                    <p data-add-content-section-error class="hidden text-sm text-red-600"></p>
                </div>
            </form>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Checkpoints</h3>
                    <p class="mt-1 text-xs text-slate-400">Workshop checklist groups - checkpoint counts are always derived from active checkpoints.</p>
                </div>
                <button type="button" data-add-checkpoint-group-open
                        class="shrink-0 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                    + Add group
                </button>
            </div>
            <div data-checkpoint-groups-empty class="hidden mt-3 text-sm text-slate-500">No checkpoint groups yet.</div>
            <div data-checkpoint-groups class="mt-3 space-y-4"></div>
        </div>

    </div>

</div>

<template data-content-section-row-template>
    <div data-content-section-row class="py-3 first:pt-0 last:pb-0">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <span data-field="section_type_code" class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600"></span>
                <p data-field="title" class="mt-1 font-medium text-slate-900"></p>
                <p data-field="body" class="mt-0.5 text-xs text-slate-500"></p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <span data-field="is_active" class="rounded-full px-2.5 py-1 text-xs font-semibold"></span>
                <button type="button" data-edit-content-section class="rounded-full border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">Edit</button>
                <button type="button" data-toggle-content-section-active class="rounded-full border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50"></button>
            </div>
        </div>
    </div>
</template>

<template data-checkpoint-group-card-template>
    <div data-checkpoint-group-card class="rounded-xl border border-slate-100 p-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <p data-field="name" class="font-medium text-slate-900"></p>
                <p data-field="description" class="mt-0.5 text-xs text-slate-500"></p>
                <p class="mt-0.5 text-xs text-slate-400">
                    <span data-field="active_checkpoint_count"></span>/<span data-field="checkpoint_count"></span> active checkpoints
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <span data-field="is_active" class="rounded-full px-2.5 py-1 text-xs font-semibold"></span>
                <button type="button" data-edit-checkpoint-group class="rounded-full border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">Edit</button>
                <button type="button" data-toggle-checkpoint-group-active class="rounded-full border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50"></button>
                <button type="button" data-add-checkpoint-open class="rounded-full border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">+ Checkpoint</button>
            </div>
        </div>
        <ul data-checkpoints class="mt-3 space-y-1.5 text-xs text-slate-600"></ul>
    </div>
</template>

<template data-checkpoint-row-template>
    <li data-checkpoint-row class="flex items-center justify-between gap-2">
        <span>
            <span data-field="name" class="text-slate-700"></span>
            <span data-field="action_type_name" class="ml-1.5 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600"></span>
        </span>
        <span class="flex items-center gap-1.5">
            <span data-field="is_active" class="rounded-full px-2 py-0.5 text-[11px] font-semibold"></span>
            <button type="button" data-edit-checkpoint class="rounded-full border border-slate-300 bg-white px-2 py-0.5 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">Edit</button>
            <button type="button" data-toggle-checkpoint-active class="rounded-full border border-slate-300 bg-white px-2 py-0.5 text-[11px] font-semibold text-slate-700 hover:bg-slate-50"></button>
        </span>
    </li>
</template>

<div data-add-checkpoint-group-modal style="display: none;" class="fixed inset-0 z-50 items-center justify-center bg-slate-950/60 p-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">
        <h2 data-add-checkpoint-group-title class="text-lg font-semibold text-slate-950">Add checkpoint group</h2>
        <form data-add-checkpoint-group-form class="mt-4 space-y-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Name</label>
                <input type="text" name="name" required minlength="2" maxlength="160" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Description</label>
                <input type="text" name="description" maxlength="500" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Display order</label>
                <input type="number" name="display_order" min="0" max="65535" value="0" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <p data-add-checkpoint-group-error class="hidden text-sm text-red-600"></p>
            <div class="mt-2 flex justify-end gap-3">
                <button type="button" data-add-checkpoint-group-cancel class="rounded-xl px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" data-add-checkpoint-group-submit class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Save</button>
            </div>
        </form>
    </div>
</div>

<div data-add-checkpoint-modal style="display: none;" class="fixed inset-0 z-50 items-center justify-center bg-slate-950/60 p-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">
        <h2 data-add-checkpoint-title class="text-lg font-semibold text-slate-950">Add checkpoint</h2>
        <form data-add-checkpoint-form class="mt-4 space-y-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Name</label>
                <input type="text" name="name" required minlength="2" maxlength="160" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Description</label>
                <input type="text" name="description" maxlength="500" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Action</label>
                <select name="action_type_code" data-checkpoint-action-type-select required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></select>
            </div>
            <div data-checkpoint-move-field class="hidden">
                <label class="mb-1 block text-xs font-medium text-slate-600">Group</label>
                <select name="group_uuid" data-checkpoint-group-select class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Display order</label>
                <input type="number" name="display_order" min="0" max="65535" value="0" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <p data-add-checkpoint-error class="hidden text-sm text-red-600"></p>
            <div class="mt-2 flex justify-end gap-3">
                <button type="button" data-add-checkpoint-cancel class="rounded-xl px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" data-add-checkpoint-submit class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Save</button>
            </div>
        </form>
    </div>
</div>

<div data-edit-content-section-modal style="display: none;" class="fixed inset-0 z-50 items-center justify-center bg-slate-950/60 p-4">
    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
        <h2 class="text-lg font-semibold text-slate-950">Edit content section</h2>
        <form data-edit-content-section-form class="mt-4 space-y-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Title</label>
                <input type="text" name="title" required minlength="2" maxlength="160" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Body</label>
                <textarea name="body" required rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Display order</label>
                <input type="number" name="display_order" min="0" max="65535" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <p data-edit-content-section-error class="hidden text-sm text-red-600"></p>
            <div class="mt-2 flex justify-end gap-3">
                <button type="button" data-edit-content-section-cancel class="rounded-xl px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" data-edit-content-section-submit class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Save</button>
            </div>
        </form>
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
                <button type="button" data-option-edit class="rounded-full border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">Edit</button>
                <button type="button" data-option-toggle-active class="rounded-full border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50"></button>
            </div>
        </div>
        <p data-field="rule_summary" class="mt-1 text-xs text-slate-500"></p>
        <ul data-choices class="mt-2 space-y-1 text-xs text-slate-500"></ul>
        <button type="button" data-add-choice-open class="mt-2 hidden text-xs font-semibold text-blue-600 hover:text-blue-800">
            + Add choice
        </button>
    </div>
</template>

<template data-choice-row-template>
    <li data-choice-row class="space-y-1.5 py-1">
        <div class="flex items-center justify-between gap-2">
            <span data-field="name"></span>
            <span class="flex items-center gap-1.5">
                <button type="button" data-choice-add-attribute class="rounded-full border border-slate-300 bg-white px-2 py-0.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">+ Attribute</button>
                <button type="button" data-choice-edit class="rounded-full border border-slate-300 bg-white px-2 py-0.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Edit</button>
                <button type="button" data-choice-toggle-active class="rounded-full border border-slate-300 bg-white px-2 py-0.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"></button>
            </span>
        </div>
        <div data-choice-attributes class="flex flex-wrap gap-1"></div>
    </li>
</template>

<template data-choice-attribute-pill-template>
    <span data-choice-attribute-pill class="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700">
        <span data-field="label"></span>
        <button type="button" data-choice-attribute-toggle title="Activate/deactivate" class="text-blue-400 hover:text-blue-700">●</button>
    </span>
</template>

<div data-add-choice-attribute-modal style="display: none;" class="fixed inset-0 z-50 items-center justify-center bg-slate-950/60 p-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">
        <h2 class="text-lg font-semibold text-slate-950">Add attribute</h2>
        <form data-add-choice-attribute-form class="mt-4 space-y-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Attribute</label>
                <select name="attribute_type_code" data-choice-attribute-type-select required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Value</label>
                <input type="text" name="value" required maxlength="255" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <p data-add-choice-attribute-error class="hidden text-sm text-red-600"></p>
            <div class="mt-2 flex justify-end gap-3">
                <button type="button" data-add-choice-attribute-cancel class="rounded-xl px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" data-add-choice-attribute-submit class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Save</button>
            </div>
        </form>
    </div>
</div>

<template data-media-row-template>
    <div data-media-row class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
        <div>
            <p data-field="alt_text" class="text-sm font-medium text-slate-900"></p>
            <p data-field="mime_type" class="text-xs text-slate-500"></p>
        </div>
        <div class="flex items-center gap-2">
            <span data-field="is_primary" class="hidden rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">Primary</span>
            <span data-field="is_active" class="rounded-full px-2.5 py-1 text-xs font-semibold"></span>
            <button type="button" data-media-toggle-active class="rounded-full border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50"></button>
        </div>
    </div>
</template>

<div data-add-option-modal style="display: none;" class="fixed inset-0 z-50 items-center justify-center bg-slate-950/60 p-4">
    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
        <h2 data-add-option-title class="text-lg font-semibold text-slate-950">Add option</h2>

        <form data-add-option-form class="mt-4 space-y-3">
            <div data-option-create-only-fields class="grid grid-cols-2 gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Code</label>
                    <input type="text" name="code" minlength="2" maxlength="80" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Type</label>
                    <select name="option_type_code" data-option-type-select class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Name</label>
                    <input type="text" name="name" required minlength="2" maxlength="160" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Display order</label>
                    <input type="number" name="display_order" min="0" max="65535" value="0" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Description</label>
                <input type="text" name="description" maxlength="500" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <label class="flex items-center gap-1.5 text-sm">
                <input type="checkbox" name="is_required" class="rounded border-slate-300">
                Required
            </label>

            <div data-numeric-fields class="hidden grid grid-cols-2 gap-3 rounded-lg border border-slate-100 bg-slate-50 p-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Min value</label>
                    <input type="number" name="min_value" step="any" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Max value</label>
                    <input type="number" name="max_value" step="any" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
            </div>

            <div data-selection-fields class="hidden grid grid-cols-2 gap-3 rounded-lg border border-slate-100 bg-slate-50 p-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Min selections</label>
                    <input type="number" name="minimum_selections" min="0" value="0" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Max selections</label>
                    <input type="number" name="maximum_selections" min="1" value="1" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
            </div>

            <p data-add-option-error class="hidden text-sm text-red-600"></p>

            <div class="mt-2 flex justify-end gap-3">
                <button type="button" data-add-option-cancel class="rounded-xl px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" data-add-option-submit class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Create option</button>
            </div>
        </form>
    </div>
</div>

<div data-add-choice-modal style="display: none;" class="fixed inset-0 z-50 items-center justify-center bg-slate-950/60 p-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">
        <h2 data-add-choice-title class="text-lg font-semibold text-slate-950">Add choice</h2>

        <form data-add-choice-form class="mt-4 space-y-3">
            <div data-choice-create-only-fields>
                <label class="mb-1 block text-xs font-medium text-slate-600">Code</label>
                <input type="text" name="code" minlength="2" maxlength="80" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Name</label>
                <input type="text" name="name" required minlength="2" maxlength="160" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Description</label>
                <input type="text" name="description" maxlength="500" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Display order</label>
                <input type="number" name="display_order" min="0" max="65535" value="0" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <p data-add-choice-error class="hidden text-sm text-red-600"></p>
            <div class="mt-2 flex justify-end gap-3">
                <button type="button" data-add-choice-cancel class="rounded-xl px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" data-add-choice-submit class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Create choice</button>
            </div>
        </form>
    </div>
</div>

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
