<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

/**
 * BLUE V1 Phase A2.5 - POST /v1/admin/auth/step-up/verify (see
 * App\Actions\Auth\AdminStepUpVerifyAction). `credential` is the raw
 * `PublicKeyCredential` JSON object `navigator.credentials.get()` produced
 * in the browser - validated in shape only here, mirroring
 * App\Http\Requests\Auth\AdminMfaVerifyRequest exactly; the actual WebAuthn
 * ceremony verification is App\Support\Admin\WebAuthn\AdminWebAuthnAssertionService's
 * job. Unlike AdminMfaVerifyRequest, there is no device_name/app_version -
 * a step-up ceremony never creates or touches a session's device metadata.
 */
class AdminStepUpVerifyRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'step_up_ticket' => is_string($this->step_up_ticket) ? trim($this->step_up_ticket) : $this->step_up_ticket,
        ]);
    }

    public function rules(): array
    {
        return [
            'step_up_ticket' => ['required', 'uuid'],

            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string'],
            'credential.rawId' => ['required', 'string'],
            'credential.type' => ['required', 'string'],
            'credential.response' => ['required', 'array'],
            'credential.response.clientDataJSON' => ['required', 'string'],
            'credential.response.authenticatorData' => ['required', 'string'],
            'credential.response.signature' => ['required', 'string'],
        ];
    }
}
