<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="robots" content="noindex,nofollow,noarchive">

    <title>@yield('title', 'BLUE Admin')</title>

    @vite([
        'resources/css/admin.css',
        'resources/js/admin/app.js',
    ])
</head>

<body class="bg-slate-50 text-slate-900">

<div
    data-admin-loading
    class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-3 bg-slate-50">

    <div class="h-8 w-8 animate-spin rounded-full border-2
                border-slate-300 border-t-blue-600">
    </div>

    <p class="text-sm text-slate-500">
        Restoring your secure session...
    </p>

</div>


<div data-admin-shell class="flex min-h-screen" style="display: none;">

    <aside class="fixed inset-y-0 left-0 hidden w-64 border-r
                  border-slate-800 bg-slate-950 lg:flex lg:flex-col">

        <div class="flex h-20 items-center border-b border-slate-800 px-6">

            <div class="flex h-9 w-9 items-center justify-center
                        rounded-lg bg-blue-600 font-bold text-white">
                B
            </div>

            <div class="ml-3">
                <div class="font-semibold text-white">
                    BLUE
                </div>

                <div class="text-xs text-slate-500">
                    Admin
                </div>
            </div>

        </div>


        <nav class="flex-1 space-y-1 overflow-y-auto px-4 py-6">

            <a
                href="/admin"
                class="block rounded-lg px-3 py-2.5 text-sm font-medium
                       hover:bg-slate-900 hover:text-white
                       {{ request()->is('admin') ? 'bg-slate-900 text-white' : 'text-slate-300' }}">
                Dashboard
            </a>


            <div class="pt-5">

                <p class="px-3 pb-2 text-xs font-medium uppercase
                          tracking-wider text-slate-600">
                    Operations
                </p>

                <a
                    href="/admin/bookings"
                    class="block rounded-lg px-3 py-2.5 text-sm
                           hover:bg-slate-900 hover:text-white
                           {{ request()->is('admin/bookings*') ? 'bg-slate-900 text-white' : 'text-slate-400' }}">
                    Bookings
                </a>

                <a
                    href="/admin/technicians"
                    class="block rounded-lg px-3 py-2.5 text-sm
                           hover:bg-slate-900 hover:text-white
                           {{ request()->is('admin/technicians*') ? 'bg-slate-900 text-white' : 'text-slate-400' }}">
                    Technicians
                </a>

                <a
                    href="/admin/contracts"
                    class="block rounded-lg px-3 py-2.5 text-sm
                           hover:bg-slate-900 hover:text-white
                           {{ request()->is('admin/contracts*') ? 'bg-slate-900 text-white' : 'text-slate-400' }}">
                    Contracts
                </a>

            </div>


            <div class="pt-5">

                <p class="px-3 pb-2 text-xs font-medium uppercase
                          tracking-wider text-slate-600">
                    Financial
                </p>

                <a
                    href="/admin/payments"
                    class="block rounded-lg px-3 py-2.5 text-sm
                           hover:bg-slate-900 hover:text-white
                           {{ request()->is('admin/payments*') ? 'bg-slate-900 text-white' : 'text-slate-400' }}">
                    Payments
                </a>

                <a
                    href="/admin/billing"
                    class="block rounded-lg px-3 py-2.5 text-sm
                           hover:bg-slate-900 hover:text-white
                           {{ request()->is('admin/billing*') ? 'bg-slate-900 text-white' : 'text-slate-400' }}">
                    Contract Billing
                </a>

                <a
                    href="/admin/pricing"
                    class="block rounded-lg px-3 py-2.5 text-sm
                           hover:bg-slate-900 hover:text-white
                           {{ request()->is('admin/pricing*') ? 'bg-slate-900 text-white' : 'text-slate-400' }}">
                    Pricing
                </a>

            </div>


            <div class="pt-5">

                <p class="px-3 pb-2 text-xs font-medium uppercase
                          tracking-wider text-slate-600">
                    Application
                </p>

                <a
                    href="/admin/customers"
                    class="block rounded-lg px-3 py-2.5 text-sm
                           hover:bg-slate-900 hover:text-white
                           {{ request()->is('admin/customers*') || request()->is('admin/properties*') ? 'bg-slate-900 text-white' : 'text-slate-400' }}">
                    Customers
                </a>

                <a href="#"
                   class="block rounded-lg px-3 py-2.5 text-sm text-slate-400
                          hover:bg-slate-900 hover:text-white">
                    Properties
                </a>

                <a
                    href="/admin/service-categories"
                    class="block rounded-lg px-3 py-2.5 text-sm
                           hover:bg-slate-900 hover:text-white
                           {{ request()->is('admin/service-categories*') || request()->is('admin/services*') ? 'bg-slate-900 text-white' : 'text-slate-400' }}">
                    Services
                </a>

                <a
                    href="/admin/support"
                    class="block rounded-lg px-3 py-2.5 text-sm
                           hover:bg-slate-900 hover:text-white
                           {{ request()->is('admin/support*') ? 'bg-slate-900 text-white' : 'text-slate-400' }}">
                    Support
                </a>

            </div>


            <div class="pt-5">

                <p class="px-3 pb-2 text-xs font-medium uppercase
                          tracking-wider text-slate-600">
                    Security
                </p>

                <a href="#"
                   class="block rounded-lg px-3 py-2.5 text-sm text-slate-400
                          hover:bg-slate-900 hover:text-white">
                    Activity
                </a>

            </div>

        </nav>

    </aside>


    <div class="flex min-h-screen w-full flex-col lg:pl-64">

        <header class="flex h-20 items-center justify-between
                       border-b border-slate-200 bg-white px-6 lg:px-8">

            <div>
                <h1 class="font-semibold text-slate-900">
                    @yield('page-title', 'BLUE Admin')
                </h1>
            </div>

            <div class="flex items-center gap-4">

                <div class="text-right">
                    <p data-admin-name class="text-sm font-medium text-slate-800">
                        Administrator
                    </p>

                    <p data-admin-role class="text-xs text-slate-500">
                        Secure session
                    </p>
                </div>

                <button
                    type="button"
                    data-logout-all
                    title="Sign out of every Admin session"
                    class="rounded-lg border border-transparent px-3 py-2
                           text-sm font-medium text-slate-500
                           hover:bg-slate-50 hover:text-slate-700">
                    Logout all
                </button>

                <button
                    type="button"
                    data-logout
                    class="rounded-lg border border-slate-200 px-3 py-2
                           text-sm font-medium text-slate-700
                           hover:bg-slate-50">
                    Logout
                </button>

            </div>

        </header>


        <main class="flex-1 p-6 lg:p-8">
            @yield('content')
        </main>

    </div>

</div>


<div
    data-step-up-modal
    style="display: none;"
    class="fixed inset-0 z-50 items-center justify-center bg-slate-950/60 p-4">

    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">

        <p class="text-sm font-medium text-blue-600">
            Additional verification required
        </p>

        <h2 class="mt-1 text-lg font-semibold text-slate-950">
            Verify it's you
        </h2>

        <p class="mt-2 text-sm leading-6 text-slate-500">
            This action is sensitive and requires a fresh security key
            verification, even though you are already signed in.
        </p>

        <div
            data-step-up-error
            class="mt-4 hidden rounded-xl border border-red-200
                   bg-red-50 px-4 py-3 text-sm text-red-700">
        </div>

        <div class="mt-6 flex justify-end gap-3">

            <button
                type="button"
                data-step-up-cancel
                class="rounded-xl px-4 py-2.5 text-sm font-medium
                       text-slate-600 hover:bg-slate-50">
                Cancel
            </button>

            <button
                type="button"
                data-step-up-verify
                class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm
                       font-semibold text-white transition
                       hover:bg-slate-800 disabled:cursor-not-allowed
                       disabled:opacity-60">
                Verify with security key
            </button>

        </div>

    </div>

</div>

</body>
</html>
