<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Support\Admin\AdminFinancialDateRange;
use Illuminate\Validation\Rule;

class GetAdminFinancialDashboardRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'range' => ['nullable', 'string', Rule::in(AdminFinancialDateRange::PRESETS)],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_if:range,CUSTOM'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_if:range,CUSTOM'],
        ];
    }
}
