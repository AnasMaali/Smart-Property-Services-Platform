<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * Mirrors App\Http\Requests\Admin\ListAdminAuditLogsRequest's rules exactly
 * (minus page/per_page - an export is never paginated) so the CSV/PDF
 * export accepts precisely the same filters the screen Audit Log Viewer
 * does.
 */
class ExportAdminAuditLogRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
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
