<?php

namespace App\Support\Admin\WebAuthn;

use Webauthn\CredentialRecord;

final readonly class AdminWebAuthnAssertionResult
{
    public function __construct(
        public AdminWebAuthnAssertionOutcome $outcome,
        public ?CredentialRecord $credential = null,
    ) {}
}
