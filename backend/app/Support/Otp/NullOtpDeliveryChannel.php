<?php

namespace App\Support\Otp;

use Carbon\CarbonInterface;

/**
 * The default, production-safe channel: does nothing. This preserves BLUE
 * V1's original behavior exactly (the raw OTP existed only in memory and
 * was discarded) for every environment that has not explicitly opted into
 * local log delivery - see App\Providers\OtpDeliveryServiceProvider.
 */
class NullOtpDeliveryChannel implements OtpDeliveryChannel
{
    public function deliver(string $purpose, string $phoneNumber, string $rawOtp, CarbonInterface $expiresAt): void
    {
        // Intentionally empty - no SMS provider exists yet, so there is
        // nothing to deliver to. The raw OTP is discarded by the caller
        // immediately after this call returns.
    }
}
