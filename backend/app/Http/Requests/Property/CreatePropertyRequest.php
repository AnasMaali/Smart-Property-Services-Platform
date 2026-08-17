<?php

namespace App\Http\Requests\Property;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape-only validation matching the customer_properties schema - the same
 * "shape here, catalog rules in the Action" split already used by
 * App\Http\Requests\Checkout\SaveCheckoutLocationRequest (whether
 * other_property_type_name is required depends on catalog data, so that is
 * left to App\Actions\Property\CreatePropertyAction).
 */
class CreatePropertyRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'min:2', 'max:120'],

            'property_relationship_type_id' => [
                'required',
                'integer',
                Rule::exists('property_relationship_types', 'id')->where(fn ($query) => $query->where('is_active', 1)),
            ],

            'property_type_id' => [
                'required',
                'integer',
                Rule::exists('property_types', 'id')->where(fn ($query) => $query->where('is_active', 1)),
            ],
            'other_property_type_name' => ['nullable', 'string', 'min:2', 'max:120'],

            'area_id' => [
                'required',
                'integer',
                Rule::exists('areas', 'id')->where(fn ($query) => $query->where('is_active', 1)),
            ],

            'street_name' => ['required', 'string', 'min:2', 'max:180'],
            'address_line' => ['required', 'string', 'min:5', 'max:500'],
            'building_name_or_number' => ['required', 'string', 'min:1', 'max:120'],
            'floor_number' => ['nullable', 'string', 'min:1', 'max:30'],
            'unit_number' => ['nullable', 'string', 'min:1', 'max:50'],
            'nearby_landmark' => ['nullable', 'string', 'min:2', 'max:250'],
            'additional_location_notes' => ['nullable', 'string', 'min:2', 'max:1000'],
            'visit_contact_phone' => ['required', 'string', 'min:8', 'max:20'],
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
