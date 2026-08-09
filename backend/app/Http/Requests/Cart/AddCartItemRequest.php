<?php

namespace App\Http\Requests\Cart;

use App\Http\Requests\ApiFormRequest;

/**
 * Shape-only validation. Every option-type-aware, catalog-aware rule (which
 * value field a given option_uuid must use, min/max/step, required options,
 * choice ownership, ...) is deliberately left to CartSelectionValidator,
 * since it depends on the submitted service's live schema and cannot be
 * expressed as a static rule set here. Only the keys defined below ever
 * reach the Action via validated() - a client-supplied price/subtotal/
 * total/currency/pricing_rule_id field is silently dropped, never read.
 */
class AddCartItemRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_uuid' => ['required', 'uuid'],
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
