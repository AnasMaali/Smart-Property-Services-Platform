<?php

namespace App\Http\Requests\Admin\AppointmentSchedule;

use App\Http\Requests\ApiFormRequest;

class GenerateAdminAppointmentScheduleRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'booking_capacity' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
