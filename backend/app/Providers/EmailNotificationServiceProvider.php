<?php

namespace App\Providers;

use App\Support\Notifications\Gateway\EmailNotificationGateway;
use App\Support\Notifications\Gateway\FakeEmailNotificationGateway;
use App\Support\Notifications\Gateway\LaravelMailEmailNotificationGateway;
use Illuminate\Support\ServiceProvider;

/**
 * The single place EmailNotificationGateway is bound - mirrors
 * App\Providers\TechnicianNotificationServiceProvider (testing environment
 * always gets the fake, regardless of config). Unlike WhatsApp, no driver
 * switch or eager credential validation is needed here: delivery always
 * goes through Laravel's own Mail facade, which already validates its own
 * MAIL_MAILER-selected transport configuration.
 */
class EmailNotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EmailNotificationGateway::class, function ($app) {
            if ($app->environment('testing')) {
                return new FakeEmailNotificationGateway;
            }

            return new LaravelMailEmailNotificationGateway;
        });
    }
}
