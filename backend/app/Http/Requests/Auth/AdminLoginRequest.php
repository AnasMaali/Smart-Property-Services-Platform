<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

/**
 * BLUE V1 Phase A2.3: this is Stage 1 (password) of the two-stage Admin
 * login only - it never creates a session, so it no longer accepts
 * device_name/app_version (session/device metadata belongs to whichever
 * request actually creates the session - see AdminMfaVerifyRequest).
 */
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
        ]);
    }

    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'regex:/^\+?[0-9]{8,20}$/'],

            'password' => ['required', 'string'],
        ];
    }
}
