# BLUE V1 — Admin WebAuthn / MFA Schema Foundation (Phase A2.1)

This document describes **only what exists after Phase A2.1**: three new database tables and
their reference data. It documents the schema `database/blue_v1_schema.sql` /
`database/phase16_admin_webauthn_mfa_schema_migration.sql` actually contains — no aspirational or
planned behavior.

## Scope of this phase

**Schema foundation only.** No application code reads or writes any table below. There is no
WebAuthn registration endpoint, no login MFA step, no step-up endpoint, and Admin login/session
behavior (Phase 9A/9B, Phase A1) is completely unchanged. That logic is Phase A2.2 onward.

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
  `LOGIN_ASSERTION`/`STEP_UP` challenge is resolved at ceremony time by the (not-yet-built)
  application logic, not persisted state.

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

## The "first credential" rule — how the schema supports it without a new column

The locked product rule is: an Admin may self-register their *first* WebAuthn credential once
password-authenticated (with production network access already gated by Tailscale); any credential
added or revoked *after* that must require an authenticated session **and** a fresh WebAuthn
step-up. This rule needs **no additional schema field**. "Does this Admin currently have zero
active credentials?" is exactly `SELECT 1 FROM admin_webauthn_credentials WHERE user_id = ? AND
revoked_at IS NULL` — the existing `revoked_at`-based filtering (and its covering
`idx_admin_webauthn_credentials_user_active` index) already answers it unambiguously. Enforcing
*when* step-up is required is application logic for Phase A2.5, not a schema concern.

## Not built in this phase (deliberately)

WebAuthn registration/login/step-up ceremony logic, any `/v1/admin/*` route touching these tables,
any change to Admin login/session behavior, idle timeout, step-up middleware, and audit-log writes
for MFA events. All belong to Phase A2.2 onward.
