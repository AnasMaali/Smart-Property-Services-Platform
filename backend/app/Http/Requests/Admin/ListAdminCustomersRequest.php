<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class ListAdminCustomersRequest extends ApiFormRequest
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
            'account_status' => ['nullable', 'string', Rule::exists('user_account_statuses', 'code')],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'max:254'],
            'customer_uuid' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:150'],
        ];
    }
}
