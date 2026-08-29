<?php

namespace App\Support\WhatsApp;

/**
 * BLUE V1 Simple WhatsApp Handoff - the one place an E.164 phone number
 * (BLUE's existing canonical storage format for `technicians.phone_number`
 * and `users.phone_number`) is converted to the plain-digits form
 * wa.me requires (no leading "+", no spaces/dashes). Never invents a
 * country code for a malformed number - an input that does not already
 * match E.164 simply yields `null`, so App\Support\WhatsApp\
 * WhatsAppLinkBuilder can safely refuse to build a link rather than
 * guess.
 */
final class WhatsAppPhoneNormalizer
{
    private const E164_PATTERN = '/^\+[1-9]\d{7,14}$/';

    public static function toWaMeDigits(?string $phone): ?string
    {
        if ($phone === null || ! preg_match(self::E164_PATTERN, $phone)) {
            return null;
        }

        return substr($phone, 1);
    }
}
