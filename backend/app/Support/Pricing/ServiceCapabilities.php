<?php

namespace App\Support\Pricing;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * Generic capability lookup: "does this service have capability X" as a
 * data query, never a hardcoded per-service branch. Business logic that
 * used to need bespoke emergency/subscription booleans queries this instead
 * (e.g. ServiceCapabilities::has($serviceId, 'CART_ELIGIBLE')).
 */
final class ServiceCapabilities
{
    public function has(string $serviceIdUuid, string $capabilityCode): bool
    {
        return DB::table('service_capabilities')
            ->join('service_capability_types', 'service_capability_types.id', '=', 'service_capabilities.capability_type_id')
            ->where('service_capabilities.service_id', UuidBinary::toBinary($serviceIdUuid))
            ->where('service_capability_types.code', $capabilityCode)
            ->where('service_capability_types.is_active', 1)
            ->exists();
    }

    /**
     * @return array<int, string> Active capability codes for this service.
     */
    public function codesFor(string $serviceIdUuid): array
    {
        return DB::table('service_capabilities')
            ->join('service_capability_types', 'service_capability_types.id', '=', 'service_capabilities.capability_type_id')
            ->where('service_capabilities.service_id', UuidBinary::toBinary($serviceIdUuid))
            ->where('service_capability_types.is_active', 1)
            ->orderBy('service_capability_types.code')
            ->pluck('service_capability_types.code')
            ->all();
    }
}
