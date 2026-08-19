<?php

namespace App\Actions\Admin\Technician;

use App\Actions\Technician\StartTechnicianJobAction;
use App\Models\User;
use App\Support\Admin\AdminAssignmentPresenter;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Technician\TechnicianJobOutcome;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Thin Admin transport wrapper around the existing
 * App\Actions\Technician\StartTechnicianJobAction::start() (BLUE V1 Phase
 * 8B). The Admin acts on behalf of the Technician
 * (docs/03-features-and-requirements/07-technician-assignment.md - Technician
 * accounts do not exist in Version 1), so `technician_uuid` is a caller
 * *claim*, not proof of identity - the domain Action itself is what verifies
 * it against the Booking Item's actual active assignment
 * (App\Actions\Technician\Concerns\TechnicianJobPreconditions). This wrapper
 * never re-derives or bypasses that check; it only validates UUID syntax
 * before calling in, and maps the outcome to HTTP.
 */
final class AdminStartTechnicianJobAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly StartTechnicianJobAction $action = new StartTechnicianJobAction,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request, string $bookingItemUuid, string $technicianUuid, User $actor, ?string $reason): array
    {
        if (! Str::isUuid($bookingItemUuid)) {
            return $this->notFound('Booking Item not found.');
        }

        if (! Str::isUuid($technicianUuid)) {
            return $this->unprocessable('The selected technician is invalid.', ['technician_uuid' => ['The technician uuid is invalid.']]);
        }

        $result = $this->action->start(
            $bookingItemUuid,
            $technicianUuid,
            $actor->id,
            $reason,
            function ($mutation) use ($request, $actor, $bookingItemUuid): void {
                AdminAuditLogger::record(
                    $request,
                    $actor,
                    'BOOKING_ITEM_WORK_STARTED',
                    'BOOKING_ITEM',
                    $bookingItemUuid,
                    [
                        'technician_uuid' => $mutation->technicianUuid,
                    ]
                );
            }
        );

        $response = match ($result->outcome) {
            TechnicianJobOutcome::STARTED => $this->ok(200, 'Work started.', ['assignment' => AdminAssignmentPresenter::present($result->assignmentUuid), 'status' => $result->itemStatusTo]),
            TechnicianJobOutcome::ALREADY_STARTED => $this->ok(200, 'Work was already started.', ['assignment' => AdminAssignmentPresenter::present($result->assignmentUuid), 'status' => $result->itemStatusTo]),
            TechnicianJobOutcome::ITEM_NOT_FOUND => $this->notFound('Booking Item not found.'),
            TechnicianJobOutcome::ITEM_NOT_ELIGIBLE => $this->conflict('This Booking Item cannot start work from its current status.'),
            TechnicianJobOutcome::NO_ACTIVE_ASSIGNMENT => $this->conflict('This Booking Item has no active technician assignment.'),
            TechnicianJobOutcome::ASSIGNMENT_MISMATCH => $this->conflict('This technician is not the currently active technician for this Booking Item.'),
            TechnicianJobOutcome::ACTOR_NOT_FOUND, TechnicianJobOutcome::ACTOR_NOT_AUTHORIZED => $this->forbidden(),
            // start() never actually returns COMPLETED/ALREADY_COMPLETED - kept only for match exhaustiveness over the shared TechnicianJobOutcome enum.
            default => $this->conflict('This Booking Item cannot start work from its current status.'),
        };

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
