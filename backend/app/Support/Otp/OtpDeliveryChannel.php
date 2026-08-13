<?php

namespace App\Support\Otp;

use Carbon\CarbonInterface;

/**
 * The one boundary between an OTP-issuing Action and whatever happens to
 * the raw OTP code after it is generated and hashed. No Action ever knows
 * which concrete channel is bound - see App\Providers\OtpDeliveryServiceProvider
 * for the only place that decision is made.
 *
 * Every implementation must be safe to call with a real raw OTP: the raw
 * code passed here is the one value in this codebase that must never be
 * persisted, cached, or returned by any API response (see
 * docs/api-contracts/authentication-v1.md).
 */
interface OtpDeliveryChannel
{
    public function deliver(string $purpose, string $phoneNumber, string $rawOtp, CarbonInterface $expiresAt): void;
}
