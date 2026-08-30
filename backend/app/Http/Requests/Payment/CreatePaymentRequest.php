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
            // BLUE V1 Phase B24 - the customer's declared payment-method
            // intent, validated against the Cart's authoritative available
            // methods in App\Actions\Payment\CreatePaymentAttemptAction -
            // never trusted for the actual Stripe call itself (Stripe's own
            // `automatic_payment_methods` already decides what to present;
            // see docs/api-contracts/payments-v1.md "Apple Pay readiness").
            // Only CARD/APPLE_PAY are valid here - PAY_ON_SITE never
            // reaches this endpoint at all (see POST /v1/bookings/pay-on-site).
            'payment_method' => ['required', 'string', 'in:CARD,APPLE_PAY'],
        ];
    }
}
