<?php

namespace App\Support\Payment;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B25 - the repair-quote-balance-payment counterpart to
 * App\Support\Payment\PaymentAttemptStateMachine, targeting
 * `repair_quote_payment_attempts` instead of `payment_attempts` (see that
 * table's docblock in database/phase25_inspection_quote_credit_migration.sql
 * for why it is a dedicated, Cart-less table rather than a `payment_attempts`
 * row). Same conventions exactly: caller must already hold the row lock,
 * every transition is a safe no-op (never a throw) when the row is not
 * currently PENDING, and every write uses a pre-formatted
 * datetime(6)-safe timestamp.
 */
final class RepairQuoteBalancePaymentStateMachine
{
    public function transitionToSuccessful(
        object $attempt,
        Carbon $at,
        string $confirmedAmount,
        ?string $providerTransactionReference,
        ?string $providerStatusCode,
        ?string $paymentMethodType,
        bool $requiresReconciliation = false,
        ?string $reconciliationReasonCode = null,
    ): bool {
        if (! $this->isPending($attempt)) {
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
            $update['payment_method_code'] = $paymentMethodType === 'apple_pay' ? 'APPLE_PAY' : 'CARD';
        }

        DB::table('repair_quote_payment_attempts')->where('id', $attempt->id)->update($update);

        return true;
    }

    public function transitionToFailed(object $attempt, Carbon $at, ?string $failureCode, ?string $failureMessage, ?string $providerStatusCode): bool
    {
        if (! $this->isPending($attempt)) {
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

        DB::table('repair_quote_payment_attempts')->where('id', $attempt->id)->update($update);

        return true;
    }

    public function transitionToCancelled(object $attempt, Carbon $at, ?string $providerStatusCode): bool
    {
        if (! $this->isPending($attempt)) {
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

        DB::table('repair_quote_payment_attempts')->where('id', $attempt->id)->update($update);

        return true;
    }

    public function isPending(object $attempt): bool
    {
        return (int) $attempt->status_id === PaymentStatuses::id('PENDING');
    }
}
