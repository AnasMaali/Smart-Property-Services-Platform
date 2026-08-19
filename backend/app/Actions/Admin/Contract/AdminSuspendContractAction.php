<?php

namespace App\Actions\Admin\Contract;

use App\Actions\Contract\Concerns\AppliesContractExpiry;
use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminMutationAuthorizationOutcome;
use App\Support\Admin\AdminMutationAuthorizer;
use App\Support\Admin\AdminContractPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Contract\ContractStatuses;
use App\Support\Contract\ContractStatusMachine;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Temporarily suspends an ACTIVE Service Contract (POST
 * /v1/admin/contracts/{contract}/suspend, BLUE V1 Phase 10E) - a suspended
 * Contract does not authorize new CONTRACT Bookings (see
 * App\Actions\Contract\CreateContractBookingAction, which only ever accepts
 * ACTIVE) but is not cancelled and keeps its existing Bookings untouched.
 * ACTIVE -> SUSPENDED only; already SUSPENDED is a safe idempotent no-op.
 * Lazy-expires the Contract first (see App\Actions\Contract\Concerns\
 * AppliesContractExpiry) so a Contract whose term has already ended is
 * correctly reported as "cannot be suspended" rather than being suspended
 * past its own expiry. Audit logging only fires for the real transition
 * and commits atomically with that privileged state change.
 */
class AdminSuspendContractAction
{
    use AppliesContractExpiry;
    use BuildsCartResult;

    public function __construct(
        private readonly ContractStatusMachine $machine = new ContractStatusMachine,
        private readonly AdminMutationAuthorizer $mutationAuthorizer = new AdminMutationAuthorizer,
    ) {}

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

        $actorIdBinary = UuidBinary::toBinary($actor->id);

        return DB::transaction(function () use ($request, $contractUuid, $actor, $contractIdBinary, $actorIdBinary, $reason): array {
            $authorization = $this->mutationAuthorizer->checkBinary($actorIdBinary);

            if ($authorization !== AdminMutationAuthorizationOutcome::AUTHORIZED) {
                return $this->forbidden();
            }

            $contract = DB::table('service_contracts')->where('id', $contractIdBinary)->lockForUpdate()->first();

            if ($contract === null) {
                return $this->notFound('Service contract not found.');
            }

            $now = now();
            $contract = $this->applyLazyExpiry($contract, $now);

            if ($this->machine->isInStatus($contract, 'SUSPENDED')) {
                return $this->ok(200, 'Service contract already suspended.', ['contract' => AdminContractPresenter::detail($contract)]);
            }

            if (! $this->machine->isInStatus($contract, 'ACTIVE')) {
                return $this->conflict('This contract cannot be suspended from its current status.');
            }

            $timestamp = $now->format('Y-m-d H:i:s.u');

            $this->machine->transitionToSuspended($contract, $now);

            DB::table('service_contract_status_history')->insert([
                'id' => UuidBinary::toBinary(UuidBinary::generate()),
                'service_contract_id' => $contract->id,
                'from_status_id' => ContractStatuses::id('ACTIVE'),
                'to_status_id' => ContractStatuses::id('SUSPENDED'),
                'changed_by_user_id' => $actorIdBinary,
                'reason' => $reason ?? 'Suspended by admin.',
                'changed_at' => $timestamp,
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'CONTRACT_SUSPENDED',
                'SERVICE_CONTRACT',
                $contractUuid,
                ['reason' => $reason]
            );

            $updated = DB::table('service_contracts')->where('id', $contract->id)->first();

            return $this->ok(200, 'Service contract suspended successfully.', ['contract' => AdminContractPresenter::detail($updated)]);
        });
    }

    /**
     * @return array{success: bool, status: int, message: string, data: null}
     */
    private function forbidden(): array
    {
        return [
            'success' => false,
            'status' => 403,
            'message' => 'You are not authorized to perform this action.',
            'data' => null,
        ];
    }

}
