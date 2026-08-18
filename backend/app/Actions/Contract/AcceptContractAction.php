<?php

namespace App\Actions\Contract;

use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Contract\ContractPresenter;
use App\Support\Contract\ContractStatuses;
use App\Support\Contract\ContractStatusMachine;
use App\Support\Payment\CanonicalJson;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Customer acceptance of a Service Contract (POST /v1/contracts/{contract}/accept,
 * BLUE V1 Phase 10D). No advanced e-signature provider in V1 - "acceptance"
 * is the authenticated customer's own action against their own Contract,
 * persisted as an immutable snapshot (mirrors payment_attempts.
 * checkout_snapshot / checkout_snapshot_hash's "freeze what was agreed to,
 * hash it" pattern via App\Support\Payment\CanonicalJson).
 *
 * BLUE V1 Phase 11: acceptance alone no longer activates the Contract or
 * grants Contract Booking entitlement. It only records the agreement
 * snapshot/hash exactly as before and transitions
 * PENDING_CUSTOMER_ACCEPTANCE -> PENDING_PAYMENT - the Contract only
 * becomes ACTIVE once App\Actions\Contract\Billing\
 * ProcessContractBillingWebhookAction observes authoritative Stripe proof
 * that the subscription's first invoice was paid (see
 * App\Support\Contract\ContractStatusMachine::transitionToActive()). A
 * Contract sitting PENDING_PAYMENT authorizes no CONTRACT Booking - see
 * App\Actions\Contract\CreateContractBookingAction, which only ever accepts
 * ACTIVE.
 *
 * Idempotent two ways: (1) an application-level check - a Contract already
 * PENDING_PAYMENT or further along (ACTIVE/SUSPENDED/EXPIRED) with an
 * existing acceptance row is a safe no-op that returns the current state;
 * (2) a DB backstop - UNIQUE(service_contract_id) on
 * `service_contract_acceptances` resolves the narrow window where two
 * concurrent accept calls both reach the insert, exactly like
 * App\Actions\Booking\CreateBookingFromSuccessfulPaymentAction::resolveInsertRace().
 */
class AcceptContractAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly ContractStatusMachine $machine = new ContractStatusMachine,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $userUuid, string $contractUuid): array
    {
        try {
            $contractIdBinary = UuidBinary::toBinary($contractUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service contract not found.');
        }

        $userIdBinary = UuidBinary::toBinary($userUuid);

        try {
            return DB::transaction(function () use ($contractIdBinary, $userIdBinary): array {
                $contract = DB::table('service_contracts')
                    ->where('id', $contractIdBinary)
                    ->where('customer_user_id', $userIdBinary)
                    ->lockForUpdate()
                    ->first();

                if ($contract === null) {
                    return $this->notFound('Service contract not found.');
                }

                if ($contract->accepted_at !== null) {
                    // Already accepted (PENDING_PAYMENT or further along -
                    // ACTIVE/SUSPENDED/EXPIRED/CANCELLED) - a safe, idempotent
                    // no-op that returns the current state rather than
                    // re-recording acceptance or re-transitioning.
                    return $this->ok(200, 'Service contract already accepted.', ['contract' => ContractPresenter::detail($contract, now())]);
                }

                if (! $this->machine->isInStatus($contract, 'PENDING_CUSTOMER_ACCEPTANCE')) {
                    return $this->conflict('This contract cannot be accepted from its current status.');
                }

                $items = DB::table('service_contract_items')->where('service_contract_id', $contract->id)->orderBy('created_at')->get();

                if ($items->isEmpty() || $contract->starts_at === null || $contract->ends_at === null) {
                    return $this->conflict('This contract is not ready for acceptance yet.');
                }

                $now = now();
                $timestamp = $now->format('Y-m-d H:i:s.u');

                $snapshot = [
                    'contract_number' => $contract->contract_number,
                    'starts_at' => (string) $contract->starts_at,
                    'ends_at' => (string) $contract->ends_at,
                    'term_months' => $contract->term_months === null ? null : (int) $contract->term_months,
                    'quoted_amount' => $contract->quoted_amount,
                    'items' => $items->map(fn (object $item): array => [
                        'service_uuid' => UuidBinary::toString($item->service_id),
                        'service_code' => $item->service_code_snapshot,
                        'entitlement_mode' => $item->entitlement_mode,
                        'included_visits' => $item->included_visits === null ? null : (int) $item->included_visits,
                    ])->all(),
                ];

                $canonicalSnapshot = CanonicalJson::encode($snapshot);
                $hash = CanonicalJson::sha256($canonicalSnapshot);

                DB::table('service_contract_acceptances')->insert([
                    'id' => UuidBinary::toBinary(UuidBinary::generate()),
                    'service_contract_id' => $contract->id,
                    'accepted_by_user_id' => $userIdBinary,
                    'agreement_snapshot' => $canonicalSnapshot,
                    'agreement_hash' => $hash,
                    'accepted_at' => $timestamp,
                    'created_at' => $timestamp,
                ]);

                $this->machine->transitionToPendingPayment($contract, $now);

                DB::table('service_contracts')->where('id', $contract->id)->update([
                    'accepted_at' => $timestamp,
                    'accepted_by_user_id' => $userIdBinary,
                    'agreement_snapshot' => $canonicalSnapshot,
                    'agreement_hash' => $hash,
                    'updated_at' => $timestamp,
                ]);

                DB::table('service_contract_status_history')->insert([
                    'id' => UuidBinary::toBinary(UuidBinary::generate()),
                    'service_contract_id' => $contract->id,
                    'from_status_id' => ContractStatuses::id('PENDING_CUSTOMER_ACCEPTANCE'),
                    'to_status_id' => ContractStatuses::id('PENDING_PAYMENT'),
                    'changed_by_user_id' => $userIdBinary,
                    'reason' => 'Customer accepted contract terms; awaiting Stripe subscription payment.',
                    'changed_at' => $timestamp,
                ]);

                $updated = DB::table('service_contracts')->where('id', $contract->id)->first();

                return $this->ok(200, 'Service contract accepted successfully; complete billing checkout to activate it.', ['contract' => ContractPresenter::detail($updated, $now)]);
            });
        } catch (UniqueConstraintViolationException) {
            $refreshed = DB::table('service_contracts')->where('id', $contractIdBinary)->first();

            return $this->ok(200, 'Service contract already accepted.', ['contract' => ContractPresenter::detail($refreshed, now())]);
        }
    }
}
