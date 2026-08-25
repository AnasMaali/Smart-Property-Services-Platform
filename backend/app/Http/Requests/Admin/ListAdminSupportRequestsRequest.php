<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class ListAdminSupportRequestsRequest extends ApiFormRequest
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
            'status' => ['nullable', 'string', Rule::exists('support_request_statuses', 'code')],
            'customer_uuid' => ['nullable', 'uuid'],
            'booking_uuid' => ['nullable', 'uuid'],
            'assigned_admin_uuid' => ['nullable', 'uuid'],
            'unassigned' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:200'],
        ];
    }
}
