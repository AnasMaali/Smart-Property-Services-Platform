<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

/**
 * BLUE V1 Phase A2.3 - first-credential bootstrap (see
 * App\Actions\Auth\AdminMfaEnrollAction). `credential` is the raw
 * `PublicKeyCredential` JSON object `navigator.credentials.create()`
 * produced in the browser - validated in shape only here; the actual
 * WebAuthn ceremony verification (challenge, origin, RP ID, signature,
 * user verification) is App\Support\Admin\WebAuthn\AdminWebAuthnRegistrationService's
 * job, never re-implemented at this layer.
 */
class AdminMfaEnrollRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'login_ticket' => is_string($this->login_ticket) ? trim($this->login_ticket) : $this->login_ticket,
        ]);
    }

    public function rules(): array
    {
        return [
            'login_ticket' => ['required', 'uuid'],

            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string'],
            'credential.rawId' => ['required', 'string'],
            'credential.type' => ['required', 'string'],
            'credential.response' => ['required', 'array'],
            'credential.response.clientDataJSON' => ['required', 'string'],
            'credential.response.attestationObject' => ['required', 'string'],
        ];
    }
}
