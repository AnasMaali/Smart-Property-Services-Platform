<?php

namespace App\Http\Requests\Payment;

use App\Http\Requests\ApiFormRequest;

/**
 * POST /v1/payments carries no authoritative financial fields at all - the
 * server derives amount, currency, and checkout_reference entirely from
 * the caller's own ACTIVE cart (see CreatePaymentAttemptAction). Every
 * field a client might mistakenly send to influence the financial outcome
 * is explicitly `prohibited` rather than silently ignored, so a client bug
 * that tries to send one fails loudly with a 422 instead of quietly having
 * no effect.
 */
class CreatePaymentRequest extends ApiFormRequest
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
            'payment_status' => ['prohibited'],
            'provider_status' => ['prohibited'],
            'success' => ['prohibited'],
            'checkout_reference' => ['prohibited'],
            'cart_uuid' => ['prohibited'],
        ];
    }
}
