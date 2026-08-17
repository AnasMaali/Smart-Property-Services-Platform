<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * Shared optional-reason shape for the Admin suspend/cancel Contract
 * routes - mirrors App\Http\Requests\Admin\ReassignTechnicianRequest's
 * "an optional free-text reason, nothing else" convention.
 */
class ContractActionReasonRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => is_string($this->reason) ? trim($this->reason) : $this->reason,
        ]);
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'min:2', 'max:500'],
        ];
    }
}
