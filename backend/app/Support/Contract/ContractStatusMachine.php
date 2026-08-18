<?php

namespace App\Support\Contract;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one place a `service_contracts` row's status_id is ever written after
 * creation - mirrors App\Support\Booking\BookingStatusMachine exactly: every
 * method requires the caller to have already locked the row (SELECT ... FOR
 * UPDATE), is a safe no-op - never a throw - when the row is not currently in
 * the required starting status, and every write uses a datetime(6)-safe
 * pre-formatted timestamp ($at->format('Y-m-d H:i:s.u')). This class never
 * writes `service_contract_status_history` itself - exactly like
 * BookingStatusMachine leaves `booking_status_history` to its callers, so
 * every transition's actor/reason stays with the Action that knows it.
 *
 * The allowed graph (BLUE V1 Phase 10B, extended by Phase 11 "PENDING_PAYMENT"):
 *
 *   REQUESTED -> APPROVED -> PENDING_CUSTOMER_ACCEPTANCE -> PENDING_PAYMENT -> ACTIVE
 *   ACTIVE -> SUSPENDED
 *   SUSPENDED -> ACTIVE (BLUE V1 Phase 11 "billing suspension recovery" ONLY -
 *     see recoverSuspendedByBillingToActive() docblock; BLUE V1 has no
 *     general-purpose Admin resume/reactivate endpoint)
 *   ACTIVE -> EXPIRED (lazy, term-ended check only - see
 *     App\Actions\Contract\Concerns\AppliesContractExpiry)
 *   {REQUESTED, APPROVED, PENDING_CUSTOMER_ACCEPTANCE, PENDING_PAYMENT, ACTIVE, SUSPENDED} -> CANCELLED
 *
 * PENDING_PAYMENT -> ACTIVE (BLUE V1 Phase 11) only ever fires from
 * App\Actions\Contract\Billing\ProcessContractBillingWebhookAction, on
 * authoritative Stripe proof the subscription's first invoice was paid -
 * never from App\Actions\Contract\AcceptContractAction, which now only
 * reaches PENDING_PAYMENT (customer acceptance no longer grants Contract
 * Booking entitlement by itself).
 *
 * No other transition is structurally possible. EXPIRED and CANCELLED are
 * both is_terminal=1 in the seed data, so neither method here ever accepts a
 * row already in either state as a starting point.
 */
final class ContractStatusMachine
{
    public function transitionToApproved(object $contract, Carbon $at): bool
    {
        return $this->transition($contract, $at, from: 'REQUESTED', to: 'APPROVED');
    }

    public function transitionToPendingAcceptance(object $contract, Carbon $at): bool
    {
        return $this->transition($contract, $at, from: 'APPROVED', to: 'PENDING_CUSTOMER_ACCEPTANCE');
    }

    public function transitionToPendingPayment(object $contract, Carbon $at): bool
    {
        return $this->transition($contract, $at, from: 'PENDING_CUSTOMER_ACCEPTANCE', to: 'PENDING_PAYMENT');
    }

    public function transitionToActive(object $contract, Carbon $at): bool
    {
        return $this->transition($contract, $at, from: 'PENDING_PAYMENT', to: 'ACTIVE');
    }

    public function transitionToSuspended(object $contract, Carbon $at): bool
    {
        return $this->transition($contract, $at, from: 'ACTIVE', to: 'SUSPENDED');
    }

    /**
     * SUSPENDED -> ACTIVE (BLUE V1 Phase 11 "billing suspension recovery"),
     * deliberately its own named method rather than a reuse of a generic
     * "reactivate" transition: BLUE V1 has no Admin resume/reactivate
     * endpoint, and this method must ONLY ever be called by
     * App\Actions\Contract\Billing\ProcessContractBillingWebhookAction after
     * it has verified BOTH that the Contract is currently SUSPENDED AND that
     * `service_contract_billings.billing_suspended_at` is not null (i.e. the
     * suspension was caused by App\Actions\Contract\Billing\
     * SuspendContractsPastDueBillingAction, never by an Admin manually
     * suspending the Contract via App\Actions\Admin\Contract\
     * AdminSuspendContractAction - that path never sets
     * billing_suspended_at, so it is never eligible here). A manually
     * suspended Contract must stay SUSPENDED even once its billing recovers.
     */
    public function recoverSuspendedByBillingToActive(object $contract, Carbon $at): bool
    {
        return $this->transition($contract, $at, from: 'SUSPENDED', to: 'ACTIVE');
    }

    public function transitionToExpired(object $contract, Carbon $at): bool
    {
        return $this->transition($contract, $at, from: 'ACTIVE', to: 'EXPIRED');
    }

    public function transitionToCancelled(object $contract, Carbon $at): bool
    {
        if (! in_array((int) $contract->status_id, [
            ContractStatuses::id('REQUESTED'),
            ContractStatuses::id('APPROVED'),
            ContractStatuses::id('PENDING_CUSTOMER_ACCEPTANCE'),
            ContractStatuses::id('PENDING_PAYMENT'),
            ContractStatuses::id('ACTIVE'),
            ContractStatuses::id('SUSPENDED'),
        ], true)) {
            return false;
        }

        return $this->transition($contract, $at, from: null, to: 'CANCELLED');
    }

    public function isInStatus(object $contract, string $code): bool
    {
        return (int) $contract->status_id === ContractStatuses::id($code);
    }

    private function transition(object $contract, Carbon $at, ?string $from, string $to): bool
    {
        if ($from !== null && (int) $contract->status_id !== ContractStatuses::id($from)) {
            return false;
        }

        $timestamp = $at->format('Y-m-d H:i:s.u');

        DB::table('service_contracts')->where('id', $contract->id)->update([
            'status_id' => ContractStatuses::id($to),
            'status_changed_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return true;
    }
}
