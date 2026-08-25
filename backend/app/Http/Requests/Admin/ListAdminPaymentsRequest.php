<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class ListAdminPaymentsRequest extends ApiFormRequest
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
            'status' => ['nullable', 'string', Rule::exists('payment_statuses', 'code')],
            'checkout_reference' => ['nullable', 'string', 'max:64'],
            'customer_uuid' => ['nullable', 'uuid'],
            'provider_transaction_reference' => ['nullable', 'string', 'max:191'],
        ];
    }
}
