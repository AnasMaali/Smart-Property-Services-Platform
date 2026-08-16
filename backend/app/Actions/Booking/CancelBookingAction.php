<?php

namespace App\Actions\Booking;

use App\Support\Booking\BookingItemStatuses;
use App\Support\Booking\BookingItemStatusMachine;
use App\Support\Booking\BookingStatuses;
use App\Support\Booking\BookingStatusMachine;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CancelBookingAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly BookingStatusMachine $bookingMachine = new BookingStatusMachine,
        private readonly BookingItemStatusMachine $itemMachine = new BookingItemStatusMachine,
    ) {}

    /**
     * Customer cancellation only.
     *
     * This action:
     * - verifies Booking ownership
     * - cancels the parent Booking
     * - cancels every non-terminal Booking Item
     * - releases active Technician assignments
     * - calculates the amount due for manual refund
     *
     * It NEVER calls Stripe and NEVER changes payment_attempts status.
     *
     * @return array<string, mixed>
     */
    public function handle(string $userUuid, string $bookingUuid): array
    {
        try {
            $bookingIdBinary = UuidBinary::toBinary($bookingUuid);
            $userIdBinary = UuidBinary::toBinary($userUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Booking not found.');
        }

        return DB::transaction(function () use (
            $bookingIdBinary,
            $userIdBinary,
            $bookingUuid
        ): array {
            /*
             * Root lock first.
             */
            $booking = DB::table('bookings')
                ->where('id', $bookingIdBinary)
                ->lockForUpdate()
                ->first();

            if ($booking === null) {
                return $this->notFound('Booking not found.');
            }

            /*
             * Same ownership rule as GetBookingAction:
             *
             * Booking -> Cart -> customer_user_id
             *
             * Foreign and unknown Bookings are intentionally both 404.
             */
            $ownsBooking = DB::table('carts')
                ->where('id', $booking->cart_id)
                ->where('customer_user_id', $userIdBinary)
                ->exists();

            if (! $ownsBooking) {
                return $this->notFound('Booking not found.');
            }

            $currentStatus = BookingStatuses::code((int) $booking->status_id);

            /*
             * COMPLETED is terminal and cannot be cancelled.
             */
            if ($currentStatus === 'COMPLETED') {
                return $this->conflict(
                    'A completed Booking cannot be cancelled.'
                );
            }

            /*
             * Only the states already supported by BookingStatusMachine
             * are cancellable.
             */
            if (! in_array(
                $currentStatus,
                ['PAID', 'ASSIGNED', 'IN_PROGRESS', 'CANCELLED'],
                true
            )) {
                return $this->conflict(
                    'This Booking cannot be cancelled from its current status.'
                );
            }

            /*
             * Lock all Booking Items in deterministic order.
             */
            $items = DB::table('booking_items')
                ->where('booking_id', $bookingIdBinary)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            /*
             * Lock every currently-active Technician assignment belonging
             * to this Booking after the Booking Items are locked.
             */
            $itemIds = $items->pluck('id')->all();

            $activeAssignments = collect();

            if ($itemIds !== []) {
                $activeAssignments = DB::table('technician_assignments')
                    ->whereIn('booking_item_id', $itemIds)
                    ->whereNull('released_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }

            /*
             * Payment and appointment are read only.
             *
             * confirmed_amount is authoritative after successful payment.
             * requested_amount is only a defensive fallback.
             */
            $payment = DB::table('payment_attempts')
                ->where('id', $booking->payment_attempt_id)
                ->first([
                    'confirmed_amount',
                    'requested_amount',
                ]);

            $slot = DB::table('appointment_slots')
                ->where('id', $booking->appointment_slot_id)
                ->first([
                    'starts_at',
                ]);

            if ($payment === null || $slot === null) {
                return $this->conflict(
                    'Booking cancellation data is incomplete.'
                );
            }

            $now = now();

            /*
             * Idempotency:
             *
             * If this Booking had already been cancelled, calculate the
             * refund from its ORIGINAL cancelled_at timestamp, never from
             * the retry time.
             */
            $effectiveCancellationAt = $currentStatus === 'CANCELLED'
                ? $booking->cancelled_at
                : $now;

            /*
             * Cancel the parent only on the first real cancellation.
             */
            if ($currentStatus !== 'CANCELLED') {
                if (! $this->bookingMachine->transitionToCancelled($booking, $now)) {
                    return $this->conflict(
                        'This Booking cannot be cancelled from its current status.'
                    );
                }

                DB::table('booking_status_history')->insert([
                    'id' => UuidBinary::toBinary(UuidBinary::generate()),
                    'booking_id' => $bookingIdBinary,
                    'from_status_id' => (int) $booking->status_id,
                    'to_status_id' => BookingStatuses::id('CANCELLED'),
                    'changed_by_user_id' => $userIdBinary,
                    'reason' => 'Customer cancelled booking.',
                    'changed_at' => $now->format('Y-m-d H:i:s.u'),
                ]);
            }

            /*
             * Cancel every Booking Item that has not already reached a
             * terminal state.
             *
             * COMPLETED stays COMPLETED.
             * CANCELLED stays CANCELLED.
             */
            foreach ($items as $item) {
                $itemStatus = BookingItemStatuses::code(
                    (int) $item->status_id
                );

                if (in_array($itemStatus, ['COMPLETED', 'CANCELLED'], true)) {
                    continue;
                }

                if ($this->itemMachine->transitionToCancelled($item, $now)) {
                    DB::table('booking_item_status_history')->insert([
                        'id' => UuidBinary::toBinary(UuidBinary::generate()),
                        'booking_item_id' => $item->id,
                        'from_status_id' => (int) $item->status_id,
                        'to_status_id' => BookingItemStatuses::id('CANCELLED'),
                        'changed_by_user_id' => $userIdBinary,
                        'reason' => 'Customer cancelled booking.',
                        'changed_at' => $now->format('Y-m-d H:i:s.u'),
                    ]);
                }
            }

            /*
             * Release every active Technician assignment.
             *
             * The schema requires released_at, released_by_user_id and
             * release_reason to be populated together.
             */
            $releaseTimestamp = $now->format('Y-m-d H:i:s.u');

            foreach ($activeAssignments as $assignment) {
                DB::table('technician_assignments')
                    ->where('id', $assignment->id)
                    ->whereNull('released_at')
                    ->update([
                        'released_at' => $releaseTimestamp,
                        'released_by_user_id' => $userIdBinary,
                        'release_reason' => 'Customer cancelled booking.',
                        'updated_at' => $releaseTimestamp,
                    ]);
            }

            /*
             * Refund policy is based on the BUSINESS-LOCAL calendar day,
             * not on "24 hours before appointment".
             *
             * Appointment timestamps are stored under the application's
             * UTC convention, then converted to the configured business
             * timezone before comparing calendar dates.
             */
            $businessTimezone = (string) config(
                'cancellation.timezone',
                'UTC'
            );

            $storageTimezone = (string) config(
                'app.timezone',
                'UTC'
            );

            $appointmentLocal = CarbonImmutable::parse(
                (string) $slot->starts_at,
                $storageTimezone
            )->setTimezone($businessTimezone);

            $cancelledLocal = CarbonImmutable::parse(
                (string) $effectiveCancellationAt,
                $storageTimezone
            )->setTimezone($businessTimezone);

            $appointmentDayStartsAt = $appointmentLocal->startOfDay();

            $percentage = $cancelledLocal->lt($appointmentDayStartsAt)
                ? (int) config(
                    'cancellation.before_appointment_day_percentage',
                    100
                )
                : (int) config(
                    'cancellation.appointment_day_percentage',
                    75
                );

            $paidAmount = (string) (
                $payment->confirmed_amount
                ?? $payment->requested_amount
            );

            /*
             * Never use float arithmetic for money.
             */
            $refundAmount = bcdiv(
                bcmul(
                    $paidAmount,
                    (string) $percentage,
                    6
                ),
                '100',
                6
            );

            return $this->ok(
                200,
                $currentStatus === 'CANCELLED'
                    ? 'Booking was already cancelled.'
                    : 'Booking cancelled successfully.',
                [
                    'booking' => [
                        'uuid' => $bookingUuid,
                        'status' => 'CANCELLED',
                        'cancelled_at' => CarbonImmutable::parse(
                            (string) $effectiveCancellationAt,
                            $storageTimezone
                        )->toISOString(),
                    ],
                    'refund_due' => [
                        'percentage' => $percentage,
                        'amount' => $refundAmount,
                        'execution' => 'MANUAL',
                    ],
                ]
            );
        });
    }
}