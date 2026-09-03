<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class RegisterCustomerRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'full_name' => is_string($this->full_name) ? trim($this->full_name) : $this->full_name,
            'email' => is_string($this->email) ? trim(mb_strtolower($this->email)) : $this->email,
            'phone_number' => is_string($this->phone_number) ? trim($this->phone_number) : $this->phone_number,
        ]);
    }

    public function rules(): array
    {
        $cityId = $this->input('city_id');

        return [
            'full_name' => ['required', 'string', 'min:2', 'max:150'],

            'phone_number' => [
                'required',
                'string',
                'regex:/^\+?[0-9]{8,20}$/',
                'unique:users,phone_number',
            ],

            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:254',
                'unique:users,email',
            ],

            'city_id' => [
                'required',
                'integer',
                Rule::exists('cities', 'id')->where(fn ($query) => $query->where('is_active', 1)),
            ],

            'area_id' => [
                'required',
                'integer',
                Rule::exists('areas', 'id')->where(
                    fn ($query) => $query->where('is_active', 1)->where('city_id', $cityId)
                ),
            ],

            'property_relationship_type_id' => [
                'required',
                'integer',
                Rule::exists('property_relationship_types', 'id')->where(fn ($query) => $query->where('is_active', 1)),
            ],

            'service_interests' => ['required', 'array', 'min:1'],
            'service_interests.*' => [
                'distinct',
                'integer',
                Rule::exists('service_categories', 'id')->where(fn ($query) => $query->where('is_active', 1)),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'area_id.exists' => 'The selected area is invalid, inactive, or does not belong to the selected city.',
            'service_interests.*.exists' => 'One or more selected service interests are invalid or inactive.',
            'service_interests.*.distinct' => 'Service interests must not contain duplicate values.',
        ];
    }
}
