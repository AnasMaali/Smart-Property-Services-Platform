@extends('admin.layouts.app')

@section('title', 'Dashboard — BLUE Admin')
@section('page-title', 'Dashboard')

@section('content')

<div class="rounded-2xl border border-slate-200 bg-white p-8">

    <p class="text-sm font-medium text-blue-600">
        BLUE Admin Panel
    </p>

    <h2 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">
        Admin frontend foundation is ready.
    </h2>

    <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-500">
        Dashboard data will be connected after authentication and the
        existing Admin APIs are wired into the interface.
    </p>

</div>

@endsection
