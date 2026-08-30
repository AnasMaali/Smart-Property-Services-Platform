<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="robots" content="noindex,nofollow,noarchive">

    <title>BLUE Admin</title>

    @vite([
        'resources/css/admin.css',
        'resources/js/admin/app.js',
    ])
</head>

<body class="min-h-screen bg-slate-950 text-slate-100">

<div class="flex min-h-screen">

    <section class="hidden lg:flex lg:w-1/2 relative overflow-hidden bg-slate-950">

        <div class="absolute inset-0
                    bg-[radial-gradient(circle_at_top_left,rgba(59,130,246,0.18),transparent_45%)]">
        </div>

        <div class="relative flex flex-col justify-between p-14 xl:p-20">

            <div>
                <div class="inline-flex items-center gap-3">
                    <div class="flex h-11 w-11 items-center justify-center
                                rounded-xl bg-blue-600 font-bold text-white">
                        B
                    </div>

                    <div>
                        <div class="text-xl font-semibold tracking-tight">
                            BLUE
                        </div>

                        <div class="text-xs text-slate-400">
                            Administration
                        </div>
                    </div>
                </div>
            </div>

            <div class="max-w-lg">
                <p class="mb-4 text-sm font-medium uppercase tracking-[0.25em] text-blue-400">
                    Secure Operations
                </p>

                <h1 class="text-4xl font-semibold leading-tight tracking-tight xl:text-5xl">
                    Control BLUE from one secure workspace.
                </h1>

                <p class="mt-6 max-w-md text-base leading-7 text-slate-400">
                    Manage bookings, technicians, contracts, payments and application operations
                    through BLUE's protected Admin environment.
                </p>
            </div>

            <p class="text-xs text-slate-500">
                Private administrative access
            </p>

        </div>
    </section>


    <main class="flex w-full items-center justify-center bg-slate-50 px-6 py-12 lg:w-1/2">

        <div class="w-full max-w-md">

            <div class="mb-10 lg:hidden">
                <div class="inline-flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center
                                rounded-xl bg-blue-600 font-bold text-white">
                        B
                    </div>

                    <span class="text-lg font-semibold text-slate-950">
                        BLUE Admin
                    </span>
                </div>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-8 shadow-sm sm:p-10">

                <div class="mb-8">

                    <p class="mb-2 text-sm font-medium text-blue-600">
                        Administrator access
                    </p>

                    <h2 class="text-3xl font-semibold tracking-tight text-slate-950">
                        Sign in
                    </h2>

                    <p class="mt-3 text-sm leading-6 text-slate-500">
                        Enter your Admin credentials to continue.
                        Additional WebAuthn verification is required.
                    </p>

                </div>


                <div
                    data-login-error
                    class="mb-5 hidden rounded-xl border border-red-200
                           bg-red-50 px-4 py-3 text-sm text-red-700">
                </div>


                <div
                    data-login-status
                    class="mb-5 hidden rounded-xl border border-blue-200
                           bg-blue-50 px-4 py-3 text-sm text-blue-700">
                </div>


                <form data-admin-login-form class="space-y-5">

                    <div>
                        <label
                            for="phone_number"
                            class="mb-2 block text-sm font-medium text-slate-700">
                            Phone number
                        </label>

                        <input
                            id="phone_number"
                            name="phone_number"
                            type="tel"
                            required
                            autocomplete="username"
                            placeholder="+971..."
                            class="w-full rounded-xl border border-slate-300
                                   bg-white px-4 py-3 text-sm text-slate-950
                                   outline-none transition
                                   placeholder:text-slate-400
                                   focus:border-blue-500
                                   focus:ring-4 focus:ring-blue-100">
                    </div>


                    <div>
                        <label
                            for="password"
                            class="mb-2 block text-sm font-medium text-slate-700">
                            Password
                        </label>

                        <input
                            id="password"
                            name="password"
                            type="password"
                            required
                            autocomplete="current-password"
                            class="w-full rounded-xl border border-slate-300
                                   bg-white px-4 py-3 text-sm text-slate-950
                                   outline-none transition
                                   focus:border-blue-500
                                   focus:ring-4 focus:ring-blue-100">
                    </div>


                    <button
                        data-submit
                        type="submit"
                        class="mt-2 flex w-full items-center justify-center
                               rounded-xl bg-slate-950 px-5 py-3.5
                               text-sm font-semibold text-white
                               transition hover:bg-slate-800
                               disabled:cursor-not-allowed
                               disabled:opacity-60">

                        Sign in
                    </button>

                </form>


                <div class="mt-7 border-t border-slate-100 pt-6">

                    <div class="flex items-start gap-3">

                        <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center
                                    justify-center rounded-lg bg-emerald-50 text-emerald-600">
                            ✓
                        </div>

                        <p class="text-xs leading-5 text-slate-500">
                            This Admin environment requires password authentication
                            followed by WebAuthn verification.
                        </p>

                    </div>

                </div>

            </div>

        </div>

    </main>

</div>

</body>
</html>
