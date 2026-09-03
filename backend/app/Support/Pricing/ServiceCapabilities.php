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

    /**
     * Batch capability lookup for catalog list payloads. Keyed by
     * bin2hex(service_id) so callers can join without re-encoding UUIDs.
     *
     * @param  array<int, string>  $serviceIdsBinary
     * @return array<string, list<string>>
     */
    public function codesByServiceId(array $serviceIdsBinary): array
    {
        if ($serviceIdsBinary === []) {
            return [];
        }

        $rows = DB::table('service_capabilities')
            ->join('service_capability_types', 'service_capability_types.id', '=', 'service_capabilities.capability_type_id')
            ->whereIn('service_capabilities.service_id', $serviceIdsBinary)
            ->where('service_capability_types.is_active', 1)
            ->orderBy('service_capability_types.code')
            ->get(['service_capabilities.service_id', 'service_capability_types.code']);

        $grouped = [];

        foreach ($rows as $row) {
            $key = bin2hex($row->service_id);
            $grouped[$key][] = $row->code;
        }

        return $grouped;
    }
}
