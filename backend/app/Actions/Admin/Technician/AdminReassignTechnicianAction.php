<?php

namespace App\Actions\Admin\Technician;

use App\Actions\Technician\AssignTechnicianToBookingItemAction;
use App\Models\User;
use App\Support\Admin\AdminAssignmentPresenter;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Technician\TechnicianAssignmentOutcome;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Thin Admin transport wrapper around the existing
 * App\Actions\Technician\AssignTechnicianToBookingItemAction::reassign()
 * (BLUE V1 Phase 8A) - see App\Actions\Admin\Technician\AdminAssignTechnicianAction
 * for the shared "never re-implement domain rules" reasoning, which applies
 * identically here. The previous assignment is released (never deleted) by
 * the domain Action itself; this wrapper only maps request/response shape.
 */
final class AdminReassignTechnicianAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly AssignTechnicianToBookingItemAction $action = new AssignTechnicianToBookingItemAction,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request, string $bookingItemUuid, string $technicianUuid, User $actor, string $releaseReason, ?string $internalNote): array
    {
        if (! Str::isUuid($bookingItemUuid)) {
            return $this->notFound('Booking Item not found.');
        }

        if (! Str::isUuid($technicianUuid)) {
            return $this->unprocessable('The selected technician is invalid.', ['technician_uuid' => ['The technician uuid is invalid.']]);
        }

        $result = $this->action->reassign($bookingItemUuid, $technicianUuid, $actor->id, $releaseReason, $internalNote);

        $response = match ($result->outcome) {
            TechnicianAssignmentOutcome::REASSIGNED => $this->ok(200, 'Technician reassigned successfully.', ['assignment' => AdminAssignmentPresenter::present($result->assignmentUuid)]),
            TechnicianAssignmentOutcome::ALREADY_ASSIGNED => $this->ok(200, 'This technician is already the active technician for this Booking Item.', ['assignment' => AdminAssignmentPresenter::present($result->assignmentUuid)]),
            TechnicianAssignmentOutcome::ITEM_NOT_FOUND => $this->notFound('Booking Item not found.'),
            TechnicianAssignmentOutcome::TECHNICIAN_NOT_FOUND => $this->notFound('Technician not found.'),
            TechnicianAssignmentOutcome::ITEM_NOT_ELIGIBLE => $this->conflict('This Booking Item cannot be reassigned from its current status.'),
            TechnicianAssignmentOutcome::NO_ACTIVE_ASSIGNMENT => $this->conflict('This Booking Item has no active technician assignment to replace.'),
            TechnicianAssignmentOutcome::TECHNICIAN_NOT_ELIGIBLE => $this->conflict('This technician is not currently assignable.'),
            TechnicianAssignmentOutcome::TECHNICIAN_DOUBLE_BOOKED => $this->conflict('This technician already has an overlapping assignment for this appointment period.'),
            TechnicianAssignmentOutcome::SERVICE_SPECIALIZATION_NOT_CONFIGURED => $this->unprocessable('This service has no specialization configured yet, so no technician can be assigned.'),
            TechnicianAssignmentOutcome::SPECIALIZATION_MISMATCH => $this->unprocessable('This technician does not hold the specialization required for this service.'),
            TechnicianAssignmentOutcome::ACTOR_NOT_FOUND, TechnicianAssignmentOutcome::ACTOR_NOT_AUTHORIZED => $this->forbidden(),
            TechnicianAssignmentOutcome::ASSIGNED_TO_ANOTHER_TECHNICIAN => $this->conflict('This Booking Item already has an active technician assigned.'),
        };

        if ($result->outcome === TechnicianAssignmentOutcome::REASSIGNED) {
            AdminAuditLogger::record($request, $actor, 'TECHNICIAN_REASSIGNED', 'BOOKING_ITEM', $bookingItemUuid, [
                'technician_uuid' => $result->technicianUuid,
                'assignment_uuid' => $result->assignmentUuid,
                'previous_technician_uuid' => $result->previousTechnicianUuid,
            ]);
        }

        return $response;
    }

    /**
     * @return array{success: bool, status: int, message: string, data: null}
     */
    private function forbidden(): array
    {
        return ['success' => false, 'status' => 403, 'message' => 'You are not authorized to perform this action.', 'data' => null];
    }
}
