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

<div class="flex min-h-screen">

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
                class="block rounded-lg px-3 py-2.5 text-sm
                       font-medium text-slate-300
                       hover:bg-slate-900 hover:text-white">
                Dashboard
            </a>


            <div class="pt-5">

                <p class="px-3 pb-2 text-xs font-medium uppercase
                          tracking-wider text-slate-600">
                    Operations
                </p>

                <a href="#"
                   class="block rounded-lg px-3 py-2.5 text-sm text-slate-400
                          hover:bg-slate-900 hover:text-white">
                    Bookings
                </a>

                <a href="#"
                   class="block rounded-lg px-3 py-2.5 text-sm text-slate-400
                          hover:bg-slate-900 hover:text-white">
                    Technicians
                </a>

                <a href="#"
                   class="block rounded-lg px-3 py-2.5 text-sm text-slate-400
                          hover:bg-slate-900 hover:text-white">
                    Contracts
                </a>

            </div>


            <div class="pt-5">

                <p class="px-3 pb-2 text-xs font-medium uppercase
                          tracking-wider text-slate-600">
                    Financial
                </p>

                <a href="#"
                   class="block rounded-lg px-3 py-2.5 text-sm text-slate-400
                          hover:bg-slate-900 hover:text-white">
                    Payments
                </a>

            </div>


            <div class="pt-5">

                <p class="px-3 pb-2 text-xs font-medium uppercase
                          tracking-wider text-slate-600">
                    Application
                </p>

                <a href="#"
                   class="block rounded-lg px-3 py-2.5 text-sm text-slate-400
                          hover:bg-slate-900 hover:text-white">
                    Customers
                </a>

                <a href="#"
                   class="block rounded-lg px-3 py-2.5 text-sm text-slate-400
                          hover:bg-slate-900 hover:text-white">
                    Properties
                </a>

                <a href="#"
                   class="block rounded-lg px-3 py-2.5 text-sm text-slate-400
                          hover:bg-slate-900 hover:text-white">
                    Services
                </a>

                <a href="#"
                   class="block rounded-lg px-3 py-2.5 text-sm text-slate-400
                          hover:bg-slate-900 hover:text-white">
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
                    <p class="text-sm font-medium text-slate-800">
                        Administrator
                    </p>

                    <p class="text-xs text-slate-500">
                        Secure session
                    </p>
                </div>

                <button
                    type="button"
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

</body>
</html>
