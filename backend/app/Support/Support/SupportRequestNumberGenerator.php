<?php

namespace App\Support\Support;

/**
 * Backend-only generator for support_requests.request_number.
 * ASCII, unique, 6-40 chars (matching chk_support_requests_number).
 */
final class SupportRequestNumberGenerator
{
    public static function generate(): string
    {
        return 'SUP-'.strtoupper(bin2hex(random_bytes(4)));
    }
}
