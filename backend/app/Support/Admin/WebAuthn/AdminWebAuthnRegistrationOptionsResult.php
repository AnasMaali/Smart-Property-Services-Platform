<?php

namespace App\Support\Admin\WebAuthn;

use Webauthn\PublicKeyCredentialCreationOptions;

final readonly class AdminWebAuthnRegistrationOptionsResult
{
    public function __construct(
        public AdminWebAuthnRegistrationOutcome $outcome,
        public ?PublicKeyCredentialCreationOptions $options = null,
    ) {}
}
