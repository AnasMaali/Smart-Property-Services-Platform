<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class CreateAdminServiceCategoryRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:2', 'max:60'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'min:1', 'max:500'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
