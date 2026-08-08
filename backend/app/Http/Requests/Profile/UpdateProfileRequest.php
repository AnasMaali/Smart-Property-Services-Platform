<?php

namespace App\Http\Requests\Profile;

use App\Http\Requests\ApiFormRequest;
use App\Models\User;
use App\Support\Uuid\UuidBinary;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('full_name') && is_string($this->full_name)) {
            $merge['full_name'] = trim($this->full_name);
        }

        if ($this->has('email') && is_string($this->email)) {
            $merge['email'] = trim(mb_strtolower($this->email));
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        /** @var User $authUser */
        $authUser = $this->attributes->get('auth_user');
        $currentUserId = UuidBinary::toBinary($authUser->id);

        return [
            'full_name' => ['sometimes', 'string', 'min:2', 'max:150'],

            'email' => [
                'sometimes',
                'string',
                'email:rfc',
                'max:254',
                Rule::unique('users', 'email')->ignore($currentUserId, 'id'),
            ],

            'area_id' => [
                'sometimes',
                'integer',
                Rule::exists('areas', 'id')->where(fn ($query) => $query->where('is_active', 1)),
            ],

            'property_relationship_type_id' => [
                'sometimes',
                'integer',
                Rule::exists('property_relationship_types', 'id')->where(fn ($query) => $query->where('is_active', 1)),
            ],

            'service_interests' => ['sometimes', 'array', 'min:1'],
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
            'area_id.exists' => 'The selected area is invalid or inactive.',
            'property_relationship_type_id.exists' => 'The selected property relationship type is invalid or inactive.',
            'service_interests.min' => 'At least one service interest must be selected.',
            'service_interests.*.exists' => 'One or more selected service interests are invalid or inactive.',
            'service_interests.*.distinct' => 'Service interests must not contain duplicate values.',
        ];
    }
}
