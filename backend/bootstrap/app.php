<?php

use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Middleware\AuthenticateCustomer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.customer' => AuthenticateCustomer::class,
            'auth.admin' => AuthenticateAdmin::class,
        ]);

        // Left unset (the safe default), Laravel trusts no proxy at all, so
        // $request->ip() - used throughout rate limiting
        // (App\Providers\AppServiceProvider) and Admin audit logging
        // (App\Support\Admin\AdminAuditLogger) - is always the raw TCP peer
        // address and X-Forwarded-For is never honored, so a direct client
        // can never spoof it. When BLUE V1 runs behind a real reverse
        // proxy/load balancer, TRUSTED_PROXIES must name only that proxy's
        // actual IP(s)/CIDR(s) (comma-separated) - or "*" only if the
        // deployment genuinely has no stable proxy IP to pin to (e.g. a CDN
        // with rotating edge IPs) - so $request->ip() again reports the
        // real client instead of the proxy.
        $trustedProxies = trim((string) env('TRUSTED_PROXIES', ''));

        if ($trustedProxies !== '') {
            $middleware->trustProxies(
                at: $trustedProxies === '*'
                    ? '*'
                    : array_values(array_filter(array_map('trim', explode(',', $trustedProxies)))),
            );
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
