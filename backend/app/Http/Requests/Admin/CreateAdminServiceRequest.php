<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class CreateAdminServiceRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', Rule::exists('service_categories', 'id')],
            'code' => ['required', 'string', 'min:2', 'max:80'],
            'slug' => ['required', 'string', 'min:2', 'max:160'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'short_description' => ['nullable', 'string', 'min:1', 'max:300'],
            'description' => ['nullable', 'string', 'min:1', 'max:5000'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
