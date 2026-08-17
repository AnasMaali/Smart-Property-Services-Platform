<?php

namespace App\Actions\Property;

use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Property\PropertyPresenter;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only, ownership-scoped Property lookup (GET /v1/properties/{property}).
 * A foreign or unknown Property UUID is reported identically as 404, never
 * 403, matching App\Actions\Booking\GetBookingAction's convention.
 *
 * Also returns every Service Contract ever requested against this Property
 * (a lightweight summary only - full covered-service/entitlement/booking
 * detail lives at GET /v1/contracts/{contract}, never duplicated here, to
 * avoid an N+1-prone deep nest on a screen whose job is "pick a property").
 */
class GetPropertyAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $userUuid, string $propertyUuid): array
    {
        try {
            $propertyIdBinary = UuidBinary::toBinary($propertyUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Property not found.');
        }

        $userIdBinary = UuidBinary::toBinary($userUuid);

        $property = DB::table('customer_properties')
            ->where('id', $propertyIdBinary)
            ->where('customer_user_id', $userIdBinary)
            ->first();

        if ($property === null) {
            return $this->notFound('Property not found.');
        }

        $contracts = DB::table('service_contracts')
            ->join('service_contract_statuses', 'service_contract_statuses.id', '=', 'service_contracts.status_id')
            ->where('service_contracts.customer_property_id', $propertyIdBinary)
            ->orderByDesc('service_contracts.created_at')
            ->get([
                'service_contracts.id',
                'service_contracts.contract_number',
                'service_contracts.starts_at',
                'service_contracts.ends_at',
                'service_contract_statuses.code as status_code',
            ]);

        return $this->ok(200, 'Property retrieved successfully.', [
            'property' => PropertyPresenter::present($property),
            'contracts' => $contracts->map(fn (object $contract): array => [
                'uuid' => UuidBinary::toString($contract->id),
                'contract_number' => $contract->contract_number,
                'status' => $contract->status_code,
                'starts_at' => $contract->starts_at === null ? null : Carbon::parse($contract->starts_at)->toIso8601String(),
                'ends_at' => $contract->ends_at === null ? null : Carbon::parse($contract->ends_at)->toIso8601String(),
            ])->all(),
        ]);
    }
}
