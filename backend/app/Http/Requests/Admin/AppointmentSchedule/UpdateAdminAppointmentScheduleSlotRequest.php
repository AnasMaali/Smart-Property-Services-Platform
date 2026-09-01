<?php

namespace App\Http\Requests\Admin\AppointmentSchedule;

use App\Http\Requests\ApiFormRequest;

/**
 * Deliberately never accepts `starts_at`/`ends_at`/`time_window_id` -
 * BLUE V1's Phase B27 safety policy is that a dated slot's time fields
 * become immutable the moment it has any occupancy (active hold or
 * converted Booking); see App\Actions\Admin\AppointmentSchedule\
 * AdminUpdateAppointmentScheduleSlotAction. `is_active` is also excluded
 * here - closing/reopening a slot is its own dedicated Action (Activate/
 * DeactivateAdminAppointmentScheduleSlotAction) with its own audit event
 * and existing-Bookings warning, never silently folded into a capacity
 * edit.
 */
class UpdateAdminAppointmentScheduleSlotRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'booking_capacity' => ['required', 'integer', 'min:1', 'max:10000'],
            'internal_note' => ['nullable', 'string', 'min:2', 'max:500'],
        ];
    }
}
