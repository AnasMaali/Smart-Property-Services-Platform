<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class ListAdminAuditLogsRequest extends ApiFormRequest
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
            'action_code' => ['nullable', 'string', 'max:80'],
            'entity_type' => ['nullable', 'string', 'max:80'],
            'entity_identifier' => ['nullable', 'string', 'max:191'],
            'was_successful' => ['nullable', 'boolean'],
            'actor_uuid' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
