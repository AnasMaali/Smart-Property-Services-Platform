<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * Matches `chk_support_messages_body` exactly (BLUE V1 Phase B7):
 * `char_length(trim(message_body)) between 1 and 5000`.
 */
class SendAdminSupportMessageRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'message_body' => is_string($this->message_body) ? trim($this->message_body) : $this->message_body,
        ]);
    }

    public function rules(): array
    {
        return [
            'message_body' => ['required', 'string', 'min:1', 'max:5000'],
        ];
    }
}
