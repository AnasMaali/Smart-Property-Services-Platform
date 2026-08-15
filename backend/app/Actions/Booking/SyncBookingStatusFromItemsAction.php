<?php

namespace App\Actions\Booking;

use App\Support\Booking\BookingItemStatuses;
use App\Support\Booking\BookingStatuses;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * Synchronizes the parent Booking lifecycle from its Booking Items.
 *
 * This is intentionally NOT part of TransitionBookingItemStatusAction.
 * The low-level Booking Item state machine remains independently usable.
 *
 * Aggregate policy:
 *
 * - First ASSIGNED-or-later item:
 *     PAID -> ASSIGNED
 *
 * - First IN_PROGRESS-or-COMPLETED item:
 *     ASSIGNED -> IN_PROGRESS
 *
 * - All items COMPLETED:
 *     IN_PROGRESS -> COMPLETED
 *
 * The parent Booking never regresses.
 *
 * Lock order is always:
 *
 *     bookings -> booking_items (ordered by id)
 *
 * Callers invoke this only AFTER their Booking Item transaction commits.
 * This avoids the item -> booking -> sibling deadlock cycle that would be
 * possible if aggregate synchronization happened inside the item transaction.
 */
final class SyncBookingStatusFromItemsAction
{
    private const REASON = 'Automatically synchronized from Booking Item lifecycle.';

    public function __construct(
        private readonly TransitionBookingStatusAction $bookingLifecycle = new TransitionBookingStatusAction,
    ) {}

    public function syncForItem(string $bookingItemUuid): void
    {
        $itemIdBinary = UuidBinary::toBinary($bookingItemUuid);

        $bookingIdBinary = DB::table('booking_items')
            ->where('id', $itemIdBinary)
            ->value('booking_id');

        if ($bookingIdBinary === null) {
            return;
        }

        $bookingUuid = UuidBinary::toString($bookingIdBinary);

        DB::transaction(function () use ($bookingIdBinary, $bookingUuid): void {
            // Parent first: this is the aggregate synchronization root lock.
            $booking = DB::table('bookings')
                ->where('id', $bookingIdBinary)
                ->lockForUpdate()
                ->first();

            if ($booking === null) {
                return;
            }

            // Stable sibling lock order prevents two synchronizers for the
            // same Booking from locking Booking Items in opposite orders.
            $items = DB::table('booking_items')
                ->where('booking_id', $bookingIdBinary)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'status_id']);

            if ($items->isEmpty()) {
                return;
            }

            $itemStatusCodes = $items
                ->map(fn (object $item): string => BookingItemStatuses::code((int) $item->status_id));

            $hasAssignedOrBeyond = $itemStatusCodes->contains(
                fn (string $code): bool => in_array($code, ['ASSIGNED', 'IN_PROGRESS', 'COMPLETED'], true)
            );

            $hasInProgressOrCompleted = $itemStatusCodes->contains(
                fn (string $code): bool => in_array($code, ['IN_PROGRESS', 'COMPLETED'], true)
            );

            // Deliberately require every item to be COMPLETED.
            // Cancellation aggregation is a separate business rule and is
            // not invented here.
            $allCompleted = $itemStatusCodes->every(
                fn (string $code): bool => $code === 'COMPLETED'
            );

            $currentStatus = BookingStatuses::code((int) $booking->status_id);

            // Terminal states never move again.
            if (in_array($currentStatus, ['COMPLETED', 'CANCELLED'], true)) {
                return;
            }

            /*
             * Catch-up is intentional.
             *
             * Example: an old Booking is still PAID but an Item has already
             * reached IN_PROGRESS. One synchronization may safely perform:
             *
             * PAID -> ASSIGNED -> IN_PROGRESS
             */
            if ($currentStatus === 'PAID' && $hasAssignedOrBeyond) {
                $this->bookingLifecycle->assign($bookingUuid, self::REASON);
                $currentStatus = 'ASSIGNED';
            }

            if ($currentStatus === 'ASSIGNED' && $hasInProgressOrCompleted) {
                $this->bookingLifecycle->start($bookingUuid, self::REASON);
                $currentStatus = 'IN_PROGRESS';
            }

            if ($currentStatus === 'IN_PROGRESS' && $allCompleted) {
                $this->bookingLifecycle->complete($bookingUuid, self::REASON);
            }
        });
    }
}
