<?php

namespace App\Actions\Admin\Contract;

use App\Actions\Contract\Concerns\AppliesContractExpiry;
use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminContractPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Contract\ContractStatuses;
use App\Support\Contract\ContractStatusMachine;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cancels a Service Contract (POST /v1/admin/contracts/{contract}/cancel,
 * BLUE V1 Phase 10E) - reachable from any non-terminal status (REQUESTED,
 * APPROVED, PENDING_CUSTOMER_ACCEPTANCE, ACTIVE, SUSPENDED). Already
 * CANCELLED is a safe idempotent no-op; EXPIRED is not cancellable (it is
 * already terminal). Existing Bookings created while the Contract was
 * ACTIVE are never touched - cancellation only stops *new* CONTRACT
 * Bookings (see App\Actions\Contract\CreateContractBookingAction, which
 * only ever accepts ACTIVE). Audit logging only fires for the real
 * transition.
 */
class AdminCancelContractAction
{
    use AppliesContractExpiry;
    use BuildsCartResult;

    public function __construct(private readonly ContractStatusMachine $machine = new ContractStatusMachine) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request, string $contractUuid, User $actor, ?string $reason): array
    {
        try {
            $contractIdBinary = UuidBinary::toBinary($contractUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service contract not found.');
        }

        $transitioned = false;
        $actorIdBinary = UuidBinary::toBinary($actor->id);

        $result = DB::transaction(function () use ($contractIdBinary, $actorIdBinary, $reason, &$transitioned): array {
            $contract = DB::table('service_contracts')->where('id', $contractIdBinary)->lockForUpdate()->first();

            if ($contract === null) {
                return $this->notFound('Service contract not found.');
            }

            $now = now();
            $contract = $this->applyLazyExpiry($contract, $now);

            if ($this->machine->isInStatus($contract, 'CANCELLED')) {
                return $this->ok(200, 'Service contract already cancelled.', ['contract' => AdminContractPresenter::detail($contract)]);
            }

            $fromStatusId = (int) $contract->status_id;

            if (! $this->machine->transitionToCancelled($contract, $now)) {
                return $this->conflict('This contract cannot be cancelled from its current status.');
            }

            $timestamp = $now->format('Y-m-d H:i:s.u');

            DB::table('service_contract_status_history')->insert([
                'id' => UuidBinary::toBinary(UuidBinary::generate()),
                'service_contract_id' => $contract->id,
                'from_status_id' => $fromStatusId,
                'to_status_id' => ContractStatuses::id('CANCELLED'),
                'changed_by_user_id' => $actorIdBinary,
                'reason' => $reason ?? 'Cancelled by admin.',
                'changed_at' => $timestamp,
            ]);

            $transitioned = true;

            $updated = DB::table('service_contracts')->where('id', $contract->id)->first();

            return $this->ok(200, 'Service contract cancelled successfully.', ['contract' => AdminContractPresenter::detail($updated)]);
        });

        if ($transitioned) {
            AdminAuditLogger::record($request, $actor, 'CONTRACT_CANCELLED', 'SERVICE_CONTRACT', $contractUuid, ['reason' => $reason]);
        }

        return $result;
    }
}
