<?php

namespace App\Support\Checkout;

use App\Models\CartLocation;
use Illuminate\Support\Facades\DB;

/**
 * Resolves PricingEngine context attributes exclusively from data the
 * backend already trusts - never from client input, never from the
 * customer's profile (see docs/api-contracts/checkout-v1.md "Pricing
 * context trust boundary"). Both attributes resolve to the referenced
 * lookup row's numeric id - opaque to this resolver, matched by whatever
 * pricing_rule_conditions an admin has configured against it, exactly like
 * every other CONTEXT_ATTRIBUTE value in the system.
 *
 * SERVICE_ZONE: cart_locations.area_id -> service_zone_areas -> an active
 * service_zones row. `service_zone_areas.area_id` is that table's primary
 * key, so the schema itself guarantees at most one zone per area - there is
 * no "if area == X" branch here, only a lookup. If the area has no mapping,
 * or its mapped zone has since been deactivated, SERVICE_ZONE is left
 * unresolved (never guessed).
 *
 * TIME_WINDOW: the cart's current active appointment hold -> its
 * appointment_slots row -> that slot's appointment_time_windows row, only
 * when the window is still active. Flutter never submits a time window
 * directly - only appointment_slot_uuid - so there is nothing to spoof.
 */
final class CheckoutContextResolver
{
    /**
     * @param  ?object  $heldSlot  The active hold's appointment_slots row (must expose time_window_id), or null when there is no usable hold.
     * @return array<string, string>
     */
    public function resolve(?CartLocation $location, ?object $heldSlot): array
    {
        $context = [];

        $serviceZoneId = $location === null ? null : $this->resolveServiceZone((int) $location->area_id);

        if ($serviceZoneId !== null) {
            $context['SERVICE_ZONE'] = (string) $serviceZoneId;
        }

        $timeWindowId = $heldSlot === null ? null : $this->resolveTimeWindow((int) $heldSlot->time_window_id);

        if ($timeWindowId !== null) {
            $context['TIME_WINDOW'] = (string) $timeWindowId;
        }

        return $context;
    }

    private function resolveServiceZone(int $areaId): ?int
    {
        $zoneId = DB::table('service_zone_areas')
            ->join('service_zones', 'service_zones.id', '=', 'service_zone_areas.service_zone_id')
            ->where('service_zone_areas.area_id', $areaId)
            ->where('service_zones.is_active', 1)
            ->value('service_zones.id');

        return $zoneId === null ? null : (int) $zoneId;
    }

    private function resolveTimeWindow(int $timeWindowId): ?int
    {
        $id = DB::table('appointment_time_windows')
            ->where('id', $timeWindowId)
            ->where('is_active', 1)
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
