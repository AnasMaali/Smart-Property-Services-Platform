<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

/**
 * Deliberately keyed by phone_number, not otp_verification_uuid, unlike
 * ResendOtpRequest (PHONE_VERIFICATION resend). RequestLoginOtpAction never
 * returns an otp_verification_uuid to the caller in the first place - doing
 * so would let a caller distinguish "this phone number has a real pending
 * Login OTP" from "it doesn't", which is exactly the account-enumeration
 * leak the request-otp endpoint is designed to avoid. See
 * IssueLoginOtpAction, which both endpoints share.
 */
class ResendLoginOtpRequest extends ApiFormRequest
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
        ];
    }
}
