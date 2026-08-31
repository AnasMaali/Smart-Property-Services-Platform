<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * Mirrors App\Http\Requests\Admin\PreviewAdminServicePricingRequest's
 * `quantity`/`options` shape exactly - the explicit-version pricing preview
 * validates/evaluates selections through the exact same
 * App\Support\Cart\CartSelectionValidator/pricing calculation engine the
 * real Cart flow uses. Adds an optional `context` map of resolved pricing
 * context attribute values (e.g. `SERVICE_ZONE`) an Admin may supply
 * explicitly to preview a context-conditional rule - never synthesized or
 * guessed by this codebase.
 */
class PreviewAdminPricingSchemeVersionRequest extends ApiFormRequest
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

            'context' => ['sometimes', 'array'],
            'context.*' => ['nullable', 'string', 'max:190'],
        ];
    }
}
