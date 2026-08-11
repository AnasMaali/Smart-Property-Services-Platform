<?php

namespace App\Support\Booking;

/**
 * The one return shape of every Phase 7B lifecycle transition method
 * (TransitionBookingStatusAction / TransitionBookingItemStatusAction).
 * $fromStatus/$toStatus are status *codes* (e.g. "PAID", "COMPLETED"),
 * never numeric ids - nothing in Phase 7B ever hands a raw status_id to a
 * caller.
 */
final readonly class BookingLifecycleTransitionResult
{
    public function __construct(
        public BookingLifecycleOutcome $outcome,
        public ?string $fromStatus = null,
        public ?string $toStatus = null,
    ) {}
}
