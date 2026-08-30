<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class SetAdminServiceCatalogPolicyRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_featured' => ['required', 'boolean'],
            'estimated_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:32767'],
            'min_quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
