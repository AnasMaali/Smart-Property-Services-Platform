<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class ListAdminRatingsRequest extends ApiFormRequest
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
            'rating_value' => ['nullable', 'integer', 'min:1', 'max:5'],
            'max_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'booking_uuid' => ['nullable', 'uuid'],
            'customer_uuid' => ['nullable', 'uuid'],
        ];
    }
}
