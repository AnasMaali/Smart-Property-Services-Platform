<?php

namespace App\Support\Booking;

use App\Support\Payment\CanonicalJson;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase 7A, extracted in Phase B24 so both Booking-creation entry
 * points (App\Actions\Booking\CreateBookingFromSuccessfulPaymentAction for
 * the Stripe-paid path, App\Actions\Booking\CreatePayOnSiteBookingAction
 * for the pay-on-site path) build `booking_items`/`booking_locations` rows
 * from a frozen checkout snapshot the exact same way - never two divergent
 * implementations of "what does a Booking Item snapshot look like".
 *
 * Pure, side-effect-free: every method only reads reference/lookup tables
 * (services, property_types, areas/cities/countries) to resolve stable
 * display names, never the live catalog/pricing for a monetary decision -
 * every price already comes from the frozen `pricing` block the caller
 * passes in.
 */
final class BookingSnapshotConverter
{
    /**
     * @param  array<int, array<string, mixed>>  $snapshotItems
     * @return array<int, array<string, mixed>>|null null means the frozen
     *                                               snapshot failed an internal consistency check that must never
     *                                               reach the database (e.g. a QUOTE_REQUIRED item, or line_total !=
     *                                               unit_total * quantity) - the caller flags reconciliation/rejects
     *                                               instead.
     */
    public static function buildItems(array $snapshotItems): ?array
    {
        if ($snapshotItems === []) {
            return null;
        }

        $items = [];

        foreach ($snapshotItems as $index => $snapshotItem) {
            $pricing = $snapshotItem['pricing'] ?? null;

            if ($pricing === null || ($pricing['pricing_status'] ?? null) !== 'PRICED') {
                return null;
            }

            $quantity = (int) $snapshotItem['quantity'];
            $unitTotal = (string) $pricing['unit_total'];
            $lineTotal = (string) $pricing['line_total'];

            if (bccomp(bcmul($unitTotal, (string) $quantity, 6), $lineTotal, 6) !== 0) {
                return null;
            }

            $serviceIdBinary = UuidBinary::toBinary($snapshotItem['service']['uuid']);
            $serviceCode = DB::table('services')->where('id', $serviceIdBinary)->value('code');
            $schemeVersionId = DB::table('pricing_scheme_versions')
                ->where('id', UuidBinary::toBinary($pricing['pricing_scheme_version']))
                ->value('id');

            if ($serviceCode === null || $schemeVersionId === null) {
                return null;
            }

            $items[] = [
                'display_order' => $index,
                'source_cart_item_id' => UuidBinary::toBinary($snapshotItem['cart_item_uuid']),
                'service_id' => $serviceIdBinary,
                'pricing_scheme_version_id' => $schemeVersionId,
                'service_code_snapshot' => $serviceCode,
                'service_name_snapshot' => $snapshotItem['service']['name'],
                'quantity' => $quantity,
                'pricing_status_snapshot' => 'PRICED',
                'base_amount_snapshot' => $pricing['base_amount'],
                'pricing_breakdown' => CanonicalJson::encode($pricing['adjustments'] ?? []),
                'unit_total_amount' => $unitTotal,
                'line_total_amount' => $lineTotal,
            ];
        }

        return $items;
    }

    /**
     * Resolves the historical location snapshot from reference/lookup data
     * (property_types, areas, cities, countries) keyed by the immutable
     * ids the frozen checkout_snapshot already carries. Never reads the
     * customer's current profile/cart_location - the snapshot's own
     * free-text fields (street, building, phone, ...) are copied through
     * verbatim.
     *
     * @return array<string, mixed>|null
     */
    public static function resolveLocation(?array $snapshotLocation): ?array
    {
        if ($snapshotLocation === null) {
            return null;
        }

        $propertyTypeId = $snapshotLocation['property_type']['id'] ?? null;
        $areaId = $snapshotLocation['area']['id'] ?? null;

        if ($propertyTypeId === null || $areaId === null) {
            return null;
        }

        $propertyTypeName = DB::table('property_types')->where('id', (int) $propertyTypeId)->value('name');

        $area = DB::table('areas')
            ->join('cities', 'cities.id', '=', 'areas.city_id')
            ->join('countries', 'countries.id', '=', 'cities.country_id')
            ->where('areas.id', (int) $areaId)
            ->first(['areas.name as area_name', 'cities.name as city_name', 'countries.name as country_name']);

        if ($propertyTypeName === null || $area === null) {
            return null;
        }

        return [
            'property_type_id' => (int) $propertyTypeId,
            'area_id' => (int) $areaId,
            'property_type_name_snapshot' => $propertyTypeName,
            'country_name_snapshot' => $area->country_name,
            'city_name_snapshot' => $area->city_name,
            'area_name_snapshot' => $area->area_name,
            'other_property_type_name' => $snapshotLocation['other_property_type_name'] ?? null,
            'street_name' => $snapshotLocation['street_name'],
            'address_line' => $snapshotLocation['address_line'],
            'building_name_or_number' => $snapshotLocation['building_name_or_number'],
            'floor_number' => $snapshotLocation['floor_number'] ?? null,
            'unit_number' => $snapshotLocation['unit_number'] ?? null,
            'nearby_landmark' => $snapshotLocation['nearby_landmark'] ?? null,
            'additional_location_notes' => $snapshotLocation['additional_location_notes'] ?? null,
            'visit_contact_phone' => $snapshotLocation['visit_contact_phone'],
        ];
    }
}
