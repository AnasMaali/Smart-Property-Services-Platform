@extends('admin.layouts.app')

@section('title', 'Rating — BLUE Admin')
@section('page-title', 'Rating detail')

@section('content')

<div data-rating-detail-page data-booking-uuid="{{ $bookingUuid }}" class="space-y-6">

    <a
        href="/admin/ratings"
        class="inline-flex items-center gap-1.5 text-sm font-medium
               text-slate-500 hover:text-slate-800">
        &larr; Back to ratings
    </a>

    <div data-rating-loading class="rounded-2xl border border-slate-200 bg-white p-10
                text-center text-sm text-slate-500">
        Loading rating...
    </div>

    <div data-rating-error class="hidden rounded-2xl border border-red-200 bg-red-50
                p-10 text-center text-sm text-red-700"></div>

    <div data-rating-content style="display: none;" class="flex flex-col gap-6">

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">
                        Booking <a data-booking-link class="font-medium text-blue-600 hover:text-blue-800"></a>
                    </p>
                    <h2 class="mt-1 text-2xl font-semibold text-slate-950">
                        <span data-field="rating_value"></span> / 5
                    </h2>
                    <p class="mt-1 text-xs text-slate-400">
                        Submitted <span data-field="created_at"></span>
                    </p>
                </div>

                <span data-field="booking_status" class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600"></span>
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
                </dl>
                <a data-customer-link
                   class="mt-4 inline-block text-xs font-semibold text-blue-600 hover:text-blue-800">
                    View customer &rarr;
                </a>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h3 class="text-sm font-semibold text-slate-900">Services in this booking</h3>
                <ul data-services class="mt-3 space-y-1.5 text-sm text-slate-700"></ul>
            </div>

        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <h3 class="text-sm font-semibold text-slate-900">Comment</h3>
            <p data-field="comment" class="mt-2 whitespace-pre-wrap text-sm text-slate-700"></p>
        </div>

    </div>

</div>

@endsection
