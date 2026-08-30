<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class SetAdminServicePaymentMethodsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_methods' => ['required', 'array', 'min:1'],
            'payment_methods.*' => ['required', 'string', 'in:CARD,APPLE_PAY,PAY_ON_SITE'],
        ];
    }
}
