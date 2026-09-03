<?php

namespace App\Http\Requests\Booking;

use App\Http\Requests\ApiFormRequest;

class CreateBookingRatingRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('comment') && is_string($this->comment)) {
            $this->merge(['comment' => trim($this->comment)]);
        }
    }

    public function rules(): array
    {
        return [
            'rating_value' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'min:2', 'max:1000'],
        ];
    }
}
