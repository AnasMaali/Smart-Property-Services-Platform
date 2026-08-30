@extends('admin.layouts.app')

@section('title', 'Audit log entry — BLUE Admin')
@section('page-title', 'Audit log entry detail')

@section('content')

<div data-audit-log-detail-page data-audit-log-uuid="{{ $auditLogUuid }}" class="space-y-6">

    <a
        href="/admin/audit-log"
        class="inline-flex items-center gap-1.5 text-sm font-medium
               text-slate-500 hover:text-slate-800">
        &larr; Back to audit log
    </a>

    <div data-audit-log-loading class="rounded-2xl border border-slate-200 bg-white p-10
                text-center text-sm text-slate-500">
        Loading audit log entry...
    </div>

    <div data-audit-log-error class="hidden rounded-2xl border border-red-200 bg-red-50
                p-10 text-center text-sm text-red-700"></div>

    <div data-audit-log-content style="display: none;" class="flex flex-col gap-6">

        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Action</p>
                    <h2 data-field="action_code" class="mt-1 text-2xl font-semibold text-slate-950"></h2>
                    <p class="mt-1 text-xs text-slate-400">
                        <span data-field="created_at"></span>
                    </p>
                </div>

                <span data-field="outcome" class="rounded-full px-3 py-1.5 text-xs font-semibold"></span>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h3 class="text-sm font-semibold text-slate-900">Entity</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Type</dt>
                        <dd data-field="entity_type" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Identifier</dt>
                        <dd data-field="entity_identifier" class="font-medium text-slate-900"></dd>
                    </div>
                    <div data-failure-row class="flex justify-between gap-4">
                        <dt class="text-slate-500">Failure reason</dt>
                        <dd data-field="failure_reason" class="font-medium text-red-700"></dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h3 class="text-sm font-semibold text-slate-900">Actor &amp; session</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Admin</dt>
                        <dd data-field="actor_name" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">IP address</dt>
                        <dd data-field="ip_address" class="font-medium text-slate-900"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">User agent</dt>
                        <dd data-field="user_agent" class="max-w-[60%] break-words text-right font-medium text-slate-900"></dd>
                    </div>
                </dl>
            </div>

        </div>

    </div>

</div>

@endsection
