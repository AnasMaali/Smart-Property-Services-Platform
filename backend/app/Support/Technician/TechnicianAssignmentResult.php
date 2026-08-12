<?php

namespace App\Support\Technician;

/**
 * The one return shape of every
 * App\Actions\Technician\AssignTechnicianToBookingItemAction method
 * (BLUE V1 Phase 8A). Every uuid here is a standard UUID string, never a raw
 * binary(16) id - mirrors App\Support\Booking\BookingLifecycleTransitionResult.
 */
final readonly class TechnicianAssignmentResult
{
    public function __construct(
        public TechnicianAssignmentOutcome $outcome,
        public ?string $assignmentUuid = null,
        public ?string $bookingItemUuid = null,
        public ?string $technicianUuid = null,
        public ?string $previousTechnicianUuid = null,
        public ?string $itemStatusFrom = null,
        public ?string $itemStatusTo = null,
    ) {}
}
