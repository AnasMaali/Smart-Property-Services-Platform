<?php

namespace App\Support\WhatsApp;

/**
 * BLUE V1 Simple WhatsApp Handoff - builds the `https://wa.me/<number>
 * ?text=<encoded>` link the Admin Booking Workspace opens in a new tab so
 * an Admin can review and manually press Send inside WhatsApp Web/App.
 * Deliberately the ONLY place a wa.me URL is assembled - never duplicated
 * in JavaScript, so the message text can never be recomputed (or
 * tampered with) client-side.
 *
 * `rawurlencode()` (RFC 3986 percent-encoding) is used rather than
 * `urlencode()` so line breaks (`\n` -> `%0A`) and any Arabic/English
 * UTF-8 text survive intact and WhatsApp still renders them as real line
 * breaks - `urlencode()`'s `+`-for-space form is a form-encoding
 * convention wa.me does not reliably honor.
 */
final class WhatsAppLinkBuilder
{
    /**
     * @return array{message: string, url: string}|null `null` when the
     *                                                  phone number is
     *                                                  missing or not a
     *                                                  valid E.164
     *                                                  number - the
     *                                                  caller must treat
     *                                                  this as "hide/
     *                                                  disable the
     *                                                  action", never as
     *                                                  a reason to fail
     *                                                  Booking rendering.
     */
    public static function build(?string $phone, string $message): ?array
    {
        $digits = WhatsAppPhoneNormalizer::toWaMeDigits($phone);

        if ($digits === null) {
            return null;
        }

        return [
            'message' => $message,
            'url' => 'https://wa.me/'.$digits.'?text='.rawurlencode($message),
        ];
    }
}
