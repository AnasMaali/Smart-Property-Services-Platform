<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class CreateAdminServiceOptionChoiceAttributeRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attribute_type_code' => ['required', 'string', Rule::exists('service_option_choice_attribute_types', 'code')],
            'value' => ['required', 'string', 'max:255'],
        ];
    }
}
