<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

/**
 * Shape-only validation - only otp_code is ever read by DeleteAccountAction.
 * The deletion target is always the authenticated caller (auth_user).
 */
class DeleteAccountRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'otp_code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ];
    }
}
