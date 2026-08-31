<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * Only what an Admin actually chooses: which Admin to assign. Eligibility
 * (must currently hold an active ADMIN/SUPER_ADMIN role) is
 * App\Actions\Admin\Support\AdminAssignSupportRequestAction's job, never
 * this Request's - mirrors App\Http\Requests\Admin\AssignTechnicianRequest
 * deferring technician eligibility to its own Action.
 */
class AssignAdminSupportRequestRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'admin_uuid' => ['required', 'uuid'],
        ];
    }
}
