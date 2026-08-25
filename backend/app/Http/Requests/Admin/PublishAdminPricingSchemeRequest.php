<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class PublishAdminPricingSchemeRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
        ];
    }
}
