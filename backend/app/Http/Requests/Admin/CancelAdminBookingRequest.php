<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * Admin "Cancel Booking" (BLUE V1 Phase B16) - unlike the optional-reason
 * Contract cancel/suspend convention (ContractActionReasonRequest), a
 * reason is mandatory here: this is the one Admin-initiated Booking status
 * change this phase supports, and it must always leave a real explanation
 * in booking_status_history/booking_item_status_history/
 * technician_assignments.release_reason.
 */
class CancelAdminBookingRequest extends ApiFormRequest
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
            'reason' => ['required', 'string', 'min:2', 'max:500'],
        ];
    }
}
