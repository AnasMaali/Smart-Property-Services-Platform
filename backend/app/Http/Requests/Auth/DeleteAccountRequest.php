<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

/**
 * Shape-only validation - only current_password is ever read by
 * DeleteAccountAction. No account/user identifier, status, or lifecycle
 * field (user_uuid, account_status, role, phone_number, email,
 * delete_immediately, force, bypass_active_contracts, ...) is declared
 * here, so FormRequest::validated() (the only thing the Action ever
 * reads) silently strips any such field a client might submit - the
 * deletion target is always the authenticated caller (auth_user), never
 * request input.
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
            'current_password' => ['required', 'string'],
        ];
    }
}
