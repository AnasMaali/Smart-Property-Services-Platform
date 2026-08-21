<?php

namespace App\Actions\Contract;

use App\Support\Auth\PendingAccountDeletionGuard;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Contract\ContractNumberGenerator;
use App\Support\Contract\ContractPresenter;
use App\Support\Contract\ContractStatuses;
use App\Support\Pricing\ServiceCapabilities;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Customer-initiated Service Contract request (POST /v1/contracts/requests,
 * BLUE V1 Phase 10D). Creates the `service_contracts` row in REQUESTED only
 * - never writes `service_contract_items`. The customer's requested service
 * selection (explicit list, or "all currently eligible services") is stored
 * only as an informational `requested_service_ids` / `requested_all_services`
 * snapshot for Admin's reference; the authoritative, historical
 * `service_contract_items` rows are created later by
 * App\Actions\Admin\Contract\AdminApproveContractAction, exactly once, at
 * approval time - "all services" is deliberately never re-resolved after
 * that (see that Action's docblock).
 *
 * CONTRACT eligibility reuses the existing generic capability mechanism
 * (App\Support\Pricing\ServiceCapabilities) against the already-seeded
 * `SUBSCRIPTION` capability code - no new schema was needed for this.
 */
class RequestContractAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly ServiceCapabilities $capabilities = new ServiceCapabilities,
        private readonly PendingAccountDeletionGuard $deletionGuard = new PendingAccountDeletionGuard,
    ) {}

    /**
     * BLUE V1 Phase 13: wrapped in a transaction that locks `users` first
     * (previously this Action took no lock at all) specifically so the
     * PendingAccountDeletionGuard check below cannot race against
     * DeleteAccountAction's own `users` lock - see
     * PendingAccountDeletionGuard's class docblock for why locking only
     * this one shared row, before any other resource, cannot introduce a
     * deadlock with any other lock chain in this codebase.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function handle(string $userUuid, array $data): array
    {
        $userIdBinary = UuidBinary::toBinary($userUuid);

        return DB::transaction(function () use ($userIdBinary, $data) {
            $userExists = DB::table('users')->where('id', $userIdBinary)->lockForUpdate()->exists();

            if (! $userExists) {
                throw new RuntimeException("Authenticated user with binary id not found.");
            }

            if ($this->deletionGuard->isPending($userIdBinary)) {
                return $this->conflict(PendingAccountDeletionGuard::REJECTION_MESSAGE);
            }

            return $this->createContractRequest($userIdBinary, $data);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function createContractRequest(string $userIdBinary, array $data): array
    {
        try {
            $propertyIdBinary = UuidBinary::toBinary($data['property_uuid']);
        } catch (InvalidArgumentException) {
            return $this->notFound('Property not found.');
        }

        $property = DB::table('customer_properties')
            ->where('id', $propertyIdBinary)
            ->where('customer_user_id', $userIdBinary)
            ->first(['id', 'is_active']);

        if ($property === null) {
            return $this->notFound('Property not found.');
        }

        if ((int) $property->is_active !== 1) {
            return $this->conflict('An archived property cannot be used for a new Contract request.');
        }

        $allServices = (bool) ($data['all_services'] ?? false);

        if ($allServices) {
            $eligibleServiceUuids = DB::table('services')
                ->join('service_capabilities', 'service_capabilities.service_id', '=', 'services.id')
                ->join('service_capability_types', 'service_capability_types.id', '=', 'service_capabilities.capability_type_id')
                ->where('services.is_active', 1)
                ->where('service_capability_types.code', 'SUBSCRIPTION')
                ->where('service_capability_types.is_active', 1)
                ->pluck('services.id')
                ->map(fn ($id) => UuidBinary::toString($id))
                ->all();

            if ($eligibleServiceUuids === []) {
                return $this->unprocessable('No contract-eligible services are currently available.', ['service_uuids' => ['No contract-eligible services are currently available.']]);
            }
        } else {
            $requestedUuids = $data['service_uuids'] ?? [];
            $eligibleServiceUuids = [];
            $invalid = [];

            foreach ($requestedUuids as $serviceUuid) {
                try {
                    $serviceIdBinary = UuidBinary::toBinary($serviceUuid);
                } catch (InvalidArgumentException) {
                    $invalid[] = $serviceUuid;

                    continue;
                }

                $exists = DB::table('services')->where('id', $serviceIdBinary)->where('is_active', 1)->exists();

                if (! $exists || ! $this->capabilities->has($serviceUuid, 'SUBSCRIPTION')) {
                    $invalid[] = $serviceUuid;

                    continue;
                }

                $eligibleServiceUuids[] = $serviceUuid;
            }

            if ($invalid !== []) {
                return $this->unprocessable('One or more requested services are not eligible for a Service Contract.', ['service_uuids' => $invalid]);
            }
        }

        $now = now();
        $contractIdBinary = UuidBinary::toBinary(UuidBinary::generate());

        try {
            DB::table('service_contracts')->insert([
                'id' => $contractIdBinary,
                'contract_number' => ContractNumberGenerator::generate(),
                'customer_user_id' => $userIdBinary,
                'customer_property_id' => $propertyIdBinary,
                'status_id' => ContractStatuses::id('REQUESTED'),
                'status_changed_at' => $now,
                'requested_service_ids' => json_encode($eligibleServiceUuids),
                'requested_all_services' => $allServices ? 1 : 0,
                'requested_starts_on' => isset($data['desired_start_date']) ? Carbon::parse($data['desired_start_date'])->toDateString() : null,
                'customer_note' => $data['customer_note'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->conflict('Could not generate a unique contract number. Please try again.');
        }

        DB::table('service_contract_status_history')->insert([
            'id' => UuidBinary::toBinary(UuidBinary::generate()),
            'service_contract_id' => $contractIdBinary,
            'from_status_id' => null,
            'to_status_id' => ContractStatuses::id('REQUESTED'),
            'changed_by_user_id' => $userIdBinary,
            'reason' => 'Customer requested a service contract.',
            'changed_at' => $now,
        ]);

        $contract = DB::table('service_contracts')->where('id', $contractIdBinary)->first();

        return $this->ok(201, 'Service contract requested successfully.', ['contract' => ContractPresenter::detail($contract, $now)]);
    }
}
