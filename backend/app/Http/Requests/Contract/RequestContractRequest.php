<?php

namespace App\Http\Requests\Contract;

use App\Http\Requests\ApiFormRequest;

/**
 * Shape-only validation for POST /v1/contracts/requests. Whether the
 * requested services are actually CONTRACT-eligible (and resolving
 * all_services against the current catalog) is a catalog rule left to
 * App\Actions\Contract\RequestContractAction - the same "shape here,
 * catalog rules in the Action" split used throughout this codebase.
 *
 * Deliberately never accepts status, contract_number, entitlement
 * quantities/mode, price, or an approval decision - all of those are
 * server/Admin-owned (BLUE V1 Phase 10D).
 */
class RequestContractRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'customer_note' => is_string($this->customer_note) ? trim($this->customer_note) : $this->customer_note,
            'all_services' => $this->boolean('all_services'),
        ]);
    }

    public function rules(): array
    {
        return [
            'property_uuid' => ['required', 'uuid'],
            'all_services' => ['required', 'boolean'],
            'service_uuids' => ['required_if:all_services,false', 'array', 'min:1'],
            'service_uuids.*' => ['uuid', 'distinct'],
            'desired_start_date' => ['nullable', 'date', 'after_or_equal:today'],
            'customer_note' => ['nullable', 'string', 'min:2', 'max:1000'],
        ];
    }
}
