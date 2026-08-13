<?php

namespace App\Providers;

use App\Support\Otp\LocalLogOtpDeliveryChannel;
use App\Support\Otp\NullOtpDeliveryChannel;
use App\Support\Otp\OtpDeliveryChannel;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * The single place OtpDeliveryChannel is bound, mirroring exactly how
 * PaymentServiceProvider is the single place PaymentGateway is bound and
 * FakePaymentGateway is kept out of every environment except "testing".
 *
 * Here, LocalLogOtpDeliveryChannel (the only channel that ever sees a raw
 * OTP code) is bound only when both of the following hold:
 *   1. config('otp.delivery_driver') === 'log' (OTP_DELIVERY_DRIVER=log)
 *   2. app()->environment('local') is true
 *
 * "log" set in any environment other than local is a configuration error,
 * not a request to silently fall back to safe behavior - the app refuses
 * to boot rather than risk a raw OTP ever reaching a shared/production log.
 */
class OtpDeliveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OtpDeliveryChannel::class, function ($app) {
            $driver = config('otp.delivery_driver', 'null');

            return match ($driver) {
                'null' => new NullOtpDeliveryChannel,
                'log' => $app->environment('local')
                    ? new LocalLogOtpDeliveryChannel
                    : throw new RuntimeException(
                        'OTP_DELIVERY_DRIVER=log is only permitted when APP_ENV=local. '.
                        'Refusing to boot with this configuration outside local development.'
                    ),
                default => throw new RuntimeException("Unsupported OTP_DELIVERY_DRIVER config value: {$driver}"),
            };
        });
    }
}
