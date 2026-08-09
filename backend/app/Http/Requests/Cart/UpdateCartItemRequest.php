<?php

namespace App\Http\Requests\Cart;

use App\Http\Requests\ApiFormRequest;

/**
 * See AddCartItemRequest for why option validation is shape-only here.
 * `options`, when present, is the FULL replacement selection set - the
 * Action never merges it with previously-persisted selections.
 */
class UpdateCartItemRequest extends ApiFormRequest
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
