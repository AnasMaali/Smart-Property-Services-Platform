<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class SetAdminServiceCurrentPriceRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Mirrors CreateAdminPricingRuleRequest's effect_amount rule -
            // the actual checkout selling price can never be zero/negative
            // (BLUE V1 catalog spec section 6: "current price > 0 unless
            // existing business rules explicitly allow free Services" - no
            // such rule exists yet).
            'current_price' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
