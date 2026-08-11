<?php

namespace App\Support\Booking;

/**
 * Backend-only generator for bookings.booking_number - opaque, ASCII,
 * unique, 6-40 chars (matching chk_bookings_booking_number), mirroring
 * App\Support\Payment\CheckoutReferenceGenerator's "never accepted from
 * Flutter, never derived from client input" contract. A short "BLU-"
 * prefix plus 10 base32-ish uppercase hex characters keeps it readable
 * enough for customer support conversations while remaining astronomically
 * unlikely to collide - CreateBookingFromSuccessfulPaymentAction still
 * treats the column's UNIQUE constraint as the authoritative backstop, not
 * this randomness alone.
 */
final class BookingNumberGenerator
{
    public static function generate(): string
    {
        return 'BLU-'.strtoupper(bin2hex(random_bytes(5)));
    }
}
