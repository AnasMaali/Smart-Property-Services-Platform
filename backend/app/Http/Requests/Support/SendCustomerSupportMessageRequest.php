<?php

namespace App\Http\Requests\Support;

use App\Http\Requests\ApiFormRequest;

class SendCustomerSupportMessageRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'message' => is_string($this->message) ? trim($this->message) : $this->message,
        ]);
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:2', 'max:2000'],
        ];
    }
}
