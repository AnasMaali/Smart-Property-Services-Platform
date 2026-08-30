<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * Mirrors App\Http\Requests\Cart\AddCartItemRequest's `options` shape
 * exactly - the Admin pricing preview tool validates/evaluates selections
 * through the exact same App\Support\Cart\CartSelectionValidator/
 * App\Support\Pricing\PricingEngine the real Cart flow uses.
 */
class PreviewAdminServicePricingRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity' => ['sometimes', 'integer', 'between:1,1000'],

            'options' => ['sometimes', 'array'],
            'options.*.option_uuid' => ['required', 'uuid'],
            'options.*.numeric_value' => ['nullable', 'numeric'],
            'options.*.boolean_value' => ['nullable', 'boolean'],
            'options.*.text_value' => ['nullable', 'string', 'max:1000'],
            'options.*.choice_uuids' => ['nullable', 'array'],
            'options.*.choice_uuids.*' => ['uuid'],
        ];
    }
}
