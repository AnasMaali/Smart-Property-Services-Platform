<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

class VerifyPasswordResetOtpRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone_number' => is_string($this->phone_number) ? trim($this->phone_number) : $this->phone_number,
            'otp_code' => is_string($this->otp_code) ? trim($this->otp_code) : $this->otp_code,
        ]);
    }

    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'regex:/^\+?[0-9]{8,20}$/'],
            'otp_code' => ['required', 'string', 'digits:6'],
        ];
    }
}
