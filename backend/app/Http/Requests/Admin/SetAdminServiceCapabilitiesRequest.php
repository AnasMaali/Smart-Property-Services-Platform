<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class SetAdminServiceCapabilitiesRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'capabilities' => ['present', 'array'],
            'capabilities.*' => ['required', 'string', 'max:60'],
        ];
    }
}
