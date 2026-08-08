<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

class RequestPhoneNumberChangeRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'new_phone_number' => is_string($this->new_phone_number) ? trim($this->new_phone_number) : $this->new_phone_number,
        ]);
    }

    public function rules(): array
    {
        return [
            'new_phone_number' => [
                'required',
                'string',
                'regex:/^\+?[0-9]{8,20}$/',
                'unique:users,phone_number',
            ],
        ];
    }
}
