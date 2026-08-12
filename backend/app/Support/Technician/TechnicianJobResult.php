<?php

namespace App\Support\Technician;

/**
 * The one return shape of every App\Actions\Technician\StartTechnicianJobAction
 * and App\Actions\Technician\CompleteTechnicianJobAction call (BLUE V1 Phase
 * 8B). Every uuid here is a standard UUID string, never a raw binary(16) id -
 * mirrors App\Support\Technician\TechnicianAssignmentResult /
 * App\Support\Booking\BookingLifecycleTransitionResult.
 */
final readonly class TechnicianJobResult
{
    public function __construct(
        public TechnicianJobOutcome $outcome,
        public ?string $bookingItemUuid = null,
        public ?string $technicianUuid = null,
        public ?string $assignmentUuid = null,
        public ?string $itemStatusFrom = null,
        public ?string $itemStatusTo = null,
    ) {}
}
