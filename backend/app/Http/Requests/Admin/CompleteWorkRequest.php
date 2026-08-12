<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * Same contract as App\Http\Requests\Admin\StartWorkRequest - see that
 * class's docblock.
 */
class CompleteWorkRequest extends ApiFormRequest
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
