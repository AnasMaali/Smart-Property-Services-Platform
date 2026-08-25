<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class ListAdminPricingSchemesRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'service_uuid' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string', Rule::in(['DRAFT', 'PUBLISHED', 'RETIRED'])],
            'currency' => ['nullable', 'string', Rule::exists('currencies', 'code')],
        ];
    }
}
