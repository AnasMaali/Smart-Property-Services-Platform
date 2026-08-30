<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * BLUE V1 Phase B25 - shared amount-validation shape reused verbatim by
 * every repair-quote Controller that accepts a `quoted_amount` (create,
 * edit-draft, revise) - the rule is identical in all three cases and the
 * three Actions each independently enforce their own status/state
 * eligibility, so a single Request class avoids duplicating the exact same
 * two validation lines three times.
 */
class CreateAdminRepairQuoteRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Mirrors SetAdminServiceCurrentPriceRequest's current_price
            // rule - credit_amount/balance_due_amount are never accepted
            // from the client, only ever server-computed (see
            // App\Actions\Admin\Booking\AdminCreateRepairQuoteAction).
            'quoted_amount' => ['required', 'numeric', 'gt:0'],
            'credit_amount' => ['prohibited'],
            'balance_due_amount' => ['prohibited'],
        ];
    }
}
