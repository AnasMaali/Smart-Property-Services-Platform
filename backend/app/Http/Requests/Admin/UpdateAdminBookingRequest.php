<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class UpdateAdminBookingRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only operational visit/location fields are editable.
     *
     * Historical lookup snapshots such as country/city/area/property type
     * are deliberately immutable through this endpoint.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'street_name' => [
                'sometimes',
                'string',
                'min:2',
                'max:180',
            ],

            'address_line' => [
                'sometimes',
                'string',
                'min:5',
                'max:500',
            ],

            'building_name_or_number' => [
                'sometimes',
                'string',
                'min:1',
                'max:120',
            ],

            'floor_number' => [
                'sometimes',
                'nullable',
                'string',
                'min:1',
                'max:30',
            ],

            'unit_number' => [
                'sometimes',
                'nullable',
                'string',
                'min:1',
                'max:50',
            ],

            'nearby_landmark' => [
                'sometimes',
                'nullable',
                'string',
                'min:2',
                'max:250',
            ],

            'additional_location_notes' => [
                'sometimes',
                'nullable',
                'string',
                'min:2',
                'max:1000',
            ],

            'visit_contact_phone' => [
                'sometimes',
                'string',
                'min:8',
                'max:20',
            ],
        ];
    }
}