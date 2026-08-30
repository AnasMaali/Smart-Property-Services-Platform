<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class UpdateAdminTechnicianRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->phone_number)) {
            $merge['phone_number'] = trim($this->phone_number);
        }

        if (is_string($this->email)) {
            $merge['email'] = trim(mb_strtolower($this->email));
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'min:2', 'max:150'],
            'phone_number' => ['sometimes', 'string', 'regex:/^\+?[0-9]{8,20}$/'],
            'email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:254'],
            'is_phone_visible_to_customer' => ['sometimes', 'boolean'],
            'internal_note' => ['sometimes', 'nullable', 'string', 'min:2', 'max:1000'],
        ];
    }
}
