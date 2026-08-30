<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class ListAdminContractBillingsRequest extends ApiFormRequest
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
            'status' => ['nullable', 'string', Rule::exists('service_contract_billing_statuses', 'code')],
            'contract_number' => ['nullable', 'string', 'max:40'],
            'customer_uuid' => ['nullable', 'uuid'],
        ];
    }
}
