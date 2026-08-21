<?php

namespace App\Providers;

use App\Support\Otp\LocalLogOtpDeliveryChannel;
use App\Support\Otp\NullOtpDeliveryChannel;
use App\Support\Otp\OtpDeliveryChannel;
use App\Support\Otp\TwilioOtpDeliveryChannel;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * The single place OtpDeliveryChannel is bound, mirroring exactly how
 * PaymentServiceProvider is the single place PaymentGateway is bound and
 * FakePaymentGateway is kept out of every environment except "testing".
 *
 * Here, LocalLogOtpDeliveryChannel (the only channel that ever sees a raw
 * OTP code outside a real provider request) is bound only when both of the
 * following hold:
 *   1. config('otp.delivery_driver') === 'log' (OTP_DELIVERY_DRIVER=log)
 *   2. app()->environment('local') is true
 *
 * "log" set in any environment other than local is a configuration error,
 * not a request to silently fall back to safe behavior - the app refuses
 * to boot rather than risk a raw OTP ever reaching a shared/production log.
 *
 * "null" (the safe-everywhere default) is refused under APP_ENV=production
 * for the same reason, from the other direction: a production deployment
 * that forgot to configure real delivery must fail loudly rather than
 * silently accept every OTP request while delivering nothing.
 */
class OtpDeliveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OtpDeliveryChannel::class, function ($app) {
            $driver = config('otp.delivery_driver', 'null');

            return match ($driver) {
                'null' => $app->environment('production')
                    ? throw new RuntimeException(
                        'OTP_DELIVERY_DRIVER=null is not a valid production configuration. '.
                        'Configure a real delivery driver (e.g. OTP_DELIVERY_DRIVER=twilio) before deploying to production.'
                    )
                    : new NullOtpDeliveryChannel,
                'log' => $app->environment('local')
                    ? new LocalLogOtpDeliveryChannel
                    : throw new RuntimeException(
                        'OTP_DELIVERY_DRIVER=log is only permitted when APP_ENV=local. '.
                        'Refusing to boot with this configuration outside local development.'
                    ),
                'twilio' => $this->makeTwilioChannel(),
                default => throw new RuntimeException("Unsupported OTP_DELIVERY_DRIVER config value: {$driver}"),
            };
        });
    }

    private function makeTwilioChannel(): TwilioOtpDeliveryChannel
    {
        $accountSid = config('services.twilio.account_sid');
        $authToken = config('services.twilio.auth_token');
        $messagingServiceSid = config('services.twilio.messaging_service_sid');
        $fromNumber = config('services.twilio.from_number');

        if (! is_string($accountSid) || $accountSid === '') {
            throw new RuntimeException('OTP_DELIVERY_DRIVER=twilio requires TWILIO_ACCOUNT_SID to be configured.');
        }

        if (! is_string($authToken) || $authToken === '') {
            throw new RuntimeException('OTP_DELIVERY_DRIVER=twilio requires TWILIO_AUTH_TOKEN to be configured.');
        }

        $hasMessagingService = is_string($messagingServiceSid) && $messagingServiceSid !== '';
        $hasFromNumber = is_string($fromNumber) && $fromNumber !== '';

        if (! $hasMessagingService && ! $hasFromNumber) {
            throw new RuntimeException(
                'OTP_DELIVERY_DRIVER=twilio requires either TWILIO_MESSAGING_SERVICE_SID or TWILIO_FROM_NUMBER to be configured.'
            );
        }

        $timeoutSeconds = (int) config('services.twilio.timeout_seconds', 10);

        // Guzzle (Laravel's HTTP client) treats a 0 timeout as "wait
        // forever" - a hung Twilio connection would then block the
        // customer's own HTTP request (and the PHP-FPM worker handling it)
        // indefinitely. A misconfigured TWILIO_TIMEOUT_SECONDS must fail
        // closed the same way a missing credential does, not silently
        // remove the one guard against that.
        if ($timeoutSeconds < 1) {
            throw new RuntimeException('TWILIO_TIMEOUT_SECONDS must be a positive integer.');
        }

        return new TwilioOtpDeliveryChannel(
            accountSid: $accountSid,
            authToken: $authToken,
            messagingServiceSid: $hasMessagingService ? $messagingServiceSid : null,
            fromNumber: $hasMessagingService ? null : $fromNumber,
            timeoutSeconds: $timeoutSeconds,
        );
    }
}
