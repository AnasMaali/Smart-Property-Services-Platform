<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class ListAdminBookingsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', Rule::exists('booking_statuses', 'code')],
            'booking_number' => ['nullable', 'string', 'max:40'],
            'customer_uuid' => ['nullable', 'uuid'],
            'technician_uuid' => ['nullable', 'uuid'],
            'service_uuid' => ['nullable', 'uuid'],
            'assignment_state' => ['nullable', 'string', Rule::in(['PENDING', 'PARTIAL', 'FULL'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'appointment_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
