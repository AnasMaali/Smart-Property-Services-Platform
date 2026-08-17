<?php

namespace App\Support\Property;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one safe, Flutter-facing customer property JSON shape (BLUE V1 Phase
 * 10A) - mirrors App\Support\Booking\BookingPresenter's conventions
 * (snake_case, UUID strings, ISO-8601 datetimes, explicit nullability).
 * Never exposes `customer_user_id` or any raw binary(16) id.
 */
final class PropertyPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(object $property): array
    {
        $relationshipType = DB::table('property_relationship_types')->where('id', $property->property_relationship_type_id)->first(['code', 'name']);
        $propertyType = DB::table('property_types')->where('id', $property->property_type_id)->first(['code', 'name']);

        $area = DB::table('areas')
            ->join('cities', 'cities.id', '=', 'areas.city_id')
            ->join('countries', 'countries.id', '=', 'cities.country_id')
            ->where('areas.id', $property->area_id)
            ->first(['areas.id', 'areas.name as area_name', 'cities.name as city_name', 'countries.name as country_name']);

        return [
            'uuid' => UuidBinary::toString($property->id),
            'label' => $property->label,
            'relationship_type' => $relationshipType === null ? null : [
                'code' => $relationshipType->code,
                'name' => $relationshipType->name,
            ],
            'property_type' => $propertyType === null ? null : [
                'code' => $propertyType->code,
                'name' => $propertyType->name,
            ],
            'other_property_type_name' => $property->other_property_type_name,
            'area' => $area === null ? null : [
                'id' => (int) $area->id,
                'name' => $area->area_name,
                'city_name' => $area->city_name,
                'country_name' => $area->country_name,
            ],
            'street_name' => $property->street_name,
            'address_line' => $property->address_line,
            'building_name_or_number' => $property->building_name_or_number,
            'floor_number' => $property->floor_number,
            'unit_number' => $property->unit_number,
            'nearby_landmark' => $property->nearby_landmark,
            'additional_location_notes' => $property->additional_location_notes,
            'visit_contact_phone' => $property->visit_contact_phone,
            'is_active' => (bool) $property->is_active,
            'created_at' => Carbon::parse($property->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse($property->updated_at)->toIso8601String(),
        ];
    }
}
