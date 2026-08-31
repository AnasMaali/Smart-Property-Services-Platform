<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Support\Admin\AdminFinancialDateRange;
use Illuminate\Validation\Rule;

class GetAdminRefundReportRequest extends ApiFormRequest
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
            'status' => ['nullable', 'string', Rule::exists('booking_refund_statuses', 'code')],
        ];
    }
}
