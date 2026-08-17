<?php

namespace App\Actions\Property;

use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Property\PropertyPresenter;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * Creates a new saved customer Property (BLUE V1 Phase 10A). Field shape and
 * OTHER-property-type pairing mirror
 * App\Actions\Checkout\SaveCheckoutLocationAction exactly, since
 * customer_properties reuses the same area/property_type/address
 * conventions as cart_locations/booking_locations - see
 * docs/api-contracts/properties-v1.md.
 */
class CreatePropertyAction
{
    use BuildsCartResult;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function handle(string $userUuid, array $data): array
    {
        $userIdBinary = UuidBinary::toBinary($userUuid);

        $propertyType = DB::table('property_types')
            ->where('id', $data['property_type_id'])
            ->where('is_active', 1)
            ->first(['id', 'code']);

        if ($propertyType === null) {
            return $this->unprocessable('One or more fields are invalid.', ['property_type_id' => ['The selected property type is invalid or inactive.']]);
        }

        $otherPropertyTypeName = trim((string) ($data['other_property_type_name'] ?? ''));

        if ($propertyType->code === 'OTHER' && $otherPropertyTypeName === '') {
            return $this->unprocessable('One or more fields are invalid.', ['other_property_type_name' => ['other_property_type_name is required when property_type_id is OTHER.']]);
        }

        $now = now();
        $propertyIdBinary = UuidBinary::toBinary(UuidBinary::generate());

        DB::table('customer_properties')->insert([
            'id' => $propertyIdBinary,
            'customer_user_id' => $userIdBinary,
            'label' => trim((string) $data['label']),
            'property_relationship_type_id' => $data['property_relationship_type_id'],
            'property_type_id' => $propertyType->id,
            'other_property_type_name' => $propertyType->code === 'OTHER' ? $otherPropertyTypeName : null,
            'area_id' => $data['area_id'],
            'street_name' => $data['street_name'],
            'address_line' => $data['address_line'],
            'building_name_or_number' => $data['building_name_or_number'],
            'floor_number' => $data['floor_number'] ?? null,
            'unit_number' => $data['unit_number'] ?? null,
            'nearby_landmark' => $data['nearby_landmark'] ?? null,
            'additional_location_notes' => $data['additional_location_notes'] ?? null,
            'visit_contact_phone' => $data['visit_contact_phone'],
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $property = DB::table('customer_properties')->where('id', $propertyIdBinary)->first();

        return $this->ok(201, 'Property created successfully.', ['property' => PropertyPresenter::present($property)]);
    }
}
