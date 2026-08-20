<?php

namespace App\Support\Otp;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Local-development-only OTP delivery: writes one log line so a developer
 * running the app locally (no real SMS provider configured) can complete
 * the phone-verification flow through the real API.
 *
 * This class is never bound outside APP_ENV=local -
 * App\Providers\OtpDeliveryServiceProvider is the one place that guard is
 * enforced, exactly like App\Providers\PaymentServiceProvider guards
 * FakePaymentGateway to APP_ENV=testing only. This class does not re-check
 * the environment itself; it trusts the provider never to construct it
 * anywhere else.
 */
class LocalLogOtpDeliveryChannel implements OtpDeliveryChannel
{
    public function deliver(string $purpose, string $phoneNumber, string $rawOtp, CarbonInterface $expiresAt): void
    {
        Log::info(sprintf(
            '[LOCAL OTP] purpose=%s phone=%s code=%s expires_at=%s',
            $purpose,
            OtpPhoneMasking::mask($phoneNumber),
            $rawOtp,
            $expiresAt->toIso8601String(),
        ));
    }
}
