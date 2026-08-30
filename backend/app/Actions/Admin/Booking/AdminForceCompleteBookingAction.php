<?php

namespace App\Actions\Admin\Booking;

use App\Actions\Booking\TransitionBookingItemStatusAction;
use App\Actions\Booking\TransitionBookingStatusAction;
use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Booking\BookingItemStatuses;
use App\Support\Booking\BookingStatuses;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Admin "Force Complete" (BLUE V1 Phase B17) - a break-glass operational
 * recovery override, NOT a substitute for normal technician Complete Work
 * (App\Actions\Technician\CompleteTechnicianJobAction remains the canonical
 * path and is left entirely untouched).
 *
 * Eligibility (reject rather than guess on anything else):
 * - Booking CANCELLED -> 409, never converted to COMPLETED.
 * - Booking already COMPLETED -> idempotent 200, no audit, nothing written.
 * - Any Booking Item CANCELLED -> 409. A CANCELLED Booking Item can never
 *   become COMPLETED, and App\Actions\Booking\SyncBookingStatusFromItemsAction's
 *   own "every item COMPLETED" invariant already treats this combination as
 *   permanently unreachable via the normal path - so it is rejected here
 *   too, never forced.
 * - Any Booking Item still PENDING_ASSIGNMENT or ASSIGNED (work never
 *   started) -> 409. App\Support\Booking\BookingItemStatusMachine::
 *   transitionToCompleted() only ever accepts IN_PROGRESS -> COMPLETED -
 *   there is no sanctioned PENDING_ASSIGNMENT/ASSIGNED -> COMPLETED jump,
 *   and this Action never invents one.
 * - No Booking Items at all -> 409 (defensive; a real Booking always has
 *   at least one).
 * - Otherwise every item is already COMPLETED or IN_PROGRESS: every
 *   IN_PROGRESS item is transitioned to COMPLETED via the existing
 *   App\Actions\Booking\TransitionBookingItemStatusAction::complete() (never
 *   a raw `booking_items` write) - technician_assignments is left exactly
 *   as normal Complete Work leaves it (released_at NOT touched; completion
 *   is tracked on the Item, not by releasing the assignment - see
 *   CompleteTechnicianJobAction).
 *
 * The parent Booking is then walked up to COMPLETED via
 * App\Actions\Booking\TransitionBookingStatusAction's own PAID -> ASSIGNED
 * -> IN_PROGRESS -> COMPLETED steps (never a raw `bookings.status_id`
 * write) - this mirrors App\Actions\Booking\SyncBookingStatusFromItemsAction's
 * catch-up cascade shape exactly (the same recovery scenario: Items ahead
 * of a parent that never caught up), but is NOT that Action itself, since
 * its hardcoded 'Automatically synchronized...' reason and null actor would
 * silently discard the Admin's actual reason/identity from history - the
 * one thing this operation must never do.
 *
 * Every real transition step happens inside ONE outer transaction (nested
 * calls into the two Transition*Action classes above safely reuse the same
 * row locks via savepoints), so the Admin audit event below is written
 * atomically with the state change.
 */
final class AdminForceCompleteBookingAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly TransitionBookingItemStatusAction $itemLifecycle = new TransitionBookingItemStatusAction,
        private readonly TransitionBookingStatusAction $bookingLifecycle = new TransitionBookingStatusAction,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request, User $actor, string $bookingUuid, string $reason): array
    {
        try {
            $bookingIdBinary = UuidBinary::toBinary($bookingUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Booking not found.');
        }

        return DB::transaction(function () use ($request, $actor, $bookingIdBinary, $bookingUuid, $reason): array {
            $booking = DB::table('bookings')->where('id', $bookingIdBinary)->lockForUpdate()->first();

            if ($booking === null) {
                return $this->notFound('Booking not found.');
            }

            $currentStatus = BookingStatuses::code((int) $booking->status_id);

            if ($currentStatus === 'CANCELLED') {
                return $this->conflict('A cancelled Booking cannot be force-completed.');
            }

            if ($currentStatus === 'COMPLETED') {
                return $this->ok(200, 'Booking is already completed.', [
                    'booking' => ['uuid' => $bookingUuid, 'status' => 'COMPLETED'],
                ]);
            }

            $items = DB::table('booking_items')
                ->where('booking_id', $bookingIdBinary)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                return $this->conflict('This Booking has no items to complete.');
            }

            $itemStatusCodes = $items->map(fn ($item) => BookingItemStatuses::code((int) $item->status_id));

            if ($itemStatusCodes->contains('CANCELLED')) {
                return $this->conflict('This Booking has a cancelled item and cannot be force-completed.');
            }

            if ($itemStatusCodes->contains(fn (string $code) => in_array($code, ['PENDING_ASSIGNMENT', 'ASSIGNED'], true))) {
                return $this->conflict('This Booking has items that have not started work and cannot be force-completed.');
            }

            $actorUuid = $actor->id;

            foreach ($items as $item) {
                if (BookingItemStatuses::code((int) $item->status_id) !== 'IN_PROGRESS') {
                    continue;
                }

                $this->itemLifecycle->complete(
                    UuidBinary::toString($item->id),
                    reason: $reason,
                    actorUserUuid: $actorUuid,
                );
            }

            if (in_array($currentStatus, ['PAID', 'CONFIRMED'], true)) {
                $this->bookingLifecycle->assign($bookingUuid, reason: $reason, actorUserUuid: $actorUuid);
                $currentStatus = 'ASSIGNED';
            }

            if ($currentStatus === 'ASSIGNED') {
                $this->bookingLifecycle->start($bookingUuid, reason: $reason, actorUserUuid: $actorUuid);
                $currentStatus = 'IN_PROGRESS';
            }

            $this->bookingLifecycle->complete($bookingUuid, reason: $reason, actorUserUuid: $actorUuid);

            AdminAuditLogger::record(
                request: $request,
                actor: $actor,
                actionCode: 'BOOKING_FORCE_COMPLETED',
                entityType: 'BOOKING',
                entityIdentifier: $bookingUuid,
                newValues: ['reason' => $reason],
            );

            return $this->ok(200, 'Booking force-completed successfully.', [
                'booking' => ['uuid' => $bookingUuid, 'status' => 'COMPLETED'],
            ]);
        });
    }
}
