<?php

namespace App\Actions\Contract\Billing;

use App\Support\Contract\Billing\ContractBillingStatuses;
use App\Support\Contract\ContractStatuses;
use App\Support\Contract\ContractStatusMachine;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Maintenance-only bulk escalation for Service Contracts whose billing has
 * sat PAST_DUE beyond the configured grace period (BLUE V1 Phase 11
 * "FAILED RENEWAL", CONTRACT_BILLING_GRACE_DAYS - default 3 days).
 * `App\Actions\Contract\CreateContractBookingAction` already blocks every
 * new CONTRACT Booking for the entire PAST_DUE window regardless of this
 * command ever running - the grace period this Action enforces is a
 * separate, slower escalation: an ACTIVE Contract still stuck PAST_DUE
 * after `grace_days` is transitioned ACTIVE -> SUSPENDED - the Contract
 * record itself is always preserved, never cancelled, and no Stripe-side
 * action is taken here: BLUE V1 never invents its own collection/retry
 * logic beyond Stripe's own automatic Billing retries (see
 * StripeContractBillingGateway - Stripe itself decides when/whether to keep
 * retrying or eventually fire customer.subscription.deleted, which
 * App\Actions\Contract\Billing\ProcessContractBillingWebhookAction already
 * reconciles independently of this command). Unlike an Admin manually
 * suspending a Contract (App\Actions\Admin\Contract\
 * AdminSuspendContractAction), this Action also stamps
 * `service_contract_billings.billing_suspended_at` in the SAME transaction
 * as the Contract transition - the one durable marker that distinguishes
 * "billing caused this suspension" from "an Admin caused this suspension".
 *
 * If the Stripe retry succeeds later, invoice.paid both restores billing
 * ACTIVE AND - because this suspension is marked `billing_suspended_at` -
 * automatically recovers the Contract SUSPENDED -> ACTIVE too, via
 * ContractStatusMachine::recoverSuspendedByBillingToActive() (see
 * ProcessContractBillingWebhookAction::handleInvoicePaid()). A Contract an
 * Admin manually suspended is never auto-recovered this way (BLUE V1 has no
 * automatic reactivation for that case, by design, and no Admin resume
 * endpoint yet either) - only a suspension THIS Action caused ever is.
 */
class SuspendContractsPastDueBillingAction
{
    public function __construct(private readonly ContractStatusMachine $machine = new ContractStatusMachine) {}

    /**
     * @return int Number of Contracts transitioned to SUSPENDED.
     */
    public function handle(int $limit = 500): int
    {
        $graceDays = (int) config('contract_billing.grace_days', 3);
        $cutoff = now()->subDays($graceDays);

        $candidateContractIds = DB::table('service_contract_billings')
            ->join('service_contracts', 'service_contracts.id', '=', 'service_contract_billings.service_contract_id')
            ->where('service_contract_billings.status_id', ContractBillingStatuses::id('PAST_DUE'))
            ->whereNotNull('service_contract_billings.past_due_since')
            ->where('service_contract_billings.past_due_since', '<=', $cutoff->format('Y-m-d H:i:s.u'))
            ->where('service_contracts.status_id', ContractStatuses::id('ACTIVE'))
            ->orderBy('service_contract_billings.past_due_since')
            ->limit($limit)
            ->pluck('service_contracts.id');

        $suspended = 0;

        foreach ($candidateContractIds as $contractIdBinary) {
            $wasSuspended = DB::transaction(function () use ($contractIdBinary, $cutoff): bool {
                $contract = DB::table('service_contracts')->where('id', $contractIdBinary)->lockForUpdate()->first();

                if ($contract === null || (int) $contract->status_id !== ContractStatuses::id('ACTIVE')) {
                    return false;
                }

                $billing = DB::table('service_contract_billings')->where('service_contract_id', $contract->id)->lockForUpdate()->first();

                if ($billing === null
                    || (int) $billing->status_id !== ContractBillingStatuses::id('PAST_DUE')
                    || $billing->past_due_since === null
                    || Carbon::parse($billing->past_due_since)->greaterThan($cutoff)
                ) {
                    return false;
                }

                $now = now();

                if (! $this->machine->transitionToSuspended($contract, $now)) {
                    return false;
                }

                $timestamp = $now->format('Y-m-d H:i:s.u');

                // Marks this as a BILLING-caused suspension, in the SAME
                // transaction as the Contract transition above (BLUE V1
                // Phase 11 "billing suspension recovery") - this is what
                // later lets App\Actions\Contract\Billing\
                // ProcessContractBillingWebhookAction distinguish "billing
                // recovered after this" from a Contract an Admin manually
                // suspended (App\Actions\Admin\Contract\
                // AdminSuspendContractAction never sets this column), so
                // only THIS suspension is ever eligible for the automatic
                // SUSPENDED -> ACTIVE recovery once billing repairs itself.
                DB::table('service_contract_billings')->where('id', $billing->id)->update([
                    'billing_suspended_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                DB::table('service_contract_status_history')->insert([
                    'id' => UuidBinary::toBinary(UuidBinary::generate()),
                    'service_contract_id' => $contract->id,
                    'from_status_id' => ContractStatuses::id('ACTIVE'),
                    'to_status_id' => ContractStatuses::id('SUSPENDED'),
                    'changed_by_user_id' => null,
                    'reason' => 'Billing past due beyond the grace period.',
                    'changed_at' => $timestamp,
                ]);

                return true;
            });

            if ($wasSuspended) {
                $suspended++;
            }
        }

        return $suspended;
    }
}
