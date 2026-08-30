<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class ListAdminTechniciansRequest extends ApiFormRequest
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
            'q' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'string', Rule::exists('technician_statuses', 'code')],
            'specialization' => ['nullable', 'string', Rule::exists('specializations', 'code')],
            'assignable' => ['nullable', 'boolean'],
            'rating_min' => ['nullable', 'numeric', 'min:1', 'max:5'],
            'rating_max' => ['nullable', 'numeric', 'min:1', 'max:5', 'gte:rating_min'],
            'sort' => ['nullable', 'string', Rule::in(['newest', 'name', 'rating', 'completed_jobs', 'active_jobs'])],
        ];
    }
}
