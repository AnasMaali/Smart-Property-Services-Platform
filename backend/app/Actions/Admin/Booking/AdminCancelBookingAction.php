<?php

namespace App\Actions\Admin\Booking;

use App\Actions\Booking\CancelBookingAction;
use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use Illuminate\Http\Request;

/**
 * Admin "Cancel Booking" (BLUE V1 Phase B16) - the one Admin-initiated
 * Booking status change this phase supports, and the ONLY status transition
 * that is safe for an Admin to trigger directly:
 *
 * - ASSIGNED/IN_PROGRESS are always DERIVED from Booking Item lifecycle
 *   (see App\Actions\Booking\SyncBookingStatusFromItemsAction, driven by the
 *   existing technician Assign/Reassign/Start/Complete operations) - an
 *   Admin manually forcing either would let the parent Booking contradict
 *   its own Items, which this phase's architecture forbids.
 * - COMPLETED requires every Booking Item to already be COMPLETED - forcing
 *   it here would be a disguised "Force Complete", explicitly out of scope.
 * - CANCELLED is the only transition reachable from any non-terminal status
 *   without depending on Item state, and an equivalent customer-initiated
 *   version (POST /v1/bookings/{booking}/cancel) already exists - so this
 *   Action is a thin Admin-authorization/audit wrapper over the exact same
 *   App\Actions\Booking\CancelBookingAction cascade (item cancellation,
 *   assignment release, one-time manual refund-eligibility snapshot),
 *   never a duplicate of it.
 *
 * Gated by its own `bookings.cancel` capability (never `bookings.manage`,
 * whose own docblock explicitly excludes Booking lifecycle status) - see
 * App\Support\Admin\AdminCapability::BOOKINGS_CANCEL.
 */
final class AdminCancelBookingAction
{
    public function __construct(
        private readonly CancelBookingAction $cancelBookingAction = new CancelBookingAction,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request, User $actor, string $bookingUuid, string $reason): array
    {
        return $this->cancelBookingAction->handle(
            actorUserUuid: $actor->id,
            bookingUuid: $bookingUuid,
            requireOwnerUuid: null,
            reason: $reason,
            onRealCancellation: function () use ($request, $actor, $bookingUuid, $reason): void {
                AdminAuditLogger::record(
                    request: $request,
                    actor: $actor,
                    actionCode: 'BOOKING_CANCELLED',
                    entityType: 'BOOKING',
                    entityIdentifier: $bookingUuid,
                    newValues: ['reason' => $reason],
                );
            },
        );
    }
}
