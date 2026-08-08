<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Auth\Concerns\HasPasswordPolicy;

class ChangePasswordRequest extends ApiFormRequest
{
    use HasPasswordPolicy;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'confirmed', $this->passwordRule()],
        ];
    }
}
