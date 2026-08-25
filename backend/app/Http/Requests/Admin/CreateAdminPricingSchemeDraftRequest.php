<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class CreateAdminPricingSchemeDraftRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_uuid' => ['required', 'uuid'],
            'currency_code' => ['required', 'string', 'size:3'],
        ];
    }
}
