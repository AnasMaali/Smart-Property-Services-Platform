<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * `technician_uuid` is the Admin's claim of which technician is executing
 * the job on the Admin's behalf - only syntactically validated here.
 * App\Actions\Technician\StartTechnicianJobAction::start() itself is what
 * verifies it against the Booking Item's actual active assignment (never
 * trusted as proof of identity by this Request or its Controller).
 */
class StartWorkRequest extends ApiFormRequest
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
            'technician_uuid' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'min:2', 'max:500'],
        ];
    }
}
