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

        // Brute-force protection for Admin/Super Admin login. Keep the
        // identity bucket independent of source IP so rotating through
        // multiple IP addresses cannot bypass protection for one account.
        // The separate IP bucket also limits phone-number enumeration and
        // distributed credential spraying from one source.
        RateLimiter::for('admin-auth-login', function (Request $request) use ($identityKey, $ipKey): array {
            return [
                Limit::perMinute(5)
                    ->by('admin-auth-login-identity:'.$identityKey($request, ['phone_number'])),

                Limit::perMinute(20)
                    ->by('admin-auth-login-ip:'.$ipKey($request)),
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

        // The authenticated customer's own identity, hashed the same way the
        // anonymous $identityKey above hashes a request-supplied field - used
        // for the phone-number-change OTP limiters below, where the caller
        // is already authenticated so there is no anonymous body field
        // (new_phone_number / otp_verification_uuid) worth keying on
        // instead. Every route these limiters attach to sits behind
        // auth.customer, which runs first and sets this attribute before
        // the throttle middleware ever evaluates; the 'anonymous' fallback
        // only guards against that assumption ever changing, collapsing to
        // one shared bucket rather than erroring.
        $authenticatedIdentityKey = static function (Request $request): string {
            $authUser = $request->attributes->get('auth_user');

            return hash('sha256', (string) ($authUser->id ?? 'anonymous'));
        };

        // Authenticated phone-number-change OTP issuance/resend protection
        // (POST /v1/auth/change-phone-number and /resend-phone-number-change-otp)
        // - same dual identity+IP shape and limits as the public
        // auth-otp-issue limiter above; the OTP row's own 60-second resend
        // cooldown remains the stronger domain rule for legitimate retries.
        RateLimiter::for('auth-phone-change-issue', function (Request $request) use ($authenticatedIdentityKey, $ipKey): array {
            return [
                Limit::perMinute(5)
                    ->by('auth-phone-change-issue-identity:'.$authenticatedIdentityKey($request)),

                Limit::perMinute(30)
                    ->by('auth-phone-change-issue-ip:'.$ipKey($request)),
            ];
        });

        // Authenticated phone-number-change OTP verification flooding
        // protection (POST /v1/auth/verify-phone-number-change-otp) - the
        // OTP's own max_attempts remains the stronger per-OTP domain rule.
        RateLimiter::for('auth-phone-change-verify', function (Request $request) use ($authenticatedIdentityKey, $ipKey): array {
            return [
                Limit::perMinute(10)
                    ->by('auth-phone-change-verify-identity:'.$authenticatedIdentityKey($request)),

                Limit::perMinute(60)
                    ->by('auth-phone-change-verify-ip:'.$ipKey($request)),
            ];
        });

        // Account deletion (DELETE /v1/auth/account) - deliberately its own,
        // low-frequency limiter rather than reusing an OTP one: this isn't
        // an OTP flow, but it is a low-frequency, password-guarded,
        // irreversible action worth its own tight identity+IP boundary
        // against automated repeated-attempt abuse.
        RateLimiter::for('auth-account-delete', function (Request $request) use ($authenticatedIdentityKey, $ipKey): array {
            return [
                Limit::perMinute(5)
                    ->by('auth-account-delete-identity:'.$authenticatedIdentityKey($request)),

                Limit::perMinute(20)
                    ->by('auth-account-delete-ip:'.$ipKey($request)),
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
