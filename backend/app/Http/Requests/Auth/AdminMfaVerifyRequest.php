<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

/**
 * BLUE V1 Phase A2.3 - Stage 2 of Admin login (see
 * App\Actions\Auth\AdminMfaVerifyAction). `credential` is the raw
 * `PublicKeyCredential` JSON object `navigator.credentials.get()` produced
 * in the browser - validated in shape only here; the actual WebAuthn
 * ceremony verification is App\Support\Admin\WebAuthn\AdminWebAuthnAssertionService's
 * job. device_name/app_version now belong here rather than the Stage 1
 * login request, since this is the request that actually creates the
 * auth_sessions row.
 */
class AdminMfaVerifyRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'login_ticket' => is_string($this->login_ticket) ? trim($this->login_ticket) : $this->login_ticket,
            'device_name' => is_string($this->device_name) ? trim($this->device_name) : $this->device_name,
            'app_version' => is_string($this->app_version) ? trim($this->app_version) : $this->app_version,
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
            'credential.response.authenticatorData' => ['required', 'string'],
            'credential.response.signature' => ['required', 'string'],

            'device_name' => ['nullable', 'string', 'max:120'],

            'app_version' => ['nullable', 'string', 'max:30'],
        ];
    }
}
