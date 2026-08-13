<?php

namespace Tests\Unit\Otp;

use App\Support\Otp\NullOtpDeliveryChannel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class NullOtpDeliveryChannelTest extends TestCase
{
    public function test_it_never_writes_to_the_log(): void
    {
        Log::spy();

        (new NullOtpDeliveryChannel)->deliver('PHONE_VERIFICATION', '+971500000001', '123456', Carbon::now());

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('warning');
    }
}
