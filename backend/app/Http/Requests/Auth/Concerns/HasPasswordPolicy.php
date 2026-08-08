<?php

namespace App\Http\Requests\Auth\Concerns;

use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rules\Password;

trait HasPasswordPolicy
{
    /**
     * The approved V1 password policy: minimum 8 characters, at least one
     * letter, at least one number, and (outside the testing environment)
     * rejection of common/previously compromised passwords.
     */
    private function passwordRule(): Password
    {
        $rule = Password::min(8)->letters()->numbers();

        return App::environment('testing') ? $rule : $rule->uncompromised();
    }
}
