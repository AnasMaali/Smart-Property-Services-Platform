<?php

namespace App\Http\Requests\Booking;

use App\Http\Requests\ApiFormRequest;

/**
 * POST /v1/bookings/pay-on-site carries no authoritative financial fields
 * at all - mirrors App\Http\Requests\Payment\CreatePaymentRequest exactly:
 * every field a client might send to influence the financial outcome is
 * explicitly `prohibited`, so a client bug that tries to send one fails
 * loudly with a 422 instead of quietly having no effect. The server derives
 * amount, items, and location entirely from the caller's own ACTIVE cart
 * (see App\Actions\Booking\CreatePayOnSiteBookingAction).
 */
class CreatePayOnSiteBookingRequest extends ApiFormRequest
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
            'status' => ['prohibited'],
            'cart_uuid' => ['prohibited'],
        ];
    }
}
