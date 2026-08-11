<?php

namespace App\Support\Booking;

/**
 * The one return shape of CreateBookingFromSuccessfulPaymentAction::handle().
 * $bookingUuid is set on CREATED and ALREADY_EXISTS; $reason is a short,
 * internal (never Flutter-facing) diagnostic string for every other
 * outcome - logged by callers (the webhook pipeline, the recovery Artisan
 * command), never returned over HTTP.
 */
final readonly class BookingConversionResult
{
    public function __construct(
        public BookingConversionOutcome $outcome,
        public ?string $bookingUuid = null,
        public ?string $reason = null,
    ) {}
}
