<?php

namespace App\Http\Requests\Admin\AppointmentSchedule;

use App\Http\Requests\ApiFormRequest;

class GetAdminAppointmentScheduleRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
