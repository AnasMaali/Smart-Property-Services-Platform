<?php

namespace App\Support\Payment;

/**
 * Backend-only generator for payment_attempts.checkout_reference - opaque,
 * ASCII, unique, 8-64 chars (matching chk_payment_attempts_checkout_reference).
 * Never accepted from Flutter; never derived from client input. 160 bits of
 * randomness makes a collision with the column's UNIQUE constraint
 * astronomically unlikely, so no retry loop is needed - a collision would
 * indicate a broken RNG, which should fail loudly rather than be masked.
 */
final class CheckoutReferenceGenerator
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(20));
    }
}
