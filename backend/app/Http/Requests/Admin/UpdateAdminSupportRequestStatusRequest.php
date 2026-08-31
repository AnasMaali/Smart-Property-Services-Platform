<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors App\Http\Requests\Admin\SetAdminTechnicianStatusRequest exactly:
 * only the target status code is client-supplied - transition legality
 * against the current status is App\Support\Admin\
 * SupportRequestStatusMachine's job, never this Request's.
 */
class UpdateAdminSupportRequestStatusRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::exists('support_request_statuses', 'code')],
        ];
    }
}
