<?php

namespace App\Actions\Admin\Technician;

use App\Actions\Technician\CompleteTechnicianJobAction;
use App\Models\User;
use App\Support\Admin\AdminAssignmentPresenter;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Technician\TechnicianJobOutcome;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Thin Admin transport wrapper around the existing
 * App\Actions\Technician\CompleteTechnicianJobAction::complete() (BLUE V1
 * Phase 8B) - see App\Actions\Admin\Technician\AdminStartTechnicianJobAction
 * for the shared "Admin acts on the Technician's behalf, active assignment
 * is authoritative" reasoning, which applies identically here. Never
 * accepts completion evidence (photos/signatures/notes) - no schema support
 * exists for it (see the domain Action's own docblock).
 */
final class AdminCompleteTechnicianJobAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly CompleteTechnicianJobAction $action = new CompleteTechnicianJobAction,
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

        $result = $this->action->complete(
            $bookingItemUuid,
            $technicianUuid,
            $actor->id,
            $reason,
            function ($mutation) use ($request, $actor, $bookingItemUuid): void {
                AdminAuditLogger::record(
                    $request,
                    $actor,
                    'BOOKING_ITEM_WORK_COMPLETED',
                    'BOOKING_ITEM',
                    $bookingItemUuid,
                    [
                        'technician_uuid' => $mutation->technicianUuid,
                    ]
                );
            }
        );

        $response = match ($result->outcome) {
            TechnicianJobOutcome::COMPLETED => $this->ok(200, 'Work completed.', ['assignment' => AdminAssignmentPresenter::present($result->assignmentUuid), 'status' => $result->itemStatusTo]),
            TechnicianJobOutcome::ALREADY_COMPLETED => $this->ok(200, 'Work was already completed.', ['assignment' => AdminAssignmentPresenter::present($result->assignmentUuid), 'status' => $result->itemStatusTo]),
            TechnicianJobOutcome::ITEM_NOT_FOUND => $this->notFound('Booking Item not found.'),
            TechnicianJobOutcome::ITEM_NOT_ELIGIBLE => $this->conflict('This Booking Item cannot be completed from its current status.'),
            TechnicianJobOutcome::NO_ACTIVE_ASSIGNMENT => $this->conflict('This Booking Item has no active technician assignment.'),
            TechnicianJobOutcome::ASSIGNMENT_MISMATCH => $this->conflict('This technician is not the currently active technician for this Booking Item.'),
            TechnicianJobOutcome::ACTOR_NOT_FOUND, TechnicianJobOutcome::ACTOR_NOT_AUTHORIZED => $this->forbidden(),
            // complete() never actually returns STARTED/ALREADY_STARTED - kept only for match exhaustiveness over the shared TechnicianJobOutcome enum.
            default => $this->conflict('This Booking Item cannot be completed from its current status.'),
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
