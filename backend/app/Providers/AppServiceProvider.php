<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Public authentication rate limits
        |--------------------------------------------------------------------------
        |
        | Domain-level OTP/session protections still remain authoritative:
        | OTP expiry, max attempts, resend cooldown, reset-token single use,
        | refresh-token rotation, etc.
        |
        | These limits are an additional HTTP abuse / flooding boundary.
        |
        | User-supplied identifiers are hashed before becoming cache keys so
        | phone numbers / OTP UUIDs are not stored verbatim in limiter keys.
        |
        */

        $ipKey = static function (Request $request): string {
            return hash('sha256', (string) ($request->ip() ?? 'unknown'));
        };

        $identityKey = static function (Request $request, array $fields): string {
            $identity = 'anonymous';

            foreach ($fields as $field) {
                $value = $request->input($field);

                if (is_string($value) && trim($value) !== '') {
                    $identity = trim($value);
                    break;
                }
            }

            // Deliberately independent of the source IP: this bucket follows
            // the target account/OTP identity across distributed requests.
            // A separate IP bucket below limits identifier rotation from one
            // source. The raw identifier is never stored in the cache key.
            return hash('sha256', $identity);
        };

        // Brute-force protection for Customer login.
        RateLimiter::for('auth-login', function (Request $request) use ($identityKey, $ipKey): array {
            return [
                Limit::perMinute(5)
                    ->by('auth-login-identity:'.$identityKey($request, ['phone_number'])),

                // Prevent rotating through many phone numbers from one IP.
                Limit::perMinute(30)
                    ->by('auth-login-ip:'.$ipKey($request)),
            ];
        });

        // Account-creation flooding protection.
        RateLimiter::for('auth-register', function (Request $request) use ($identityKey, $ipKey): array {
            return [
                Limit::perMinute(5)
                    ->by('auth-register-identity:'.$identityKey($request, ['phone_number'])),

                Limit::perMinute(20)
                    ->by('auth-register-ip:'.$ipKey($request)),
            ];
        });

        // OTP issuance/resend protection. Domain cooldown remains in force.
        RateLimiter::for('auth-otp-issue', function (Request $request) use ($identityKey, $ipKey): array {
            return [
                Limit::perMinute(5)
                    ->by('auth-otp-issue-identity:'.$identityKey(
                        $request,
                        ['phone_number', 'otp_uuid']
                    )),

                Limit::perMinute(30)
                    ->by('auth-otp-issue-ip:'.$ipKey($request)),
            ];
        });

        // OTP verification flooding protection. The OTP's own max_attempts
        // remains the stronger per-OTP domain rule.
        RateLimiter::for('auth-otp-verify', function (Request $request) use ($identityKey, $ipKey): array {
            return [
                Limit::perMinute(10)
                    ->by('auth-otp-verify-identity:'.$identityKey(
                        $request,
                        ['phone_number', 'otp_uuid']
                    )),

                Limit::perMinute(60)
                    ->by('auth-otp-verify-ip:'.$ipKey($request)),
            ];
        });

        // Refresh tokens are deliberately NOT included in limiter keys.
        RateLimiter::for('auth-refresh', function (Request $request) use ($ipKey): Limit {
            return Limit::perMinute(60)
                ->by('auth-refresh-ip:'.$ipKey($request));
        });

        // Reset tokens are deliberately NOT included in limiter keys.
        RateLimiter::for('auth-reset', function (Request $request) use ($ipKey): Limit {
            return Limit::perMinute(10)
                ->by('auth-reset-ip:'.$ipKey($request));
        });
    }
}
