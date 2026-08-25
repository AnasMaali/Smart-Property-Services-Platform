<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class UpdateAdminServiceRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'short_description' => ['nullable', 'string', 'min:1', 'max:300'],
            'description' => ['nullable', 'string', 'min:1', 'max:5000'],
            'display_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
