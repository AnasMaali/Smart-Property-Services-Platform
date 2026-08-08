<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class LoginRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone_number' => is_string($this->phone_number) ? trim($this->phone_number) : $this->phone_number,
            'client_type' => is_string($this->client_type) ? trim(mb_strtoupper($this->client_type)) : $this->client_type,
            'device_name' => is_string($this->device_name) ? trim($this->device_name) : $this->device_name,
            'app_version' => is_string($this->app_version) ? trim($this->app_version) : $this->app_version,
        ]);
    }

    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'regex:/^\+?[0-9]{8,20}$/'],

            'password' => ['required', 'string'],

            'client_type' => [
                'required',
                'string',
                Rule::in(['MOBILE_IOS', 'MOBILE_ANDROID']),
                Rule::exists('auth_client_types', 'code')->where(fn ($query) => $query->where('is_active', 1)),
            ],

            'device_name' => ['nullable', 'string', 'max:120'],

            'app_version' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_type.in' => 'The selected client type is not permitted for customer login.',
            'client_type.exists' => 'The selected client type is invalid or inactive.',
        ];
    }
}
