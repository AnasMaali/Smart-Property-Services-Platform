<?php

use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Middleware\AuthenticateCustomer;
use App\Http\Middleware\EnsureAdminHasCapability;
use App\Http\Middleware\EnsureAdminStepUpIsFresh;
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
            // BLUE V1 Phase A1 - Admin capability authorization gate. Must
            // always run AFTER auth.admin on a route (it only reads the
            // auth_admin_roles attribute auth.admin attaches) - see
            // EnsureAdminHasCapability's own docblock.
            'admin.capability' => EnsureAdminHasCapability::class,
            // BLUE V1 Phase A2.5 - WebAuthn step-up freshness gate. Must
            // always run AFTER auth.admin (reads the auth_session attribute
            // it attaches) and, on any given route, AFTER admin.capability
            // (step-up proves identity freshness, not authorization) - see
            // EnsureAdminStepUpIsFresh's own docblock.
            'admin.stepup' => EnsureAdminStepUpIsFresh::class,
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

        // QueryException::getMessage() interpolates SQL bindings. BLUE
        // uses binary(16) UUIDs, so a failed query's message contains raw
        // bytes that are not valid UTF-8. Laravel's debug JSON renderer
        // then throws InvalidArgumentException ("Malformed UTF-8") and
        // hides the real SQLSTATE. Sanitize only that message so the
        // original error remains readable; do not substitute on success
        // payloads.
        $exceptions->render(function (\Illuminate\Database\QueryException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $message = $e->getMessage();

            if (mb_check_encoding($message, 'UTF-8')) {
                return null;
            }

            $safeMessage = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $message) ?? 'Server Error';

            return response()->json(
                config('app.debug')
                    ? [
                        'message' => $safeMessage,
                        'exception' => $e::class,
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]
                    : ['message' => 'Server Error'],
                500
            );
        });
    })->create();
