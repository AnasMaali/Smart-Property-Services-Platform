<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Support\Admin\AdminFinancialDateRange;
use Illuminate\Validation\Rule;

class GetAdminBookingReportRequest extends ApiFormRequest
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
            'range' => ['nullable', 'string', Rule::in(AdminFinancialDateRange::PRESETS)],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_if:range,CUSTOM'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_if:range,CUSTOM'],
            'status' => ['nullable', 'string', Rule::exists('booking_statuses', 'code')],
            'payment_method' => ['nullable', 'string', Rule::in(['CARD', 'APPLE_PAY', 'PAY_ON_SITE'])],
            'booking_number' => ['nullable', 'string', 'max:40'],
            'customer_uuid' => ['nullable', 'uuid'],
        ];
    }
}
