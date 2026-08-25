<?php

namespace App\Support\Admin\WebAuthn;

use Webauthn\PublicKeyCredentialRequestOptions;

final readonly class AdminWebAuthnAssertionOptionsResult
{
    public function __construct(
        public string $ticket,
        public PublicKeyCredentialRequestOptions $options,
    ) {}
}
