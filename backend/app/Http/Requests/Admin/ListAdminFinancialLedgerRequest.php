<?php

namespace App\Http\Requests\Admin;

use App\Actions\Admin\Financial\AdminListFinancialLedgerAction;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class ListAdminFinancialLedgerRequest extends ApiFormRequest
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
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'event_type' => ['nullable', 'string', Rule::in(AdminListFinancialLedgerAction::EVENT_TYPES)],
            'payment_method' => ['nullable', 'string', Rule::in(AdminListFinancialLedgerAction::PAYMENT_METHODS)],
            'direction' => ['nullable', 'string', Rule::in(AdminListFinancialLedgerAction::DIRECTIONS)],
            'booking_uuid' => ['nullable', 'uuid'],
        ];
    }
}
