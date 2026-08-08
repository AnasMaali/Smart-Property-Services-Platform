<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

class RefreshRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'refresh_token' => is_string($this->refresh_token) ? trim($this->refresh_token) : $this->refresh_token,
        ]);
    }

    public function rules(): array
    {
        return [
            'refresh_token' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
        ];
    }
}
