<?php

namespace App\Actions\Contract\Billing;

use App\Support\Contract\Billing\ContractBillingStatuses;
use App\Support\Contract\Billing\Gateway\ContractBillingGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Durable, retryable request to cancel the recurring-billing provider's
 * Subscription linked to one Service Contract's billing record (BLUE V1
 * Phase 11 "CANCELLATION", hardened for provider outages) - the one place
 * App\Actions\Admin\Contract\AdminCancelContractAction delegates this
 * concern to, kept entirely inside the Contract Billing domain
 * (App\Actions\Contract\Billing\*) so the Admin operations source tree
 * never has to name a concrete billing provider or read a
 * provider-specific column itself (see
 * tests\Feature\Admin\AdminFinancialIsolationTest - Admin operations source
 * must never reference the payment/billing provider by name).
 *
 * Two-phase, durable design - a provider network failure must never leave a
 * Contract's provider-side Subscription permanently un-cancelled with no
 * retry path:
 *
 *   1. markCancellationRequested() - called INSIDE the same DB transaction
 *      that transitions the Contract to CANCELLED (see
 *      App\Actions\Admin\Contract\AdminCancelContractAction). Durably
 *      records the intent to cancel by stamping
 *      `service_contract_billings.provider_cancellation_requested_at` -
 *      this is the ONE moment a logical cancellation request is created, and
 *      it commits together with the Contract's own CANCELLED transition (an
 *      idempotent no-op if a request is already pending or the billing
 *      record is already provider-confirmed-cancelled). A repeated Admin
 *      cancel call is already a no-op before this method is even reached
 *      (App\Actions\Admin\Contract\AdminCancelContractAction only calls it
 *      on the real transition), and this method's own guard makes it safe
 *      even if that ever changed - exactly one logical cancellation request
 *      is ever created per Contract, regardless of how many times anything
 *      calls this.
 *
 *   2. handle() - the actual best-effort provider delivery attempt. Called
 *      once, fire-and-forget, immediately AFTER the Admin transaction above
 *      commits, and again - possibly many times - by
 *      App\Actions\Contract\Billing\RetryPendingContractBillingCancellationsAction
 *      (via `contracts:retry-pending-billing-cancellations`) for as long as
 *      the request stays unreconciled. Never writes
 *      `service_contract_billings.status_id` or `cancelled_at` itself -
 *      only the eventual provider webhook (App\Actions\Contract\Billing\
 *      ProcessContractBillingWebhookAction) does that, so this side channel
 *      can never race or conflict with it. A safe no-op once nothing is
 *      pending (no request was ever marked, or the webhook already
 *      reconciled it) - this is what makes it safe for the retry command to
 *      call unconditionally every run.
 *
 * The provider call itself is treated as at-least-once delivery, never
 * exactly-once: this class can never prove a given attempt was received by
 * the provider (a timeout is genuinely ambiguous - the provider may or may
 * not have processed it), so every attempt is unconditionally recorded via
 * `provider_cancellation_last_attempt_at` / `provider_cancellation_attempt_count`
 * regardless of whether the gateway call itself throws. Any Throwable a
 * gateway implementation raises is caught and reported, never allowed to
 * escape - this remains a pure side channel that can never affect the
 * caller's own transaction or HTTP response.
 */
class CancelContractBillingSubscriptionAction
{
    public function __construct(private readonly ContractBillingGateway $gateway) {}

    /**
     * Must be called from inside an already-open DB transaction (the same
     * one that transitions the parent Contract to CANCELLED) - participates
     * in that transaction rather than opening its own, so the durable
     * cancellation-request marker commits atomically with the Contract's
     * own CANCELLED transition.
     */
    public function markCancellationRequested(string $contractIdBinary, Carbon $at): void
    {
        $billing = DB::table('service_contract_billings')
            ->where('service_contract_id', $contractIdBinary)
            ->first(['id', 'stripe_subscription_id', 'cancelled_at', 'provider_cancellation_requested_at']);

        if ($billing === null || $billing->stripe_subscription_id === null) {
            return;
        }

        if ($billing->cancelled_at !== null || $billing->provider_cancellation_requested_at !== null) {
            return;
        }

        DB::table('service_contract_billings')
            ->where('id', $billing->id)
            ->whereNull('provider_cancellation_requested_at')
            ->whereNull('cancelled_at')
            ->update([
                'provider_cancellation_requested_at' => $at->format('Y-m-d H:i:s.u'),
                'updated_at' => $at->format('Y-m-d H:i:s.u'),
            ]);
    }

    /**
     * Performs (or safely re-performs) the best-effort provider delivery
     * attempt for a durably-recorded pending cancellation request. Safe to
     * call any number of times, from any number of places (the initiating
     * Admin action, the retry command) - always re-reads the current state
     * and is a no-op the moment nothing is pending.
     */
    public function handle(string $contractIdBinary): void
    {
        $billing = DB::table('service_contract_billings')
            ->where('service_contract_id', $contractIdBinary)
            ->first(['id', 'status_id', 'stripe_subscription_id', 'cancelled_at', 'provider_cancellation_requested_at']);

        if (! $this->isPending($billing)) {
            return;
        }

        $now = now();

        try {
            $this->gateway->cancelSubscription($billing->stripe_subscription_id);
        } catch (Throwable $e) {
            // At-least-once delivery, never exactly-once - see class
            // docblock. A failed/ambiguous attempt (timeout, network error,
            // provider outage) must never bubble up and must never stop the
            // attempt from being recorded below, so a later retry
            // (contracts:retry-pending-billing-cancellations) always has an
            // accurate `provider_cancellation_last_attempt_at` to work from.
            report($e);
        }

        DB::table('service_contract_billings')
            ->where('id', $billing->id)
            ->whereNull('cancelled_at')
            ->update([
                'provider_cancellation_last_attempt_at' => $now->format('Y-m-d H:i:s.u'),
                'provider_cancellation_attempt_count' => DB::raw('provider_cancellation_attempt_count + 1'),
                'updated_at' => $now->format('Y-m-d H:i:s.u'),
            ]);
    }

    private function isPending(?object $billing): bool
    {
        if ($billing === null || $billing->stripe_subscription_id === null) {
            return false;
        }

        if ($billing->provider_cancellation_requested_at === null || $billing->cancelled_at !== null) {
            return false;
        }

        return ContractBillingStatuses::code((int) $billing->status_id) !== 'CANCELLED';
    }
}
