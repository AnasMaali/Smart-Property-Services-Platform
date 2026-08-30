<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class CreateAdminTechnicianRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone_number' => is_string($this->phone_number) ? trim($this->phone_number) : $this->phone_number,
            'email' => is_string($this->email) ? trim(mb_strtolower($this->email)) : $this->email,
        ]);
    }

    public function rules(): array
    {
        return [
            'employee_code' => ['required', 'string', 'min:3', 'max:50'],
            'full_name' => ['required', 'string', 'min:2', 'max:150'],
            'phone_number' => ['required', 'string', 'regex:/^\+?[0-9]{8,20}$/'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:254'],
            'is_phone_visible_to_customer' => ['nullable', 'boolean'],
            'internal_note' => ['nullable', 'string', 'min:2', 'max:1000'],
        ];
    }
}
