<?php

namespace App\Http\Requests\ServiceCatalog;

use App\Http\Requests\ApiFormRequest;

class PreviewServicePricingRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'options' => ['sometimes', 'array'],
            'options.*.option_uuid' => ['required_with:options', 'string'],
            'options.*.numeric_value' => ['sometimes'],
            'options.*.boolean_value' => ['sometimes', 'boolean'],
            'options.*.text_value' => ['sometimes', 'string'],
            'options.*.choice_uuids' => ['sometimes', 'array'],
            'options.*.choice_uuids.*' => ['string'],
        ];
    }
}
