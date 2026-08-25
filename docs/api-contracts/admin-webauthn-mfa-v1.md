# BLUE V1 — Admin WebAuthn / MFA (Phase A2.1 + A2.2)

This document describes **only what actually exists** after Phase A2.2: the schema Phase A2.1 added
(`database/blue_v1_schema.sql` / `database/phase16_admin_webauthn_mfa_schema_migration.sql`), and
the WebAuthn library, configuration, challenge service, credential repository, and
registration/assertion ceremony *services* Phase A2.2 added on top of it. No aspirational or
planned behavior is included.

## Scope — IMPLEMENTED NOW vs. NOT IMPLEMENTED YET

**Implemented now (Phase A2.1 + A2.2):**
- Schema: `admin_webauthn_challenge_purposes`, `admin_webauthn_challenges`, `admin_webauthn_credentials`.
- WebAuthn library integration (`web-auth/webauthn-lib` ^5.3 — see "Library" below).
- `config/admin_webauthn.php` (RP name/ID, allowed origins, challenge TTL).
- `App\Support\Admin\WebAuthn\AdminWebAuthnChallengeService` — centralized challenge issuance/consumption.
- `App\Support\Admin\WebAuthn\AdminWebAuthnCredentialRepository` — the only reader/writer of `admin_webauthn_credentials`.
- `App\Support\Admin\WebAuthn\AdminWebAuthnRegistrationService` — registration options + verification, with the first-credential/step-up rule enforced.
- `App\Support\Admin\WebAuthn\AdminWebAuthnAssertionService` — assertion options + verification, shared by `LOGIN_ASSERTION` and `STEP_UP`.
- Full test coverage of all of the above via a real-crypto test authenticator (`tests/Support/WebAuthn/WebAuthnTestAuthenticator`), exercising the actual `web-auth/webauthn-lib` validation logic end-to-end.

**NOT implemented yet:**
- No HTTP route exists for any of this — registration/assertion are pure services, not wired into
  `routes/api.php`.
- `POST /v1/admin/auth/login` is completely unchanged; MFA is not mandatory and no session is ever
  created through WebAuthn.
- No idle timeout, no step-up HTTP endpoint/middleware, no admin_audit_logs writes for MFA events.
- No Admin frontend/browser screens.
- No Tailscale infrastructure inside Laravel (by design — see below).

All of the above belong to Phase A2.3 onward.

## Where device trust lives (read this first)

Production device/network trust is owned by **Tailscale**, entirely outside Laravel — only
explicitly approved Tailscale devices can reach the Admin Panel origin and `/v1/admin/*` at all.
Laravel never re-implements device approval. Consequently:

- **There is no `admin_trusted_devices` table**, and none is planned. Building one would duplicate
  Tailscale's own device management with strictly weaker signals (MAC address, IP, User-Agent —
  all explicitly excluded as trust mechanisms).
- The tables below are **MFA credential/challenge storage only** — a second authentication factor
  proving *who* is logging in, layered on top of the network boundary Tailscale already enforces,
  never a device-identity or device-approval system in their own right.

## Tables added

### `admin_webauthn_challenge_purposes` — reference/lookup table

Mirrors the existing `otp_verification_purposes` convention exactly (`id`/`code`/`name`/
`description`/`is_active`) rather than a raw `CHECK`-constrained enum column, since every other
purpose/status classifier in this schema (`otp_verification_purposes`, `booking_statuses`,
`technician_statuses`, ...) already uses this pattern. Seeded with exactly three codes:

| Code | Meaning |
|---|---|
| `REGISTRATION` | A challenge issued while an Admin registers a new WebAuthn credential |
| `LOGIN_ASSERTION` | A challenge issued during Admin login, proven by an existing credential |
| `STEP_UP` | A challenge issued to re-verify an already-authenticated Admin before a sensitive operation |

### `admin_webauthn_challenges` — short-lived, single-use ceremony state

One generalized table for all three ceremony types (via `purpose_id`), rather than three
duplicated tables — mirroring how `otp_verifications` is already shared across
`PHONE_VERIFICATION`/`PASSWORD_RESET`/`PHONE_NUMBER_CHANGE`/`LOGIN` today.

- `challenge_hash` (`binary(32)`) stores **`SHA-256(raw challenge)` only** — the same
  store-a-hash-never-the-raw-value convention `auth_sessions.refresh_token_hash` and
  `password_reset_sessions.reset_token_hash` already use. The raw challenge is never persisted.
- `expires_at > created_at` and `consumed_at >= created_at` are enforced by `CHECK` constraints
  (mirroring `otp_verifications`/`password_reset_sessions`); single-use is `consumed_at IS NULL`
  until consumed, exactly once.
- Deliberately **not** linked to a specific `admin_webauthn_credentials` row — a challenge is
  scoped to a user + purpose, not to a single credential. Which credential ultimately answers a
  `LOGIN_ASSERTION`/`STEP_UP` challenge is resolved at ceremony time by
  `AdminWebAuthnAssertionService` (via `AdminWebAuthnCredentialRepository`), never persisted state.

### `admin_webauthn_credentials` — the MFA factor itself

- `user_id` is a plain FK to `users.id` only. **It deliberately does not encode role membership.**
  Whether the owning user currently holds an active `ADMIN`/`SUPER_ADMIN` role is re-checked
  dynamically at the application layer on every request, exactly like `auth_sessions` already
  works — baking that mutable fact into a foreign key would go stale the moment a role changes.
- `credential_id` is globally unique (`UNIQUE KEY`), per the WebAuthn spec's own requirement — not
  merely unique per user.
- `public_key` stores the credential's **public** key only.
- `sign_count`, `transports`, `aaguid` are authenticator metadata for a future verification
  library to consume; `aaguid` is stored as a plain `binary(16)` in its natural byte order (unlike
  this schema's own generated ids, it is an externally-supplied opaque value, never passed through
  `UuidBinary`'s index-locality byte-swap).
- `backup_eligible`/`backup_state` capture the WebAuthn "BE"/"BS" authenticator-data flags for
  **security visibility only** (nullable — `NULL` means "not yet recorded/unknown"). Phase A2
  deliberately does not reject syncable/backed-up credentials at the schema or policy level:
  WebAuthn's job here is a phishing-resistant second factor, not device identity, since Tailscale
  already owns device trust.
- `revoked_at`/`revoked_by_user_id`/`revoke_reason` follow this schema's established revocation
  pattern (`technician_assignments.release_reason`, `admin_role_permissions.granted_by_user_id`):
  `revoke_reason` is required whenever `revoked_at` is set (`CHECK`); the actor reference uses
  `ON DELETE SET NULL` since it identifies *who acted*, not the row's owner.
- **Multiple credentials per Admin are fully supported** — no uniqueness constraint on `user_id`
  alone, only on `credential_id` globally.

### Never stored, anywhere in this schema

Private keys, biometric data, authenticator PINs, or any secret that belongs only on the
authenticator. The server only ever holds public key material, a credential identifier, and a
hash of a one-time challenge.

## The "first credential" rule

The locked product rule: an Admin may self-register their *first* WebAuthn credential once
password-authenticated (with production network access already gated by Tailscale); any credential
added *after* that requires the caller to assert a fresh WebAuthn step-up. This needed no schema
field — `AdminWebAuthnCredentialRepository::activeCount()` (`WHERE user_id = ? AND revoked_at IS
NULL`) already answers "does this Admin have zero active credentials?" unambiguously.

**Enforced now**, at the service layer: `AdminWebAuthnRegistrationService::options()`/`verify()`
both take an explicit `bool $stepUpVerified` parameter and refuse (`STEP_UP_REQUIRED`) whenever the
Admin already holds ≥1 active credential and that flag is `false`. No HTTP route exists yet that
could call this insecurely — no caller in this phase can ever legitimately produce `true` for such
an Admin, since the real step-up ceremony (Phase A2.5) does not exist yet. The rule is enforced
regardless, so a future caller cannot forget to check it.

## Library

`web-auth/webauthn-lib` ^5.3 (MIT, PHP ≥8.2, actively maintained, the canonical standards-compliant
PHP FIDO2/WebAuthn implementation). See the Phase A2.2 review report for the full selection
rationale. Its Symfony dependencies (`symfony/uid`, `symfony/clock`, ...) were already present in
this Laravel 13 app at compatible versions.

## Ceremony architecture

- `App\Support\Admin\WebAuthn\AdminWebAuthnConfig` — resolves `config/admin_webauthn.php`, throwing
  if `rp_id`/`allowed_origins` are unset. Never derives either from a request header.
- `App\Support\Admin\WebAuthn\AdminWebAuthnCeremonyFactory` — builds the library's serializer and
  the two ceremony validators, with `NoneAttestationStatementSupport` only (registration requests
  `attestation: none`) and the library's default counter checker.
- `App\Support\Admin\WebAuthn\AdminWebAuthnChallengeService` — the single place challenges are
  issued/consumed, shared by all three purposes; stores only `SHA-256(raw challenge)`
  (`admin_webauthn_challenges.challenge_hash`), atomically single-use under a row lock.
- `App\Support\Admin\WebAuthn\AdminWebAuthnCredentialRepository` — the only reader/writer of
  `admin_webauthn_credentials`; always excludes revoked rows.
- `App\Support\Admin\WebAuthn\AdminWebAuthnRegistrationService` /
  `AdminWebAuthnAssertionService` — the registration and assertion ceremonies themselves. Every
  rejection reason (wrong role, bad/expired/replayed challenge, wrong origin/RP ID, missing user
  verification, bad signature, unknown/revoked credential) is deliberately collapsed into one of a
  small number of generic outcomes — never a granular per-cause message — matching this codebase's
  existing anti-oracle convention (`AdminLoginAction`, `AuthenticateAdmin`).

**User Verification is always `required`** — hardcoded, not env-configurable, so it can never be
silently weakened to `preferred` by a misconfigured environment.

**Counter policy**: the library's default (`Webauthn\Counter\ThrowExceptionIfInvalid`). A stored
counter of `0` with a reported counter of `0` is treated as "this authenticator does not support a
counter" and is never rejected (many resident-key/passkey authenticators always report `0`). Once a
real counter has been recorded, any non-increasing value on a later assertion is treated as a clone
warning and hard-fails verification.

## Not built yet (deliberately)

Any `/v1/admin/*` HTTP route, any change to Admin login/session behavior, idle timeout, step-up
HTTP endpoint/middleware, and audit-log writes for MFA events. All belong to Phase A2.3 onward.
