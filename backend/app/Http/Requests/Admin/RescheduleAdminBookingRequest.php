<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * Admin "Reschedule Booking" (BLUE V1 Phase B19). `appointment_slot_uuid`
 * is deliberately validated as a plain string here, NOT Laravel's `uuid`
 * rule - malformed and unknown must both resolve to 404 (the same privacy
 * convention every other Booking/slot identifier in this Admin module
 * already follows), never split into a 422 for malformed shape.
 */
class RescheduleAdminBookingRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => is_string($this->reason) ? trim($this->reason) : $this->reason,
        ]);
    }

    public function rules(): array
    {
        return [
            'appointment_slot_uuid' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:2', 'max:500'],
        ];
    }
}
