<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Auth\Concerns\HasPasswordPolicy;

class ResetPasswordRequest extends ApiFormRequest
{
    use HasPasswordPolicy;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reset_token' => is_string($this->reset_token) ? trim($this->reset_token) : $this->reset_token,
        ]);
    }

    public function rules(): array
    {
        return [
            'reset_token' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'password' => ['required', 'string', 'confirmed', $this->passwordRule()],
        ];
    }
}
