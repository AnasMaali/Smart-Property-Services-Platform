<?php

namespace Tests\Feature\Auth;

use App\Support\Otp\LocalLogOtpDeliveryChannel;
use App\Support\Otp\NullOtpDeliveryChannel;
use App\Support\Otp\OtpDeliveryChannel;
use App\Support\Otp\TwilioOtpDeliveryChannel;
use RuntimeException;
use Tests\TestCase;

/**
 * App\Providers\OtpDeliveryServiceProvider is the single place that
 * decides which OtpDeliveryChannel every OTP-issuing Action receives. These
 * tests exercise that decision directly, independent of any HTTP endpoint,
 * proving the gate is structural (config + environment) rather than
 * something an individual Action could get wrong.
 */
class OtpDeliveryServiceProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->app->forgetInstance(OtpDeliveryChannel::class);

        parent::tearDown();
    }

    public function test_default_driver_resolves_to_the_null_channel(): void
    {
        config(['otp.delivery_driver' => 'null']);
        $this->app->forgetInstance(OtpDeliveryChannel::class);

        $this->assertInstanceOf(NullOtpDeliveryChannel::class, $this->app->make(OtpDeliveryChannel::class));
    }

    public function test_log_driver_resolves_to_the_local_log_channel_only_in_local_environment(): void
    {
        config(['otp.delivery_driver' => 'log']);
        $this->app->instance('env', 'local');
        $this->app->forgetInstance(OtpDeliveryChannel::class);

        $this->assertInstanceOf(LocalLogOtpDeliveryChannel::class, $this->app->make(OtpDeliveryChannel::class));

        $this->app->instance('env', 'testing');
    }

    public function test_log_driver_fails_closed_outside_local_environment(): void
    {
        // phpunit.xml always runs this suite under APP_ENV=testing - this
        // proves the provider refuses "log" here rather than silently
        // returning a safe no-op channel.
        config(['otp.delivery_driver' => 'log']);
        $this->app->forgetInstance(OtpDeliveryChannel::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OTP_DELIVERY_DRIVER=log is only permitted when APP_ENV=local');

        $this->app->make(OtpDeliveryChannel::class);
    }

    public function test_unsupported_driver_value_fails_closed(): void
    {
        config(['otp.delivery_driver' => 'sms_provider_that_does_not_exist']);
        $this->app->forgetInstance(OtpDeliveryChannel::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported OTP_DELIVERY_DRIVER config value');

        $this->app->make(OtpDeliveryChannel::class);
    }

    // A production deployment that forgot to configure real OTP delivery
    // must fail loudly at resolution time, not silently accept every OTP
    // request while delivering nothing.
    public function test_null_driver_fails_closed_under_production_environment(): void
    {
        config(['otp.delivery_driver' => 'null']);
        $this->app->instance('env', 'production');
        $this->app->forgetInstance(OtpDeliveryChannel::class);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('OTP_DELIVERY_DRIVER=null is not a valid production configuration');

            $this->app->make(OtpDeliveryChannel::class);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    // Outside production, "null" remains a legal, safe-everywhere default -
    // unchanged by the production-only guard above.
    public function test_null_driver_remains_available_outside_production(): void
    {
        config(['otp.delivery_driver' => 'null']);
        $this->app->forgetInstance(OtpDeliveryChannel::class);

        $this->assertInstanceOf(NullOtpDeliveryChannel::class, $this->app->make(OtpDeliveryChannel::class));
    }

    private function configureTwilio(array $overrides = []): void
    {
        config(array_merge([
            'otp.delivery_driver' => 'twilio',
            'services.twilio.account_sid' => 'AC_test_sid',
            'services.twilio.auth_token' => 'test_auth_token',
            'services.twilio.messaging_service_sid' => 'MG_test_service_sid',
            'services.twilio.from_number' => null,
            'services.twilio.timeout_seconds' => 10,
        ], $overrides));

        $this->app->forgetInstance(OtpDeliveryChannel::class);
    }

    public function test_twilio_driver_resolves_to_the_twilio_channel_when_fully_configured(): void
    {
        $this->configureTwilio();

        $this->assertInstanceOf(TwilioOtpDeliveryChannel::class, $this->app->make(OtpDeliveryChannel::class));
    }

    public function test_twilio_driver_fails_closed_without_account_sid(): void
    {
        $this->configureTwilio(['services.twilio.account_sid' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TWILIO_ACCOUNT_SID');

        $this->app->make(OtpDeliveryChannel::class);
    }

    public function test_twilio_driver_fails_closed_without_auth_token(): void
    {
        $this->configureTwilio(['services.twilio.auth_token' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TWILIO_AUTH_TOKEN');

        $this->app->make(OtpDeliveryChannel::class);
    }

    public function test_twilio_driver_fails_closed_without_any_sender_configured(): void
    {
        $this->configureTwilio(['services.twilio.messaging_service_sid' => null, 'services.twilio.from_number' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TWILIO_MESSAGING_SERVICE_SID or TWILIO_FROM_NUMBER');

        $this->app->make(OtpDeliveryChannel::class);
    }

    public function test_twilio_driver_resolves_using_from_number_when_messaging_service_sid_absent(): void
    {
        $this->configureTwilio(['services.twilio.messaging_service_sid' => null, 'services.twilio.from_number' => '+15005550006']);

        $this->assertInstanceOf(TwilioOtpDeliveryChannel::class, $this->app->make(OtpDeliveryChannel::class));
    }

    // A 0 timeout means "wait forever" to Guzzle - a hung Twilio connection
    // would then block the customer's own HTTP request indefinitely.
    public function test_twilio_driver_fails_closed_with_a_zero_timeout(): void
    {
        $this->configureTwilio(['services.twilio.timeout_seconds' => 0]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TWILIO_TIMEOUT_SECONDS must be a positive integer.');

        $this->app->make(OtpDeliveryChannel::class);
    }

    public function test_twilio_driver_fails_closed_with_a_negative_timeout(): void
    {
        $this->configureTwilio(['services.twilio.timeout_seconds' => -5]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TWILIO_TIMEOUT_SECONDS must be a positive integer.');

        $this->app->make(OtpDeliveryChannel::class);
    }
}
