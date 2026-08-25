<?php

namespace App\Actions\Admin\Property;

use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Property\PropertyPresenter;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only Admin Property detail lookup (BLUE V1 Phase B6) - unlike
 * App\Actions\Property\GetPropertyAction, never ownership-scoped to the
 * authenticated caller (there is no "authenticated caller who owns this"
 * concept for an Admin). Reuses App\Support\Property\PropertyPresenter::
 * present() verbatim - that presenter is already ownership-independent
 * pure presentation, so no `AdminPropertyPresenter` was needed. Mirrors
 * `GetPropertyAction`'s own linked-Contracts summary for the same reason
 * that Action includes it (a lightweight cross-reference, never a full
 * duplicate of the Contract detail screen).
 */
final class AdminGetPropertyAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $propertyUuid): array
    {
        try {
            $propertyIdBinary = UuidBinary::toBinary($propertyUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Property not found.');
        }

        $property = DB::table('customer_properties')->where('id', $propertyIdBinary)->first();

        if ($property === null) {
            return $this->notFound('Property not found.');
        }

        $customer = DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->where('users.id', $property->customer_user_id)
            ->first(['users.id', 'users.phone_number', 'users.email', 'user_profiles.full_name']);

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
            'customer' => $customer === null ? null : [
                'uuid' => UuidBinary::toString($customer->id),
                'full_name' => $customer->full_name,
                'phone_number' => $customer->phone_number,
                'email' => $customer->email,
            ],
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
