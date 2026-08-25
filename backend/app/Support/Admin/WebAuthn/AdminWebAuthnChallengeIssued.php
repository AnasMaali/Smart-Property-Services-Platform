<?php

namespace App\Support\Admin\WebAuthn;

/**
 * Result of AdminWebAuthnChallengeService::issue() (BLUE V1 Phase A2.3).
 *
 * $ticket is the challenge row's own uuid - safe to expose to the client
 * exactly like any other uuid identifier this API already returns
 * (session_uuid, booking uuid, ...). It is never the raw challenge itself
 * and carries no secret: it exists purely so a later request (Stage 2 MFA
 * verify / first-credential enroll) can tell the server which pending
 * login attempt it is completing, without the server needing a separate
 * session/cookie to track it. The actual challenge match is still verified
 * independently by hash inside AdminWebAuthnChallengeService::consume().
 *
 * $rawChallenge is the 32 raw challenge bytes, used exactly once to build
 * the WebAuthn options shown to the browser - never persisted itself, only
 * its SHA-256 hash is (see AdminWebAuthnChallengeService).
 */
final readonly class AdminWebAuthnChallengeIssued
{
    public function __construct(
        public string $ticket,
        public string $rawChallenge,
    ) {}
}
