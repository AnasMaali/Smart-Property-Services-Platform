<?php

namespace App\Support\Otp;

/**
 * Shared "last 4 digits visible" masking used everywhere an OTP delivery
 * channel needs to reference a phone number outside the request/response
 * cycle (a log line, a reported delivery-failure message) - never the full
 * number, even in a channel-local or otherwise trusted log.
 */
final class OtpPhoneMasking
{
    public static function mask(string $phoneNumber): string
    {
        $visibleLength = 4;

        if (mb_strlen($phoneNumber) <= $visibleLength) {
            return $phoneNumber;
        }

        $visible = mb_substr($phoneNumber, -$visibleLength);
        $maskedLength = mb_strlen($phoneNumber) - $visibleLength;

        return str_repeat('*', $maskedLength).$visible;
    }
}
