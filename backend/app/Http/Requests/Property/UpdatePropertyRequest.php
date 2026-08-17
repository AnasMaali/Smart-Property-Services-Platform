<?php

namespace App\Http\Requests\Property;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH is a partial update - every field is "sometimes", but once present
 * it is validated exactly like App\Http\Requests\Property\CreatePropertyRequest.
 * Archived (is_active = 0) state is never toggled through this request -
 * see App\Http\Controllers\Api\V1\Property\DeletePropertyController for the
 * one, idempotent way to archive a property.
 */
class UpdatePropertyRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'min:2', 'max:120'],

            'property_relationship_type_id' => [
                'sometimes',
                'integer',
                Rule::exists('property_relationship_types', 'id')->where(fn ($query) => $query->where('is_active', 1)),
            ],

            'property_type_id' => [
                'sometimes',
                'integer',
                Rule::exists('property_types', 'id')->where(fn ($query) => $query->where('is_active', 1)),
            ],
            'other_property_type_name' => ['nullable', 'string', 'min:2', 'max:120'],

            'area_id' => [
                'sometimes',
                'integer',
                Rule::exists('areas', 'id')->where(fn ($query) => $query->where('is_active', 1)),
            ],

            'street_name' => ['sometimes', 'string', 'min:2', 'max:180'],
            'address_line' => ['sometimes', 'string', 'min:5', 'max:500'],
            'building_name_or_number' => ['sometimes', 'string', 'min:1', 'max:120'],
            'floor_number' => ['nullable', 'string', 'min:1', 'max:30'],
            'unit_number' => ['nullable', 'string', 'min:1', 'max:50'],
            'nearby_landmark' => ['nullable', 'string', 'min:2', 'max:250'],
            'additional_location_notes' => ['nullable', 'string', 'min:2', 'max:1000'],
            'visit_contact_phone' => ['sometimes', 'string', 'min:8', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'property_relationship_type_id.exists' => 'The selected property relationship type is invalid or inactive.',
            'property_type_id.exists' => 'The selected property type is invalid or inactive.',
            'area_id.exists' => 'The selected area is invalid or inactive.',
        ];
    }
}
