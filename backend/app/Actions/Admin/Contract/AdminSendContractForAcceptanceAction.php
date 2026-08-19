<?php

namespace App\Actions\Admin\Contract;

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
 * Sends a finalized (APPROVED) Service Contract to the customer for
 * acceptance (POST /v1/admin/contracts/{contract}/send-for-acceptance, BLUE
 * V1 Phase 10E). APPROVED -> PENDING_CUSTOMER_ACCEPTANCE only; already
 * PENDING_CUSTOMER_ACCEPTANCE is a safe idempotent no-op. Kept as its own
 * route rather than folded into approve() because BLUE V1 Phase 10D's
 * required route surface lists both explicitly and an Admin may legitimately
 * want to review a freshly-approved contract before actually notifying the
 * customer. Audit logging only fires for the real transition, never the
 * idempotent no-op, and is committed atomically with that transition - see
 * App\Actions\Admin\Contract\AdminApproveContractAction.
 */
class AdminSendContractForAcceptanceAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly ContractStatusMachine $machine = new ContractStatusMachine,
        private readonly AdminMutationAuthorizer $mutationAuthorizer = new AdminMutationAuthorizer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request, string $contractUuid, User $actor): array
    {
        try {
            $contractIdBinary = UuidBinary::toBinary($contractUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service contract not found.');
        }

        $actorIdBinary = UuidBinary::toBinary($actor->id);

        return DB::transaction(function () use ($request, $contractUuid, $actor, $contractIdBinary, $actorIdBinary): array {
            $authorization = $this->mutationAuthorizer->checkBinary($actorIdBinary);

            if ($authorization !== AdminMutationAuthorizationOutcome::AUTHORIZED) {
                return $this->forbidden();
            }

            $contract = DB::table('service_contracts')->where('id', $contractIdBinary)->lockForUpdate()->first();

            if ($contract === null) {
                return $this->notFound('Service contract not found.');
            }

            if ($this->machine->isInStatus($contract, 'PENDING_CUSTOMER_ACCEPTANCE')) {
                return $this->ok(200, 'Service contract already sent for acceptance.', ['contract' => AdminContractPresenter::detail($contract)]);
            }

            if (! $this->machine->isInStatus($contract, 'APPROVED')) {
                return $this->conflict('This contract cannot be sent for acceptance from its current status.');
            }

            $now = now();
            $timestamp = $now->format('Y-m-d H:i:s.u');

            $this->machine->transitionToPendingAcceptance($contract, $now);

            DB::table('service_contract_status_history')->insert([
                'id' => UuidBinary::toBinary(UuidBinary::generate()),
                'service_contract_id' => $contract->id,
                'from_status_id' => ContractStatuses::id('APPROVED'),
                'to_status_id' => ContractStatuses::id('PENDING_CUSTOMER_ACCEPTANCE'),
                'changed_by_user_id' => $actorIdBinary,
                'reason' => 'Sent to customer for acceptance.',
                'changed_at' => $timestamp,
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'CONTRACT_SENT_FOR_ACCEPTANCE',
                'SERVICE_CONTRACT',
                $contractUuid
            );

            $updated = DB::table('service_contracts')->where('id', $contract->id)->first();

            return $this->ok(200, 'Service contract sent for customer acceptance.', ['contract' => AdminContractPresenter::detail($updated)]);
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
