<?php

namespace App\Http\Requests\Checkout;

use App\Http\Requests\ApiFormRequest;

/**
 * The client submits slot identity only - never a time_window, price, or
 * any other pricing-context field. The backend derives every pricing
 * context attribute itself (see CheckoutContextResolver); nothing here is
 * ever read as a context value.
 */
class CreateAppointmentHoldRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'appointment_slot_uuid' => ['required', 'uuid'],
        ];
    }
}
