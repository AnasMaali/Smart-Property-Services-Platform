<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * Admin "Force Complete" (BLUE V1 Phase B17) - a break-glass override, so a
 * reason is always mandatory, matching CancelAdminBookingRequest's
 * convention exactly (same booking_status_history/
 * booking_item_status_history.reason CHECK bounds: 2-500 chars).
 */
class ForceCompleteAdminBookingRequest extends ApiFormRequest
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
