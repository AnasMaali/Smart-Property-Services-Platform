<?php

namespace App\Actions\Admin\Technician;

use App\Actions\Notifications\CreateTechnicianAssignmentNotificationAction;
use App\Actions\Notifications\SendTechnicianNotificationAction;
use App\Actions\Technician\AssignTechnicianToBookingItemAction;
use App\Models\User;
use App\Support\Admin\AdminAssignmentPresenter;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Technician\TechnicianAssignmentOutcome;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Thin Admin transport wrapper around the existing, already-tested
 * App\Actions\Technician\AssignTechnicianToBookingItemAction::assign()
 * (BLUE V1 Phase 8A) - never re-implements eligibility, specialization
 * matching, double-booking detection, item-status validation, idempotency,
 * or DB race handling; only maps the request to the Action's signature and
 * the Action's outcome to an HTTP-shaped result. The actor is always the
 * `auth.admin`-resolved caller, never a request field (see
 * App\Http\Controllers\Api\V1\Admin\Technician\AssignTechnicianController).
 *
 * BLUE V1 Phase B21 - a genuine new assignment also queues exactly one
 * WhatsApp NEW_ASSIGNMENT notification obligation, written inside the SAME
 * transaction as the assignment itself (via the existing $afterMutation
 * hook - AssignTechnicianToBookingItemAction itself stays entirely
 * unaware of WhatsApp/Meta). The best-effort synchronous send happens
 * strictly AFTER that transaction has committed - a WhatsApp failure
 * here can never roll back or otherwise affect the assignment that
 * already safely committed; the obligation remains PENDING and
 * recoverable via `php artisan notifications:send-pending`.
 */
final class AdminAssignTechnicianAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly SendTechnicianNotificationAction $sendNotification,
        private readonly AssignTechnicianToBookingItemAction $action = new AssignTechnicianToBookingItemAction,
        private readonly CreateTechnicianAssignmentNotificationAction $createNotification = new CreateTechnicianAssignmentNotificationAction,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request, string $bookingItemUuid, string $technicianUuid, User $actor, ?string $internalNote): array
    {
        if (! Str::isUuid($bookingItemUuid)) {
            return $this->notFound('Booking Item not found.');
        }

        if (! Str::isUuid($technicianUuid)) {
            return $this->unprocessable('The selected technician is invalid.', ['technician_uuid' => ['The technician uuid is invalid.']]);
        }

        $notificationUuid = null;

        $result = $this->action->assign(
            $bookingItemUuid,
            $technicianUuid,
            $actor->id,
            $internalNote,
            function ($mutation) use ($request, $actor, $bookingItemUuid, &$notificationUuid): void {
                AdminAuditLogger::record(
                    $request,
                    $actor,
                    'TECHNICIAN_ASSIGNED',
                    'BOOKING_ITEM',
                    $bookingItemUuid,
                    [
                        'technician_uuid' => $mutation->technicianUuid,
                        'assignment_uuid' => $mutation->assignmentUuid,
                    ]
                );

                $notificationUuid = $this->createNotification->createForNewAssignment($mutation->assignmentUuid);
            }
        );

        if ($notificationUuid !== null) {
            try {
                $this->sendNotification->handle($notificationUuid);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $response = match ($result->outcome) {
            TechnicianAssignmentOutcome::ASSIGNED => $this->ok(201, 'Technician assigned successfully.', ['assignment' => AdminAssignmentPresenter::present($result->assignmentUuid)]),
            TechnicianAssignmentOutcome::ALREADY_ASSIGNED => $this->ok(200, 'This technician is already assigned to this Booking Item.', ['assignment' => AdminAssignmentPresenter::present($result->assignmentUuid)]),
            TechnicianAssignmentOutcome::ITEM_NOT_FOUND => $this->notFound('Booking Item not found.'),
            TechnicianAssignmentOutcome::TECHNICIAN_NOT_FOUND => $this->notFound('Technician not found.'),
            TechnicianAssignmentOutcome::ITEM_NOT_ELIGIBLE => $this->conflict('This Booking Item cannot be assigned from its current status.'),
            TechnicianAssignmentOutcome::ASSIGNED_TO_ANOTHER_TECHNICIAN => $this->conflict('This Booking Item already has an active technician assigned. Use reassign instead.'),
            TechnicianAssignmentOutcome::TECHNICIAN_NOT_ELIGIBLE => $this->conflict('This technician is not currently assignable.'),
            TechnicianAssignmentOutcome::TECHNICIAN_DOUBLE_BOOKED => $this->conflict('This technician already has an overlapping assignment for this appointment period.'),
            TechnicianAssignmentOutcome::SERVICE_SPECIALIZATION_NOT_CONFIGURED => $this->unprocessable('This service has no specialization configured yet, so no technician can be assigned.'),
            TechnicianAssignmentOutcome::SPECIALIZATION_MISMATCH => $this->unprocessable('This technician does not hold the specialization required for this service.'),
            TechnicianAssignmentOutcome::ACTOR_NOT_FOUND, TechnicianAssignmentOutcome::ACTOR_NOT_AUTHORIZED => $this->forbidden(),
            TechnicianAssignmentOutcome::NO_ACTIVE_ASSIGNMENT => $this->conflict('This Booking Item has no active technician assignment.'),
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
