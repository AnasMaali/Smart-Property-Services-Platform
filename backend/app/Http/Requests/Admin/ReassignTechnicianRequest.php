<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * `release_reason` is required here - `technician_assignments.release_reason`
 * is NOT NULL whenever a row is released
 * (chk_technician_assignments_release_data), and
 * App\Actions\Technician\AssignTechnicianToBookingItemAction::reassign() has
 * no default of its own to supply.
 */
class ReassignTechnicianRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'release_reason' => is_string($this->release_reason) ? trim($this->release_reason) : $this->release_reason,
            'internal_note' => is_string($this->internal_note) ? trim($this->internal_note) : $this->internal_note,
        ]);
    }

    public function rules(): array
    {
        return [
            'technician_uuid' => ['required', 'uuid'],
            'release_reason' => ['required', 'string', 'min:2', 'max:500'],
            'internal_note' => ['nullable', 'string', 'min:2', 'max:1000'],
        ];
    }
}
