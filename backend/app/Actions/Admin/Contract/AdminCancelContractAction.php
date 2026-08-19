<?php

namespace App\Actions\Admin\Contract;

use App\Actions\Contract\Billing\CancelContractBillingSubscriptionAction;
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
 * APPROVED, PENDING_CUSTOMER_ACCEPTANCE, PENDING_PAYMENT, ACTIVE,
 * SUSPENDED). Already CANCELLED is a safe idempotent no-op; EXPIRED is not
 * cancellable (it is already terminal). Existing Bookings created while the
 * Contract was ACTIVE are never touched - cancellation only stops *new*
 * CONTRACT Bookings (see App\Actions\Contract\CreateContractBookingAction,
 * which only ever accepts ACTIVE). Audit logging only fires for the real
 * transition.
 *
 * BLUE V1 Phase 11 "CANCELLATION" (hardened for provider outages): the
 * operational Contract is cancelled immediately, in the same DB transaction
 * as before - the recurring-billing provider is never involved in, and can
 * never block or delay, that. The durable intent to also cancel the
 * provider-side Subscription is recorded in that SAME transaction (see
 * App\Actions\Contract\Billing\
 * CancelContractBillingSubscriptionAction::markCancellationRequested()) -
 * this class never itself reads a billing-provider-specific column or
 * names the provider (see that Action's docblock for why: Admin operations
 * source must never reference the billing provider by name). Only AFTER
 * that transaction commits does it make one best-effort delivery attempt
 * (CancelContractBillingSubscriptionAction::handle(), never inside the
 * transaction - a provider outage must never roll back an Admin's
 * cancellation, and a failed/ambiguous attempt must never lose the request
 * either). Fired ONLY on the real transition, never on the idempotent
 * already-CANCELLED no-op path above - a repeated Admin cancel call is
 * deliberately NOT how a failed delivery gets retried; that is the sole job
 * of App\Actions\Contract\Billing\RetryPendingContractBillingCancellationsAction
 * (`contracts:retry-pending-billing-cancellations`), which keeps retrying
 * for as long as the durably-recorded request stays unreconciled. This
 * never writes service_contract_billings.status_id itself - only the
 * eventual provider webhook (App\Actions\Contract\Billing\
 * ProcessContractBillingWebhookAction) does that, so this side channel can
 * never race or conflict with it.
 */
class AdminCancelContractAction
{
    use AppliesContractExpiry;
    use BuildsCartResult;

    public function __construct(
        private readonly CancelContractBillingSubscriptionAction $cancelBillingSubscription,
        private readonly ContractStatusMachine $machine = new ContractStatusMachine,
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

        $transitioned = false;
        $actorIdBinary = UuidBinary::toBinary($actor->id);

        $result = DB::transaction(function () use ($request, $contractUuid, $actor, $contractIdBinary, $actorIdBinary, $reason, &$transitioned): array {
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

            // Durably records the cancellation intent in the SAME
            // transaction as the Contract's own CANCELLED transition above -
            // see class docblock and CancelContractBillingSubscriptionAction's
            // docblock. A commit failure below rolls this back together with
            // the Contract transition, so the two can never disagree.
            $this->cancelBillingSubscription->markCancellationRequested($contract->id, $now);

            AdminAuditLogger::record(
                $request,
                $actor,
                'CONTRACT_CANCELLED',
                'SERVICE_CONTRACT',
                $contractUuid,
                ['reason' => $reason]
            );

            $transitioned = true;

            $updated = DB::table('service_contracts')->where('id', $contract->id)->first();

            return $this->ok(200, 'Service contract cancelled successfully.', ['contract' => AdminContractPresenter::detail($updated)]);
        });

        if ($transitioned) {
            // Best-effort, fire-and-forget FIRST delivery attempt for the
            // durably-recorded request above - see class docblock.
            // Deliberately outside the domain transaction and never allowed
            // to affect the HTTP response either way. If this attempt fails
            // or times out, the request stays durably pending (it was
            // already committed above) and
            // App\Actions\Contract\Billing\RetryPendingContractBillingCancellationsAction
            // picks it up later - this call is only ever the FIRST attempt,
            // never the only one.
            $this->cancelBillingSubscription->handle($contractIdBinary);
        }

        return $result;
    }
}
