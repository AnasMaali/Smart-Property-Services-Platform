<?php

namespace App\Support\Payment;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one place a payment_attempts row's status_id is ever written after
 * creation. Phase 6A's only allowed transitions are PENDING -> SUCCESSFUL,
 * PENDING -> FAILED, PENDING -> CANCELLED (REFUNDED exists in
 * payment_statuses but no code path here ever writes it - refund execution
 * is out of scope). Every method requires the caller to have already
 * locked the row (SELECT ... FOR UPDATE) and is a safe no-op - never a
 * throw - when the row is not currently PENDING, so a stale/duplicate/
 * out-of-order webhook event can never regress a terminal attempt; callers
 * use the boolean return to distinguish "transitioned" from "already
 * terminal, nothing to do" without treating the latter as an error.
 *
 * Every write uses datetime(6)-safe pre-formatted timestamps
 * ($at->format('Y-m-d H:i:s.u')), matching the pattern already established
 * by CreateAppointmentHoldAction - the query builder truncates
 * DateTimeInterface bindings to whole-second precision otherwise.
 */
final class PaymentAttemptStateMachine
{
    /**
     * Atomically sets confirmed_amount, provider transaction reference (if
     * known), provider status code (if known), payment_method_type (if
     * safely known), successful_at, finalized_at, and status_changed_at -
     * plus the reconciliation flag/reason when the caller has determined
     * this success is not safe for automatic Booking creation (Phase 7
     * must reject automatic Booking while requires_reconciliation = true).
     */
    public function transitionToSuccessful(
        object $paymentAttempt,
        Carbon $at,
        string $confirmedAmount,
        ?string $providerTransactionReference,
        ?string $providerStatusCode,
        ?string $paymentMethodType,
        bool $requiresReconciliation = false,
        ?string $reconciliationReasonCode = null,
    ): bool {
        if (! $this->isPending($paymentAttempt)) {
            return false;
        }

        $timestamp = $at->format('Y-m-d H:i:s.u');

        $update = [
            'status_id' => PaymentStatuses::id('SUCCESSFUL'),
            'confirmed_amount' => $confirmedAmount,
            'successful_at' => $timestamp,
            'finalized_at' => $timestamp,
            'status_changed_at' => $timestamp,
            'requires_reconciliation' => $requiresReconciliation ? 1 : 0,
            'reconciliation_reason_code' => $reconciliationReasonCode,
            'updated_at' => $timestamp,
        ];

        if ($providerTransactionReference !== null) {
            $update['provider_transaction_reference'] = $providerTransactionReference;
        }

        if ($providerStatusCode !== null) {
            $update['provider_status_code'] = $providerStatusCode;
        }

        if ($paymentMethodType !== null) {
            $update['payment_method_type'] = $paymentMethodType;
        }

        DB::table('payment_attempts')->where('id', $paymentAttempt->id)->update($update);

        return true;
    }

    public function transitionToFailed(
        object $paymentAttempt,
        Carbon $at,
        ?string $failureCode,
        ?string $failureMessage,
        ?string $providerStatusCode,
    ): bool {
        if (! $this->isPending($paymentAttempt)) {
            return false;
        }

        $timestamp = $at->format('Y-m-d H:i:s.u');

        $update = [
            'status_id' => PaymentStatuses::id('FAILED'),
            'finalized_at' => $timestamp,
            'status_changed_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        if ($failureCode !== null) {
            $update['failure_code'] = $failureCode;
        }

        if ($failureMessage !== null) {
            $update['failure_message'] = $failureMessage;
        }

        if ($providerStatusCode !== null) {
            $update['provider_status_code'] = $providerStatusCode;
        }

        DB::table('payment_attempts')->where('id', $paymentAttempt->id)->update($update);

        return true;
    }

    public function transitionToCancelled(object $paymentAttempt, Carbon $at, ?string $providerStatusCode): bool
    {
        if (! $this->isPending($paymentAttempt)) {
            return false;
        }

        $timestamp = $at->format('Y-m-d H:i:s.u');

        $update = [
            'status_id' => PaymentStatuses::id('CANCELLED'),
            'finalized_at' => $timestamp,
            'status_changed_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        if ($providerStatusCode !== null) {
            $update['provider_status_code'] = $providerStatusCode;
        }

        DB::table('payment_attempts')->where('id', $paymentAttempt->id)->update($update);

        return true;
    }

    public function isPending(object $paymentAttempt): bool
    {
        return (int) $paymentAttempt->status_id === PaymentStatuses::id('PENDING');
    }
}
