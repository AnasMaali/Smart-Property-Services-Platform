<?php

namespace Tests\Feature\Auth;

use App\Support\Otp\LocalLogOtpDeliveryChannel;
use App\Support\Otp\NullOtpDeliveryChannel;
use App\Support\Otp\OtpDeliveryChannel;
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
}
