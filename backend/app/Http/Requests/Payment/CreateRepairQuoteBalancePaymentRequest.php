<?php

namespace App\Http\Requests\Payment;

use App\Http\Requests\ApiFormRequest;

/**
 * POST /v1/bookings/{booking}/quote/pay-balance carries no authoritative
 * financial fields - mirrors App\Http\Requests\Payment\CreatePaymentRequest
 * exactly. The server derives amount/currency entirely from the caller's
 * own ACTIVE, ACCEPTED repair quote (see App\Actions\Payment\
 * CreateRepairQuoteBalancePaymentAction) - CARD/APPLE_PAY only, resolved by
 * Stripe's own `automatic_payment_methods`, never a client-declared method.
 */
class CreateRepairQuoteBalancePaymentRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['prohibited'],
            'currency' => ['prohibited'],
            'quote_uuid' => ['prohibited'],
        ];
    }
}
