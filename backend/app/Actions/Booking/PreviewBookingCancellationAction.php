<?php

namespace App\Actions\Booking;

use App\Support\Booking\BookingStatuses;
use App\Support\Booking\RefundEligibilityCalculator;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * BLUE V1 Phase B20 - the one server-authoritative "what would cancelling
 * this Booking right now do" read, shared verbatim by both the customer
 * cancellation-confirmation screen (GET /v1/bookings/{booking}/
 * cancellation-preview, ownership-scoped) and the Admin equivalent
 * (GET /v1/admin/bookings/{booking}/cancellation-preview, $requireOwnerUuid
 * = null) - mirrors the App\Actions\Booking\CancelBookingAction /
 * App\Actions\Admin\Booking\AdminCancelBookingAction split exactly, so
 * neither a controller nor the frontend ever recomputes the refund
 * percentage/amount itself. Read-only: never locks, never mutates
 * anything, and never calls Stripe.
 *
 * Uses the exact same App\Support\Booking\RefundEligibilityCalculator the
 * real cancellation later re-evaluates at commit time - a preview and the
 * cancellation it precedes can only disagree if the appointment's calendar
 * day/start genuinely elapses between the two calls, which is the correct,
 * unavoidable behavior of previewing a time-based policy.
 */
final class PreviewBookingCancellationAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $bookingUuid, ?string $requireOwnerUuid = null): array
    {
        try {
            $bookingIdBinary = UuidBinary::toBinary($bookingUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Booking not found.');
        }

        $query = DB::table('bookings')
            ->join('carts', 'carts.id', '=', 'bookings.cart_id')
            ->where('bookings.id', $bookingIdBinary);

        if ($requireOwnerUuid !== null) {
            $query->where('carts.customer_user_id', UuidBinary::toBinary($requireOwnerUuid));
        }

        $booking = $query->first(['bookings.*']);

        if ($booking === null) {
            return $this->notFound('Booking not found.');
        }

        $currentStatus = BookingStatuses::code((int) $booking->status_id);

        if ($currentStatus === 'CANCELLED') {
            return $this->conflict('This Booking is already cancelled.');
        }

        if (! in_array($currentStatus, ['PAID', 'ASSIGNED', 'IN_PROGRESS'], true)) {
            return $this->conflict('This Booking cannot be cancelled from its current status.');
        }

        // Every Booking - Contract-sourced or STANDARD alike - has a
        // NOT NULL appointment_slot_id (bookings.appointment_slot_id), so
        // the appointment is always resolvable here regardless of which
        // branch below runs.
        $slot = DB::table('appointment_slots')
            ->where('id', $booking->appointment_slot_id)
            ->first(['starts_at']);

        if ($slot === null) {
            return $this->conflict('Booking cancellation data is incomplete.');
        }

        $appointment = ['starts_at' => Carbon::parse((string) $slot->starts_at)->toIso8601String()];

        $isContractBooking = $booking->service_contract_id !== null;

        if ($isContractBooking) {
            // Contract Bookings are never payment-refund-backed and never
            // subject to the appointment-started restriction below - see
            // CancelBookingAction's docblock. Status already proved this
            // Booking is cancellable.
            return $this->ok(200, 'Cancellation preview retrieved successfully.', [
                'preview' => [
                    'cancellable' => true,
                    'reason_code' => 'CONTRACT_ENTITLEMENT',
                    'appointment' => $appointment,
                    'paid_amount' => null,
                    'currency' => null,
                    'refund' => null,
                ],
            ]);
        }

        $payment = DB::table('payment_attempts')
            ->where('id', $booking->payment_attempt_id)
            ->first(['confirmed_amount', 'currency_id']);

        if ($payment === null) {
            return $this->conflict('Booking cancellation data is incomplete.');
        }

        // BLUE V1 Phase B20 fix - confirmed_amount is the ONLY financial
        // source of truth for an automated refund of a STANDARD Booking
        // (payment_attempts.confirmed_amount). A successful Booking that
        // somehow lacks it is a reconciliation failure, never a case to
        // silently guess from requested_amount - see the identical guard
        // in App\Actions\Booking\CancelBookingAction.
        if ($payment->confirmed_amount === null) {
            return $this->conflict(
                'This Booking\'s payment is missing its confirmed amount and cannot be previewed for automated refund.'
            );
        }

        $paidAmount = (string) $payment->confirmed_amount;

        $currency = DB::table('currencies')->where('id', $payment->currency_id)->first(['code', 'symbol', 'minor_unit']);

        if ($currency === null) {
            return $this->conflict('Booking cancellation data is incomplete.');
        }

        $evaluation = RefundEligibilityCalculator::evaluate(
            (string) $slot->starts_at,
            (string) now(),
            $paidAmount,
            (int) $currency->minor_unit
        );

        return $this->ok(200, 'Cancellation preview retrieved successfully.', [
            'preview' => [
                'cancellable' => $evaluation['cancellable'],
                'reason_code' => $evaluation['reason_code'],
                'appointment' => $appointment,
                'paid_amount' => $paidAmount,
                'currency' => [
                    'code' => $currency->code,
                    'symbol' => $currency->symbol,
                    'decimal_places' => (int) $currency->minor_unit,
                ],
                // Provider-neutral by design, exactly like
                // App\Support\Booking\BookingPresenter::refundDuePayload -
                // this preview is read by both the customer API and the
                // Admin API (never a separate Admin calculator), and the
                // customer surface must never name Stripe.
                'refund' => $evaluation['cancellable'] ? [
                    'percentage' => $evaluation['percentage'],
                    'amount' => $evaluation['amount'],
                    'execution' => 'AUTOMATIC',
                    'method' => 'ORIGINAL_PAYMENT_METHOD',
                ] : null,
            ],
        ]);
    }
}
