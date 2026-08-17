<?php

namespace App\Actions\Property;

use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Property\PropertyPresenter;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Partial update of a saved customer Property (PATCH /v1/properties/{property}).
 * Ownership-scoped exactly like App\Actions\Property\GetPropertyAction. An
 * archived Property is immutable - the customer must be looking at an
 * active property to change it, mirroring how a terminal Booking/Booking
 * Item can no longer be mutated elsewhere in this codebase.
 */
class UpdatePropertyAction
{
    use BuildsCartResult;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function handle(string $userUuid, string $propertyUuid, array $data): array
    {
        try {
            $propertyIdBinary = UuidBinary::toBinary($propertyUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Property not found.');
        }

        return DB::transaction(function () use ($propertyIdBinary, $userUuid, $data): array {
            $userIdBinary = UuidBinary::toBinary($userUuid);

            $property = DB::table('customer_properties')
                ->where('id', $propertyIdBinary)
                ->where('customer_user_id', $userIdBinary)
                ->lockForUpdate()
                ->first();

            if ($property === null) {
                return $this->notFound('Property not found.');
            }

            if ((int) $property->is_active !== 1) {
                return $this->conflict('An archived property cannot be edited.');
            }

            $propertyTypeId = $data['property_type_id'] ?? $property->property_type_id;

            $propertyType = DB::table('property_types')
                ->where('id', $propertyTypeId)
                ->where('is_active', 1)
                ->first(['id', 'code']);

            if ($propertyType === null) {
                return $this->unprocessable('One or more fields are invalid.', ['property_type_id' => ['The selected property type is invalid or inactive.']]);
            }

            $otherPropertyTypeName = trim((string) ($data['other_property_type_name'] ?? $property->other_property_type_name ?? ''));

            if ($propertyType->code === 'OTHER' && $otherPropertyTypeName === '') {
                return $this->unprocessable('One or more fields are invalid.', ['other_property_type_name' => ['other_property_type_name is required when property_type_id is OTHER.']]);
            }

            $now = now();

            $fields = [
                'label' => isset($data['label']) ? trim((string) $data['label']) : $property->label,
                'property_relationship_type_id' => $data['property_relationship_type_id'] ?? $property->property_relationship_type_id,
                'property_type_id' => $propertyType->id,
                'other_property_type_name' => $propertyType->code === 'OTHER' ? $otherPropertyTypeName : null,
                'area_id' => $data['area_id'] ?? $property->area_id,
                'street_name' => $data['street_name'] ?? $property->street_name,
                'address_line' => $data['address_line'] ?? $property->address_line,
                'building_name_or_number' => $data['building_name_or_number'] ?? $property->building_name_or_number,
                'floor_number' => array_key_exists('floor_number', $data) ? $data['floor_number'] : $property->floor_number,
                'unit_number' => array_key_exists('unit_number', $data) ? $data['unit_number'] : $property->unit_number,
                'nearby_landmark' => array_key_exists('nearby_landmark', $data) ? $data['nearby_landmark'] : $property->nearby_landmark,
                'additional_location_notes' => array_key_exists('additional_location_notes', $data) ? $data['additional_location_notes'] : $property->additional_location_notes,
                'visit_contact_phone' => $data['visit_contact_phone'] ?? $property->visit_contact_phone,
                'updated_at' => $now,
            ];

            DB::table('customer_properties')->where('id', $propertyIdBinary)->update($fields);

            $updated = DB::table('customer_properties')->where('id', $propertyIdBinary)->first();

            return $this->ok(200, 'Property updated successfully.', ['property' => PropertyPresenter::present($updated)]);
        });
    }
}
