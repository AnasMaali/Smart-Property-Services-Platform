<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

class AdminLoginRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone_number' => is_string($this->phone_number) ? trim($this->phone_number) : $this->phone_number,
            'device_name' => is_string($this->device_name) ? trim($this->device_name) : $this->device_name,
            'app_version' => is_string($this->app_version) ? trim($this->app_version) : $this->app_version,
        ]);
    }

    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'regex:/^\+?[0-9]{8,20}$/'],

            'password' => ['required', 'string'],

            'device_name' => ['nullable', 'string', 'max:120'],

            'app_version' => ['nullable', 'string', 'max:30'],
        ];
    }
}
