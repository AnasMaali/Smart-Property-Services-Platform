<?php

namespace App\Providers;

use App\Support\Payment\Gateway\FakePaymentGateway;
use App\Support\Payment\Gateway\PaymentGateway;
use App\Support\Payment\Gateway\StripePaymentGateway;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * The single place PaymentGateway is bound. FakePaymentGateway is bound
 * only under the "testing" environment (see phpunit.xml APP_ENV=testing) -
 * this is the one guard that keeps the fake gateway out of every other
 * environment, so a misconfigured PAYMENT_PROVIDER can never accidentally
 * fall back to a fake successful payment outside of tests.
 */
class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, function ($app) {
            if ($app->environment('testing')) {
                return new FakePaymentGateway;
            }

            return match (config('payment.provider')) {
                'stripe' => new StripePaymentGateway(
                    config('services.stripe.secret_key'),
                    config('services.stripe.webhook_secret'),
                ),
                default => throw new RuntimeException('Unsupported PAYMENT_PROVIDER config value: '.config('payment.provider')),
            };
        });
    }
}
