# BLUE V1 — Admin WebAuthn / MFA (Phase A2.1–A2.6)

This document describes the complete BLUE V1 Admin WebAuthn/MFA security foundation through
Phase A2.6: schema (A2.1), WebAuthn library/challenge/credential/ceremony services (A2.2),
mandatory MFA login (A2.3), hardened Admin session policy (A2.4), session-bound Step-Up
authentication (A2.5), and the Admin authentication security-audit trail (A2.6).

`docs/api-contracts/admin-authentication-v1.md` is the authoritative HTTP/session contract for the
Admin login, refresh, logout, Step-Up, session-security, and audit behavior. This document remains
focused primarily on the WebAuthn credential/challenge/ceremony architecture underneath those
flows.

## Scope — IMPLEMENTED NOW vs. NOT IMPLEMENTED YET

**Implemented now (Phase A2.1–A2.6):**

- Schema: `admin_webauthn_challenge_purposes`, `admin_webauthn_challenges`,
  `admin_webauthn_credentials`, plus the A2.5 session-binding additions
  `auth_sessions.step_up_verified_at` and
  `admin_webauthn_challenges.auth_session_id`.
- WebAuthn library integration (`web-auth/webauthn-lib` ^5.3).
- `config/admin_webauthn.php` for RP identity, allowed origins, and challenge TTL.
- Centralized challenge issuance/consumption through
  `AdminWebAuthnChallengeService`.
- Credential persistence and lookup through
  `AdminWebAuthnCredentialRepository`.
- Registration and assertion ceremony services using real
  standards-compliant WebAuthn verification.
- Mandatory password → WebAuthn → Admin-session login flow:
  `POST /v1/admin/auth/login`, `/mfa/enroll`, `/mfa/verify`.
- Admin-only 12-hour absolute session lifetime and 20-minute idle timeout,
  enforced centrally through `AdminSessionPolicy`.
- Session-bound WebAuthn Step-Up:
  `/v1/admin/auth/step-up/request` and `/verify`.
- `admin.stepup` middleware with `contracts.cancel` as the first protected
  sensitive mutation.
- Phase A2.6 security auditing for login success/MFA failure, first credential
  registration, Step-Up success/failure, Admin logout, and Admin logout-all.
- End-to-end automated coverage using real ECDSA WebAuthn test cryptography.

**Not implemented yet:**

- General authenticated Admin WebAuthn credential-management endpoints
  (list/add/revoke additional credentials).
- Credential-management frontend/UI.
- Additional `admin.stepup`-protected operations beyond the currently
  protected `contracts.cancel`.
- Admin frontend/browser screens.
- Tailscale infrastructure inside Laravel — deliberately excluded because
  device/network trust belongs to Tailscale, not application code.

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
Admin already holds ≥1 active credential and that flag is `false`. `App\Actions\Auth\AdminMfaEnrollAction` remains a first-credential bootstrap endpoint and
always passes `stepUpVerified: false`; it therefore can never be used to add a second credential.

Phase A2.5 now provides the real authenticated, session-bound WebAuthn Step-Up mechanism that a
future general credential-management endpoint can use before legitimately invoking registration
with `stepUpVerified: true`. No such general add/revoke credential endpoint is exposed yet.

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

## Phase A2.4–A2.6 integration

### Admin session hardening (A2.4)

Admin MFA-issued sessions use a separate Admin session policy: 12-hour absolute lifetime and
20-minute idle timeout by default. Refresh never extends the absolute expiry and never counts as
Admin activity. See `admin-authentication-v1.md` for the authoritative policy.

### Session-bound Step-Up (A2.5)

`STEP_UP` challenges are now bound to the exact authenticated `auth_sessions` row through
`admin_webauthn_challenges.auth_session_id`. Successful verification updates only that session's
`step_up_verified_at`.

This prevents a challenge issued from one concurrent session from upgrading another session owned
by the same Admin.

The first protected operation is `contracts.cancel`, enforced through the `admin.stepup`
middleware.

### Security auditing (A2.6)

The WebAuthn/Admin authentication lifecycle now writes the following security events to
`admin_audit_logs`:

- `ADMIN_LOGIN_SUCCESS`
- `ADMIN_LOGIN_MFA_FAILED`
- `WEBAUTHN_CREDENTIAL_REGISTERED`
- `STEP_UP_VERIFIED`
- `STEP_UP_FAILED`
- `ADMIN_LOGOUT`
- `ADMIN_LOGOUT_ALL`

Credential-registration audit records reference only BLUE's internal credential UUID. Raw WebAuthn
credential identifiers, public keys, challenges, assertions, signatures, passwords, and tokens are
never written into the audit trail.

See `admin-authentication-v1.md` → **Admin Security Audit Trail (Phase A2.6)** for the authoritative
event semantics and data-minimization rules.

## Not built yet (deliberately)

General WebAuthn credential-management endpoints/UI and additional Step-Up-protected sensitive
operations beyond `contracts.cancel` remain future work. Tailscale device/network trust remains
outside Laravel by design.
