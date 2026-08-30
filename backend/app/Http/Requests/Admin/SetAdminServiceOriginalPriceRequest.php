<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class SetAdminServiceOriginalPriceRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Mirrors CreateAdminPricingRuleRequest's effect_amount rule
            // exactly (numeric, never float-typed math downstream - the
            // Action always re-serializes via (string) before writing to
            // the decimal(19,6) column). null clears the original/list
            // price.
            'original_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
