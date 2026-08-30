<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class SetAdminServiceSpecializationRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'specialization_id' => ['required', 'integer', Rule::exists('specializations', 'id')],
            'is_primary' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
