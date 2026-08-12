<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * Only what an Admin actually chooses: which technician, and an optional
 * note. Never accepts service_uuid, specialization_id, booking_uuid,
 * status, assigned_at, or any actor identifier - those are all
 * server/domain-owned (BLUE V1 Phase 9B).
 */
class AssignTechnicianRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'internal_note' => is_string($this->internal_note) ? trim($this->internal_note) : $this->internal_note,
        ]);
    }

    public function rules(): array
    {
        return [
            'technician_uuid' => ['required', 'uuid'],
            'internal_note' => ['nullable', 'string', 'min:2', 'max:1000'],
        ];
    }
}
