<?php

namespace App\Actions\Admin\Contract;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminContractPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Contract\Billing\ContractBillingStatuses;
use App\Support\Contract\Billing\Gateway\ContractBillingGateway;
use App\Support\Contract\ContractStatuses;
use App\Support\Contract\ContractStatusMachine;
use App\Support\Pricing\ServiceCapabilities;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Admin finalization of a requested Service Contract (POST
 * /v1/admin/contracts/{contract}/approve, BLUE V1 Phase 10E). This is the
 * one place `service_contract_items` rows are ever created - a "all
 * currently eligible services" customer request is resolved into explicit,
 * immutable rows here, at this exact moment, never re-resolved afterwards
 * (BLUE V1 Phase 10C). REQUESTED -> APPROVED only; already-APPROVED is a
 * safe idempotent no-op (V1 has no "amend an approved contract" endpoint -
 * items are write-once).
 *
 * BLUE V1 Phase 11: this is also the one place a `service_contract_billings`
 * row is ever created - the Admin's billing_interval/recurring_amount/
 * billing_currency_code become an immutable commercial snapshot the exact
 * same way service_contract_items already are. The row starts
 * PENDING_CHECKOUT with no billing-provider references at all; nothing
 * here ever calls the billing provider (this file must never reference it
 * by name - see tests\Feature\Admin\AdminFinancialIsolationTest) -
 * App\Actions\Contract\Billing\CreateContractBillingCheckoutAction is the
 * only place a provider-side subscription checkout is created, once the
 * customer has accepted and the Contract has reached PENDING_PAYMENT.
 *
 * Audit logging mirrors App\Actions\Admin\Technician\
 * AdminAssignTechnicianAction: a deliberate, immediate second write AFTER
 * the domain transaction has already committed, and only ever for the real
 * REQUESTED -> APPROVED transition - never for the idempotent no-op path -
 * so a retried call never produces a duplicate audit row.
 *
 * Legacy-data backstop (Phase 11 migration safety): every APPROVED Contract
 * is structurally guaranteed to already have a `service_contract_billings`
 * row - either this Action just created one (the real transition, above),
 * or `database/phase11_contract_billing_migration.sql`'s preflight
 * assertion already refused to run unless every pre-existing APPROVED/
 * PENDING_CUSTOMER_ACCEPTANCE/ACTIVE/SUSPENDED Contract had one backfilled
 * first. The already-APPROVED idempotent-return branch below still
 * re-checks this explicitly rather than trusting that invariant blindly -
 * if it is ever somehow violated (a corrupted row, a manual DB edit), this
 * Action reports it and returns a conflict instead of silently returning a
 * `200` for a Contract that can never reach PENDING_PAYMENT/ACTIVE.
 */
class AdminApproveContractAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly ContractBillingGateway $billingGateway,
        private readonly ContractStatusMachine $machine = new ContractStatusMachine,
        private readonly ServiceCapabilities $capabilities = new ServiceCapabilities,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function handle(Request $request, string $contractUuid, User $actor, array $data): array
    {
        try {
            $contractIdBinary = UuidBinary::toBinary($contractUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service contract not found.');
        }

        $transitioned = false;
        $actorIdBinary = UuidBinary::toBinary($actor->id);

        $result = DB::transaction(function () use ($contractIdBinary, $actorIdBinary, $data, &$transitioned): array {
            $contract = DB::table('service_contracts')->where('id', $contractIdBinary)->lockForUpdate()->first();

            if ($contract === null) {
                return $this->notFound('Service contract not found.');
            }

            if ($this->machine->isInStatus($contract, 'APPROVED')) {
                $hasBilling = DB::table('service_contract_billings')->where('service_contract_id', $contract->id)->exists();

                if (! $hasBilling) {
                    // See class docblock "Legacy-data backstop" - structurally
                    // unreachable once the Phase 11 migration's preflight
                    // assertion has run, but never silently treated as a
                    // successful no-op if it somehow happens anyway.
                    report(new RuntimeException(
                        'Data inconsistency: Service Contract '.UuidBinary::toString($contract->id).' is APPROVED but has no service_contract_billings row.'
                    ));

                    return $this->conflict('This contract is missing its billing configuration and cannot be processed. Contact support.');
                }

                return $this->ok(200, 'Service contract already approved.', ['contract' => AdminContractPresenter::detail($contract)]);
            }

            if (! $this->machine->isInStatus($contract, 'REQUESTED')) {
                return $this->conflict('This contract cannot be approved from its current status.');
            }

            $services = [];

            foreach ($data['services'] as $requested) {
                try {
                    $serviceIdBinary = UuidBinary::toBinary($requested['service_uuid']);
                } catch (InvalidArgumentException) {
                    return $this->unprocessable('One or more services are invalid.', ['services' => [$requested['service_uuid']]]);
                }

                $service = DB::table('services')->where('id', $serviceIdBinary)->where('is_active', 1)->first(['id', 'code', 'name']);

                if ($service === null || ! $this->capabilities->has($requested['service_uuid'], 'SUBSCRIPTION')) {
                    return $this->unprocessable('One or more services are not eligible for a service contract.', ['services' => [$requested['service_uuid']]]);
                }

                $entitlementMode = $requested['entitlement_mode'];
                $includedVisits = $requested['included_visits'] ?? null;

                if ($entitlementMode === 'LIMITED_VISITS' && ($includedVisits === null || (int) $includedVisits < 1)) {
                    return $this->unprocessable('LIMITED_VISITS services require a positive included_visits value.', ['services' => [$requested['service_uuid']]]);
                }

                if ($entitlementMode === 'UNLIMITED' && $includedVisits !== null) {
                    return $this->unprocessable('UNLIMITED services must not specify included_visits.', ['services' => [$requested['service_uuid']]]);
                }

                $services[] = [
                    'service_id' => $service->id,
                    'service_code_snapshot' => $service->code,
                    'service_name_snapshot' => $service->name,
                    'entitlement_mode' => $entitlementMode,
                    'included_visits' => $entitlementMode === 'LIMITED_VISITS' ? (int) $includedVisits : null,
                ];
            }

            $currencyId = null;

            if (isset($data['quoted_amount'])) {
                $currencyCode = $data['currency_code'] ?? DB::table('currencies')->where('is_active', 1)->orderBy('id')->value('code');
                $currencyId = DB::table('currencies')->where('code', $currencyCode)->where('is_active', 1)->value('id');

                if ($currencyId === null) {
                    return $this->unprocessable('The selected currency is invalid or inactive.', ['currency_code' => [$currencyCode]]);
                }
            }

            $billingCurrency = DB::table('currencies')->where('code', $data['billing_currency_code'])->where('is_active', 1)->first(['id']);

            if ($billingCurrency === null) {
                return $this->unprocessable('The selected billing currency is invalid or inactive.', ['billing_currency_code' => [$data['billing_currency_code']]]);
            }

            $now = now();
            $timestamp = $now->format('Y-m-d H:i:s.u');

            DB::table('service_contracts')->where('id', $contract->id)->update([
                'starts_at' => Carbon::parse($data['starts_at'])->format('Y-m-d H:i:s.u'),
                'ends_at' => Carbon::parse($data['ends_at'])->format('Y-m-d H:i:s.u'),
                'term_months' => $data['term_months'] ?? null,
                'quoted_amount' => $data['quoted_amount'] ?? null,
                'currency_id' => $currencyId,
                'internal_note' => $data['internal_note'] ?? $contract->internal_note,
                'updated_at' => $timestamp,
            ]);

            foreach ($services as $service) {
                DB::table('service_contract_items')->insert([
                    'id' => UuidBinary::toBinary(UuidBinary::generate()),
                    'service_contract_id' => $contract->id,
                    'service_id' => $service['service_id'],
                    'service_code_snapshot' => $service['service_code_snapshot'],
                    'service_name_snapshot' => $service['service_name_snapshot'],
                    'entitlement_mode' => $service['entitlement_mode'],
                    'included_visits' => $service['included_visits'],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }

            DB::table('service_contract_billings')->insert([
                'id' => UuidBinary::toBinary(UuidBinary::generate()),
                'service_contract_id' => $contract->id,
                'provider_code' => $this->billingGateway->providerCode(),
                'status_id' => ContractBillingStatuses::id('PENDING_CHECKOUT'),
                'billing_interval' => $data['billing_interval'],
                'recurring_amount' => $data['recurring_amount'],
                'currency_id' => $billingCurrency->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $this->machine->transitionToApproved($contract, $now);

            DB::table('service_contract_status_history')->insert([
                'id' => UuidBinary::toBinary(UuidBinary::generate()),
                'service_contract_id' => $contract->id,
                'from_status_id' => ContractStatuses::id('REQUESTED'),
                'to_status_id' => ContractStatuses::id('APPROVED'),
                'changed_by_user_id' => $actorIdBinary,
                'reason' => 'Approved by admin.',
                'changed_at' => $timestamp,
            ]);

            $transitioned = true;

            $updated = DB::table('service_contracts')->where('id', $contract->id)->first();

            return $this->ok(200, 'Service contract approved successfully.', ['contract' => AdminContractPresenter::detail($updated)]);
        });

        if ($transitioned) {
            AdminAuditLogger::record($request, $actor, 'CONTRACT_APPROVED', 'SERVICE_CONTRACT', $contractUuid, [
                'services_count' => count($data['services']),
            ]);
        }

        return $result;
    }
}
