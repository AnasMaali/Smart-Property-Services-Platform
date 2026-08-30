<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminServiceCheckpointRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'min:1', 'max:500'],
            'action_type_code' => ['required', 'string', Rule::exists('service_checkpoint_action_types', 'code')],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'group_uuid' => ['nullable', 'uuid'],
        ];
    }
}
