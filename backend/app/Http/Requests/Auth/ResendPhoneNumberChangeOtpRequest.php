<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

class ResendPhoneNumberChangeOtpRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'otp_verification_uuid' => is_string($this->otp_verification_uuid) ? trim($this->otp_verification_uuid) : $this->otp_verification_uuid,
        ]);
    }

    public function rules(): array
    {
        return [
            'otp_verification_uuid' => ['required', 'string', 'uuid'],
        ];
    }
}
