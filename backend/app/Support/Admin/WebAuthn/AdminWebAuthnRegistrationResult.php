<?php

namespace App\Support\Admin\WebAuthn;

use Webauthn\CredentialRecord;

final readonly class AdminWebAuthnRegistrationResult
{
    public function __construct(
        public AdminWebAuthnRegistrationOutcome $outcome,
        public ?CredentialRecord $credential = null,
        public ?string $credentialUuid = null,
    ) {}
}
