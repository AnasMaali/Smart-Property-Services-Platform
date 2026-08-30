<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class CreateAdminServiceOptionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:2', 'max:80'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'min:1', 'max:500'],
            'option_type_code' => ['required', 'string', Rule::exists('service_option_types', 'code')],
            'is_required' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'numeric_rule' => ['nullable', 'array'],
            'numeric_rule.min_value' => ['required_with:numeric_rule', 'numeric', 'min:0'],
            'numeric_rule.max_value' => ['required_with:numeric_rule', 'numeric', 'gt:numeric_rule.min_value'],
            'numeric_rule.step_value' => ['nullable', 'numeric', 'gt:0'],
            'numeric_rule.default_value' => ['nullable', 'numeric'],
            'numeric_rule.decimal_places' => ['nullable', 'integer', 'min:0', 'max:6'],
            'numeric_rule.measurement_unit_code' => ['nullable', 'string', Rule::exists('measurement_units', 'code')],
            'selection_rule' => ['nullable', 'array'],
            'selection_rule.minimum_selections' => ['nullable', 'integer', 'min:0'],
            'selection_rule.maximum_selections' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
