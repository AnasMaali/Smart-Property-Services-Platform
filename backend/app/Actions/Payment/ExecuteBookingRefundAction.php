<?php

namespace App\Actions\Payment;

use App\Support\Booking\BookingRefundStatuses;
use App\Support\Payment\Gateway\PaymentGateway;
use App\Support\Payment\Gateway\RefundCreationData;
use App\Support\Payment\Gateway\RefundCreationOutcome;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B20 - executes exactly one Stripe refund for one
 * `booking_refunds` obligation row. The one place `booking_refunds.
 * status_id` is ever written after creation, mirroring App\Support\
 * Payment\PaymentAttemptStateMachine's role for `payment_attempts`.
 *
 * Deliberately never runs inside the DB transaction that creates the
 * obligation (App\Actions\Booking\CancelBookingAction) - a DB transaction
 * and a Stripe API call cannot be one atomic unit. Two independent
 * callers invoke this with the SAME safe, idempotent semantics:
 *
 * 1. App\Actions\Booking\CancelBookingAction, once, best-effort,
 *    immediately AFTER its cancellation transaction commits (never
 *    inside it) - the common case, where the customer/Admin sees the
 *    refund already resolving in the same HTTP response.
 * 2. App\Console\Commands\ExecutePendingBookingRefunds, the recovery path
 *    for every obligation this best-effort attempt could not resolve
 *    (Stripe unavailable, timeout, process crash between steps 1 and 2).
 *
 * Idempotency is guaranteed two ways: (a) this method is a safe no-op the
 * instant the row is no longer PENDING (a webhook or a concurrent retry
 * already resolved it - never re-call Stripe, never regress a terminal
 * state), and (b) `booking_refunds.idempotency_key`, generated once at
 * obligation-creation time and reused byte-for-byte on every retry, so
 * even a raw, uncoordinated concurrent call converges on ONE Stripe
 * refund object (Stripe's own idempotency guarantee - see
 * StripePaymentGateway::refundPayment's docblock).
 *
 * A RefundCreationOutcome::UNKNOWN result (network/timeout/5xx) makes NO
 * write at all - the row stays exactly PENDING, exactly as safe to retry
 * as it was before this call ran.
 */
final class ExecuteBookingRefundAction
{
    public function __construct(
        private readonly PaymentGateway $gateway,
    ) {}

    public function providerCode(): string
    {
        return $this->gateway->providerCode();
    }

    public function handle(string $bookingRefundUuid): void
    {
        $idBinary = UuidBinary::toBinary($bookingRefundUuid);

        $row = DB::table('booking_refunds')->where('id', $idBinary)->first();

        if ($row === null || (int) $row->status_id !== BookingRefundStatuses::id('PENDING')) {
            // Already resolved (SUCCEEDED/FAILED) by a webhook or an
            // earlier attempt, or simply does not exist - never call
            // Stripe for a non-PENDING/non-existent obligation.
            return;
        }

        $paymentProviderReference = DB::table('payment_attempts')
            ->where('id', $row->payment_attempt_id)
            ->value('provider_session_reference');

        if ($paymentProviderReference === null) {
            // Defensive: a SUCCESSFUL payment_attempts row always has a
            // provider_session_reference by the time a Booking (and thus a
            // refund obligation) can exist for it. Nothing safe to do but
            // leave the obligation PENDING for operator investigation.
            return;
        }

        $currency = DB::table('currencies')->where('id', $row->currency_id)->first(['code', 'minor_unit']);

        // BLUE V1 Phase B20 fix - App\Support\Booking\
        // RefundEligibilityCalculator already normalizes/rounds
        // requested_amount to the currency's own minor-unit precision
        // before persisting it (half-up, never truncated to zero), so
        // this can only be true for a genuinely unrefundable sub-minor-
        // unit remainder. Handled explicitly and terminally here, never
        // by sending Stripe a zero-value refund request.
        if (bccomp((string) $row->requested_amount, '0', (int) $currency->minor_unit) <= 0) {
            $this->persistFailure(
                $row->id,
                'REFUND_AMOUNT_BELOW_MINIMUM_UNIT',
                'The calculated refund amount rounds to less than the smallest refundable currency unit.'
            );

            return;
        }

        $result = $this->gateway->refundPayment(new RefundCreationData(
            bookingRefundUuid: $bookingRefundUuid,
            providerPaymentReference: (string) $paymentProviderReference,
            amount: (string) $row->requested_amount,
            currencyCode: (string) $currency->code,
            currencyMinorUnit: (int) $currency->minor_unit,
            providerIdempotencyKey: $row->idempotency_key,
        ));

        match ($result->outcome) {
            RefundCreationOutcome::CREATED => $this->persistCreated($row->id, $result->providerRefundReference, $result->providerStatusCode),
            RefundCreationOutcome::DEFINITIVE_FAILURE => $this->persistFailure($row->id, $result->failureCode, $result->failureMessage),
            RefundCreationOutcome::UNKNOWN => null,
        };
    }

    private function persistCreated(string $idBinary, ?string $providerRefundReference, ?string $providerStatusCode): void
    {
        DB::transaction(function () use ($idBinary, $providerRefundReference, $providerStatusCode): void {
            $locked = DB::table('booking_refunds')->where('id', $idBinary)->lockForUpdate()->first();

            if ($locked === null || (int) $locked->status_id !== BookingRefundStatuses::id('PENDING')) {
                // A webhook (or a racing concurrent retry) already resolved
                // this obligation - never regress a terminal state.
                return;
            }

            $now = now();
            $timestamp = $now->format('Y-m-d H:i:s.u');
            $succeeded = $providerStatusCode === 'succeeded';

            DB::table('booking_refunds')->where('id', $locked->id)->update([
                'provider_refund_reference' => $providerRefundReference,
                'provider_status_code' => $providerStatusCode,
                'submitted_at' => $locked->submitted_at ?? $timestamp,
                'status_id' => $succeeded ? BookingRefundStatuses::id('SUCCEEDED') : BookingRefundStatuses::id('PENDING'),
                'succeeded_at' => $succeeded ? $timestamp : null,
                'updated_at' => $timestamp,
            ]);
        });
    }

    private function persistFailure(string $idBinary, ?string $failureCode, ?string $failureMessage): void
    {
        DB::transaction(function () use ($idBinary, $failureCode, $failureMessage): void {
            $locked = DB::table('booking_refunds')->where('id', $idBinary)->lockForUpdate()->first();

            if ($locked === null || (int) $locked->status_id !== BookingRefundStatuses::id('PENDING')) {
                return;
            }

            $timestamp = now()->format('Y-m-d H:i:s.u');

            DB::table('booking_refunds')->where('id', $locked->id)->update([
                'status_id' => BookingRefundStatuses::id('FAILED'),
                'failed_at' => $timestamp,
                'failure_code' => $failureCode ?? 'STRIPE_REFUND_REJECTED',
                'failure_message' => $failureMessage ?? 'Stripe rejected the refund request.',
                'updated_at' => $timestamp,
            ]);
        });
    }
}
