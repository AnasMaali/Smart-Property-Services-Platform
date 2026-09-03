<?php

namespace App\Http\Requests\Support;

use App\Http\Requests\ApiFormRequest;

class CreateCustomerSupportRequestRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'subject' => is_string($this->subject) ? trim($this->subject) : $this->subject,
            'message' => is_string($this->message) ? trim($this->message) : $this->message,
        ]);
    }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'min:3', 'max:200'],
            'message' => ['required', 'string', 'min:2', 'max:2000'],
            'booking_uuid' => ['sometimes', 'nullable', 'string', 'uuid'],
        ];
    }
}
