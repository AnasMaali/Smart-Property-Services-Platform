# BLUE V1 — Admin Authentication & Authorization API Contract (Phase 9A, superseded by Phase A2.3/A2.4)

Base URL: `{{base_url}}` (local default: `http://127.0.0.1:8000/api/v1`)

This document describes the Admin authentication/authorization endpoints actually implemented in
`backend/routes/api.php`, their Form Requests, Actions, Controllers, and middleware, verified
against `backend/tests/Feature/Admin/*`. It documents only what exists in code — no aspirational
or planned behavior is included.

> **BLUE V1 Phase A2.3 — Mandatory Admin MFA Login — SUPERSEDES §1 below.** The single-request,
> password-only `POST /v1/admin/auth/login` that returned a session/token directly **no longer
> exists**. Admin login is now a mandatory two-stage flow — password, then WebAuthn — and a correct
> password alone can never create a session or issue a token. See "§1. Admin Login (Stage 1)",
> "§1a. First-Credential Bootstrap", and "§1b. MFA Verify (Stage 2)" below for the current contract.
>
> **BLUE V1 Phase A2.4 — Admin Session Security — ADDS to §1b/§2/`auth.admin` below.** ADMIN_WEB
> sessions (only) now carry a 12-hour absolute lifetime (instead of the Customer 30-day default), a
> 20-minute idle timeout enforced on both Bearer requests and refresh, and a throttled (~5-minute)
> server-side activity timestamp. A silent token refresh is explicitly never treated as activity.
> See "Admin Session Security (Phase A2.4)" below for the full behavior.

## Scope of this phase

Phase 9A builds the Admin **authentication and authorization security boundary** only:

- Admin login, token refresh, identity bootstrap (`/v1/admin/me`).
- The `auth.admin` authorization middleware that protects future Admin operational APIs.

It does **not** expose any Admin operational endpoints (service management, booking management,
technician assignment, job start/complete, etc.). Those belong to later phases and will be built
behind `auth.admin` once this document is extended.

## The core security property

> **Customer authentication ≠ Admin authorization.**
>
> A user must (1) be authenticated, **and** (2) currently hold the `ADMIN` or `SUPER_ADMIN` role,
> to access any route protected by `auth.admin`. A valid Customer access token never grants
> access to Admin routes, and a valid Admin access token never grants access to Customer-only
> (`auth.customer`) routes — each middleware re-checks the caller's current role membership
> against `user_roles`/`roles` on every single request. Role membership is never trusted from the
> JWT's `role` claim, which exists only as a convenience/logging value. Consequently:
>
> - If an Admin's role is revoked after a token was issued, the very next request using that
>   token — even before it expires — is rejected.
> - If an Admin account is deactivated after a token was issued, the same applies.

## Global notes

- All responses are JSON with the same envelope used by customer authentication: `{ "success": bool, "message": string, "data": object|null }`.
- Validation failures return HTTP `422` with Laravel's standard `FormRequest` shape.
- Admin identity is represented by the **same** `users` table as customers — there is no separate
  `admins` table. Admin/Super Admin membership is determined entirely by `user_roles` rows joined
  to `roles.code IN ('ADMIN', 'SUPER_ADMIN')` with `roles.is_active = 1`. A `role_id`/numeric role
  identifier is never accepted from a request and never exposed in a response.
- Admin sessions use the same `auth_sessions` infrastructure, JWT signing (`JwtTokenService`), and
  refresh-token rotation as customer sessions — no separate cryptographic implementation exists.
  The only differences are the role requirement and the client type.
- **Client type**: Admin sessions always use `ADMIN_WEB` (seeded in `auth_client_types`). Unlike
  customer login, the Admin login endpoint does not accept a `client_type` field — it is fixed
  server-side, since there is only one Admin client type in Version 1.
- **Access token TTL / session TTL**: identical to customer sessions — 15 minutes / 30 days
  (`config('jwt.access_token_ttl_minutes')`, `config('jwt.session_ttl_days')`).
- **Phone verification is not required for Admin login.** Admin accounts are provisioned directly
  (an authorized internal process, per `docs/05-system-requirements/04-role-and-access-control-requirements.md` §18: "Admin accounts shall be created through an authorized process"), not through the
  OTP-verified customer registration flow. Phase 9A does not add an Admin account-creation
  endpoint; Admin users must currently be provisioned directly in the database.
- Nothing in this document exposes password hashes, refresh token hashes, numeric role/user IDs,
  or raw binary UUIDs. All example values are placeholders.

---

## Endpoint summary

| # | Feature | Method | Route | Auth required |
|---|---|---|---|---|
| 1 | Admin Login (Stage 1 — password) | POST | `/v1/admin/auth/login` | No (rate limited: 5/min per identity + 20/min per IP) — **never returns a session** |
| 1a | First-Credential Bootstrap | POST | `/v1/admin/auth/mfa/enroll` | No — gated by a short-lived `login_ticket` (rate limited: 10/min per ticket + 30/min per IP) — **never returns a session** |
| 1b | MFA Verify (Stage 2 — WebAuthn) | POST | `/v1/admin/auth/mfa/verify` | No — gated by a short-lived `login_ticket` (rate limited: 10/min per ticket + 30/min per IP) — **the only endpoint that creates a session** |
| 2 | Admin Refresh Access Token | POST | `/v1/admin/auth/refresh` | No (refresh token in body) |
| 3 | Admin "Me" (identity bootstrap) | GET | `/v1/admin/me` | Yes — `auth.admin` (Bearer) |
| — | Admin Logout | POST | `/v1/auth/logout` | Yes (Bearer) — **reused from customer auth**, see below |
| — | Admin Logout All Sessions | POST | `/v1/auth/logout-all` | Yes (Bearer) — **reused from customer auth**, see below |

### Why logout / logout-all are not new endpoints

`LogoutAction` and `LogoutAllAction` (see `authentication-v1.md` §6–7) only require a valid,
non-revoked, non-expired session belonging to a currently `ACTIVE` user — they never check role.
They already work unchanged for Admin sessions created through `/v1/admin/auth/login`, so Phase
9A reuses the existing `/v1/auth/logout` and `/v1/auth/logout-all` routes rather than duplicating
session-revocation logic under a separate Admin path.

---

## 1. Admin Login (Stage 1 — password)

BLUE V1 Phase A2.3. `App\Actions\Auth\AdminLoginAction` / `App\Http\Controllers\Api\V1\Admin\Auth\AdminLoginController`.

> **Non-negotiable rule**: a correct password here NEVER creates an `auth_sessions` row, an access
> token, or a refresh token. This endpoint's only job is to validate the password and identity,
> then hand back a short-lived WebAuthn challenge for whichever of the two flows below applies.

- **HTTP method / route**: `POST /v1/admin/auth/login`
- **Auth required**: No
- **Rate limiting**: `throttle:admin-auth-login` — unchanged from before this phase: 5/min per
  hashed `phone_number` identity + 20/min per hashed client IP, both independent buckets.
- **Request JSON**:
```json
{
  "phone_number": "{{admin_phone_number}}",
  "password": "{{admin_password}}"
}
```
  `device_name`/`app_version` are no longer accepted here — this request never creates a session,
  so session/device metadata is captured where the session is actually created (§1b, MFA Verify).
- **Fields**:
  | Field | Required | Rules |
  |---|---|---|
  | `phone_number` | Yes | string, `^\+?[0-9]{8,20}$` |
  | `password` | Yes | string |

- **Success status**: `200 OK` — exactly one of two states, decided by
  `App\Support\Admin\WebAuthn\AdminWebAuthnCredentialRepository::activeCount()` for this Admin:

### A. `MFA_REQUIRED` — the Admin already has ≥1 active WebAuthn credential
```json
{
  "success": true,
  "message": "WebAuthn verification required.",
  "data": {
    "state": "MFA_REQUIRED",
    "login_ticket": "9c1e2b3a-....-....-....-............",
    "webauthn": {
      "rp_id": "admin.example.com",
      "challenge": "<base64url>",
      "allow_credentials": [
        { "id": "<base64url>", "type": "public-key", "transports": ["internal"] }
      ],
      "user_verification": "required",
      "timeout": null
    }
  }
}
```
Feed `data.webauthn` directly into `navigator.credentials.get({ publicKey: ... })` (after base64url
-decoding `challenge`/`allow_credentials[].id` per the WebAuthn browser API's binary fields), then
call §1b (MFA Verify) with the resulting assertion and the same `login_ticket`.

### B. `MFA_ENROLLMENT_REQUIRED` — the Admin has zero active WebAuthn credentials
```json
{
  "success": true,
  "message": "WebAuthn credential enrollment required.",
  "data": {
    "state": "MFA_ENROLLMENT_REQUIRED",
    "login_ticket": "1a2b3c4d-....-....-....-............",
    "webauthn": {
      "rp": { "id": "admin.example.com", "name": "BLUE Admin" },
      "user": { "id": "<base64url>", "name": "+971500001234", "display_name": "Omar Al Admin" },
      "challenge": "<base64url>",
      "pub_key_cred_params": [{ "type": "public-key", "alg": -7 }, { "type": "public-key", "alg": -257 }],
      "authenticator_selection": { "user_verification": "required" },
      "attestation": "none",
      "exclude_credentials": [],
      "timeout": null
    }
  }
}
```
Feed `data.webauthn` into `navigator.credentials.create({ publicKey: ... })`, then call §1a
(First-Credential Bootstrap) with the resulting attestation and the same `login_ticket`.

Both states are reachable **only after a genuine password proof** — it is deliberately acceptable
for the two to be distinguishable from each other (see "Anti-enumeration" below); what an
unauthenticated caller can never learn is whether a given phone number/password pair is even valid.

`login_ticket` is the uuid of the `admin_webauthn_challenges` row Stage 1 created (see
`App\Support\Admin\WebAuthn\AdminWebAuthnChallengeIssued`) — an opaque, short-lived (~5 minutes,
`ADMIN_WEBAUTHN_CHALLENGE_TTL_SECONDS`), single-use bearer identifier for this one pending login
attempt, exposed the same way `session_uuid` already is elsewhere in this API. It carries no secret
itself; the actual WebAuthn challenge match is independently re-verified by hash inside
`AdminWebAuthnChallengeService::consume()` regardless of what ticket accompanies a response.

- **Error status**: `422 Unprocessable Entity` for business failure; `429 Too Many Requests` once
  the rate limit is exceeded.
- **Example error JSON**:
```json
{
  "success": false,
  "message": "The phone number or password you entered is incorrect.",
  "data": null
}
```
  This exact same message/status is returned for: unknown phone number, wrong password,
  non-`ACTIVE` account status, a missing/inactive `ADMIN`/`SUPER_ADMIN` role (including a normal
  `CUSTOMER`-only account attempting to log in here), and an inactive/missing `ADMIN_WEB` client
  type. The response never reveals which of these occurred.
- **Never returned**: `access_token`, `refresh_token`, `session_uuid`, a password hash, or any raw
  binary/internal database id.

---

## 1a. First-Credential Bootstrap

BLUE V1 Phase A2.3. `App\Actions\Auth\AdminMfaEnrollAction` / `App\Http\Controllers\Api\V1\Admin\Auth\AdminMfaEnrollController`.

The **only** password-authenticated WebAuthn credential registration path — reachable exclusively
via the `login_ticket` Stage 1 issued for `MFA_ENROLLMENT_REQUIRED`. It is deliberately **not** a
general "register a new credential" endpoint: if the caller already holds ≥1 active credential by
the time this runs, it is rejected with the same generic failure below (server-enforced, not merely
UI-hidden — see `AdminWebAuthnRegistrationService`'s `STEP_UP_REQUIRED` outcome, reused unchanged
from Phase A2.2). Adding further credentials to an Admin who already has one is step-up protected
and belongs to a later phase (A2.5+), with no route here.

- **HTTP method / route**: `POST /v1/admin/auth/mfa/enroll`
- **Auth required**: No — gated entirely by `login_ticket`
- **Rate limiting**: `throttle:admin-auth-mfa-enroll` — 10/min per ticket + 30/min per IP.
- **Request JSON**:
```json
{
  "login_ticket": "1a2b3c4d-....-....-....-............",
  "credential": { "id": "...", "rawId": "...", "type": "public-key", "response": { "clientDataJSON": "...", "attestationObject": "..." } }
}
```
  `credential` is the raw `PublicKeyCredential` JSON object `navigator.credentials.create()`
  produced in the browser — validated in shape only at this layer; the full WebAuthn ceremony
  (challenge match, origin, RP ID, attestation format, user verification) is
  `AdminWebAuthnRegistrationService::verify()`'s job.
- **Success status**: `200 OK`. **Registration alone never issues a session** — the stricter
  `registration → assertion → session` model is used deliberately (BLUE V1 Phase A2 architecture).
  On success the response is identical in shape to Stage 1's `MFA_REQUIRED` (§1.A above): a fresh
  `login_ticket` and `LOGIN_ASSERTION` WebAuthn options for the credential just registered, to be
  completed immediately via §1b.
- **Error status**: `422 Unprocessable Entity` (generic message below) for: unknown Admin,
  unknown/expired/already-consumed `login_ticket`, an Admin who already has an active credential,
  or any WebAuthn ceremony failure (challenge/origin/RP ID/attestation/user-verification).
```json
{
  "success": false,
  "message": "This WebAuthn verification could not be completed.",
  "data": null
}
```

---

## 1b. MFA Verify (Stage 2 — WebAuthn assertion)

BLUE V1 Phase A2.3. `App\Actions\Auth\AdminMfaVerifyAction` / `App\Http\Controllers\Api\V1\Admin\Auth\AdminMfaVerifyController`.

**The only endpoint in the entire Admin API that creates an `auth_sessions` row or issues a
token.** Uses `App\Actions\Auth\Concerns\IssuesAdminAuthSession` — the exact same production
session-issuance mechanism this endpoint's password-only predecessor used, now reached only after a
verified WebAuthn assertion.

- **HTTP method / route**: `POST /v1/admin/auth/mfa/verify`
- **Auth required**: No — gated entirely by `login_ticket` (never a bearer token; a forged
  `Authorization` header has no effect on the outcome)
- **Rate limiting**: `throttle:admin-auth-mfa-verify` — 10/min per ticket + 30/min per IP.
- **Request JSON**:
```json
{
  "login_ticket": "9c1e2b3a-....-....-....-............",
  "credential": { "id": "...", "rawId": "...", "type": "public-key", "response": { "clientDataJSON": "...", "authenticatorData": "...", "signature": "...", "userHandle": "..." } },
  "device_name": "Ops MacBook",
  "app_version": "1.0.0"
}
```
  `credential` is the raw `PublicKeyCredential` JSON `navigator.credentials.get()` produced.
  `device_name`/`app_version` are optional and now belong here (moved from Stage 1 — see §1),
  since this is the request that actually creates the session.
- **Server-side checks, in order** (all re-read fresh from the database — nothing from Stage 1 is
  trusted):
  1. Resolve the pending `login_ticket` (unknown/expired/consumed → generic failure).
  2. Re-check `ACTIVE` account status + active `ADMIN`/`SUPER_ADMIN` role.
  3. Re-check the `ADMIN_WEB` client type is active.
  4. Verify the WebAuthn assertion: credential belongs to this Admin and is not revoked; origin;
     RP ID; challenge match + single-use; user verification required; signature; sign-counter
     policy (see `AdminWebAuthnCeremonyFactory`'s docblock — a clone-warning counter regression
     hard-fails; a `0`/`0` counter, common for passkeys, is never treated as suspicious).
  5. **Immediately before issuing the session**, re-check account/role eligibility one more time —
     the WebAuthn ceremony itself takes real wall-clock time (network round trip + user
     interaction), during which a revocation could otherwise land in the gap.
  6. Only if every check above passed: create `auth_sessions`, issue the access/refresh token pair.
- **Success status**: `200 OK` — the exact same session response shape the old password-only
  endpoint returned:
```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "user_uuid": "3f2a1c9e-....-....-....-............",
    "full_name": "Omar Al Admin",
    "phone_number": "+971500001234",
    "email": "omar@example.com",
    "role": "ADMIN",
    "roles": ["ADMIN"],
    "session_uuid": "7e1f4b02-....-....-....-............",
    "access_token": "<jwt>",
    "access_token_expires_at": "2026-08-12T12:15:00+00:00",
    "refresh_token": "<64-hex-char raw token>",
    "session_expires_at": "2026-09-11T12:00:00+00:00"
  }
}
```
- **Error status**: `422 Unprocessable Entity`:
```json
{
  "success": false,
  "message": "This WebAuthn verification could not be completed.",
  "data": null
}
```
  Returned identically for every rejection reason listed under "Server-side checks" above — a
  wrong/unregistered/revoked credential, a bad/expired/replayed challenge, a wrong origin/RP ID, a
  missing user-verification flag, an invalid signature, a sign-counter clone signal, or an
  account/role that stopped being eligible between Stage 1 and this call. None are distinguished.
- **Security notes**: raw refresh token returned exactly once; IP/user-agent captured at this
  request (not Stage 1, since this is where the session is actually created); no password, no
  public key, and no raw binary/internal id ever appears in the response. The session created here
  uses the Admin-specific 12-hour absolute lifetime, not the Customer 30-day default — see "Admin
  Session Security (Phase A2.4)" below.

---

## 2. Admin Refresh Access Token

- **HTTP method / route**: `POST /v1/admin/auth/refresh`
- **Auth required**: No (authorization is via the `refresh_token` body field)
- **Request JSON**:
```json
{
  "refresh_token": "{{admin_refresh_token}}"
}
```
- **Fields**: identical to customer refresh — `refresh_token`, string, exactly 64 hex characters.
- **Success status**: `200 OK`
- **Success response**: identical shape to customer refresh (`access_token`,
  `access_token_expires_at`, `refresh_token`, `session_uuid`, `session_expires_at`).
- **Error status**: `422 Unprocessable Entity`
- **Example error JSON**:
```json
{
  "success": false,
  "message": "This refresh token is invalid or has expired.",
  "data": null
}
```
  Same message/status for: unknown token, revoked/expired session, non-`ACTIVE` user, a
  missing/inactive `ADMIN`/`SUPER_ADMIN` role **re-checked fresh at refresh time** (not from the
  original login), a session whose client type is not the active `ADMIN_WEB` type, or (BLUE V1
  Phase A2.4, Admin sessions only) a session that has exceeded the Admin idle timeout — see "Admin
  Session Security (Phase A2.4)" below. This means an Admin whose role was revoked or account
  deactivated after login loses the ability to refresh on the very next call, even though the raw
  refresh token itself is still otherwise valid.
- **Business behavior**: Same token-rotation strategy as customer refresh (locate by
  `SHA-256(raw token)` under `FOR UPDATE`, rotate the hash, issue a new access token) — no
  separate rotation algorithm exists. The new access token's `role` claim is recomputed from the
  user's current role membership, not cached from the previous token. **For Admin sessions only**
  (BLUE V1 Phase A2.4): a successful refresh never updates `last_used_at` — a silent refresh is
  never treated as activity — and never extends `expires_at`. The Customer refresh endpoint's
  behavior (which does update `last_used_at` on every successful refresh) is unchanged.

---

## 3. Admin "Me"

- **HTTP method / route**: `GET /v1/admin/me`
- **Auth required**: Yes — `Authorization: Bearer {{admin_access_token}}`, enforced by the
  `auth.admin` middleware (see below).
- **Success status**: `200 OK`
- **Success response**:
```json
{
  "success": true,
  "message": "Admin identity retrieved successfully.",
  "data": {
    "user_uuid": "3f2a1c9e-....-....-....-............",
    "full_name": "Omar Al Admin",
    "phone_number": "+971500001234",
    "email": "omar@example.com",
    "roles": ["ADMIN"]
  }
}
```
  Deliberately separate from `GET /v1/profile` (customer profile), which returns customer-only
  data (`customer_profiles`, service interests, property relationship) that an Admin user does not
  have.
- **Error status**: `401 Unauthorized` (see `auth.admin` below) — same generic message/shape as
  customer session errors: `{"success": false, "message": "This session is invalid or has expired."}`.

---

## Anti-enumeration and timing (Phase A2.3)

Stage 1 preserves the exact pre-existing anti-enumeration contract for anything an attacker could
learn *without* a valid password: unknown phone number, wrong password, inactive account, and
missing/inactive Admin role all return the identical generic `422`. Once the password has genuinely
been proven correct, it is deliberately acceptable — and unavoidable — for the caller to learn
whether `MFA_REQUIRED` or `MFA_ENROLLMENT_REQUIRED` applies, since reaching either state already
required a real password proof; this reveals nothing to anyone who has not already authenticated
that far. A response-latency difference between a fast password failure and a slower successful
Stage 1 call (which additionally issues a WebAuthn challenge) is an inherent, already-precedented
characteristic of this flow — the same kind of difference a successful vs. failed login already had
before this phase (session creation vs. an immediate rejection) — and is not treated as a new attack
surface; rate limiting, not constant-time responses, is this codebase's established defense against
enumeration throughout.

## Local development

WebAuthn MFA is mandatory in every environment, including local development — there is no
`APP_ENV`-based bypass anywhere in this flow. The only environment-specific difference is outside
Laravel entirely: production restricts network reachability of the Admin origin to approved
Tailscale devices, while local development simply runs against `localhost` with
`ADMIN_WEBAUTHN_RP_ID`/`ADMIN_WEBAUTHN_ORIGINS` set to the developer's local browser origin (see
`docs/api-contracts/admin-webauthn-mfa-v1.md`).

## Removed: the old password-only session flow

Before this phase, `POST /v1/admin/auth/login` validated the password and returned a full session
(`access_token`, `refresh_token`, `session_uuid`) in one request — see the (now historical) example
in §1 above this notice was added next to. That single-request flow **no longer exists in any
form** — there is no hidden password-only route, query parameter, or internal flag that bypasses
MFA. There is exactly one canonical production Admin login flow: password (§1) → WebAuthn (§1a or
§1b) → session, always.

---

## `auth.admin` middleware

`App\Http\Middleware\AuthenticateAdmin`, aliased as `auth.admin`. Mirrors `auth.customer`
(`AuthenticateCustomer`) exactly in structure — see `authentication-v1.md` and that class's own
docblock for the shared design — with three differences:

1. It authorizes against `ADMIN`/`SUPER_ADMIN` (at least one currently active) instead of
   `CUSTOMER`.
2. It does not require `phone_verified_at` to be set, since Admin accounts are not provisioned
   through the OTP-verified customer flow.
3. (BLUE V1 Phase A2.4) It enforces the Admin idle timeout and performs the throttled activity
   touch — see "Admin Session Security (Phase A2.4)" below. `AuthenticateCustomer` has neither.

On every request it: verifies the JWT signature and `exp`/`nbf`; loads the `auth_sessions` row by
the token's `sid` claim and confirms it is not revoked and not expired; **(Phase A2.4) confirms the
session has not exceeded the Admin idle timeout, revoking it if it has**; loads the `users` row by
`sub` and confirms `account_status = ACTIVE`; and re-queries `user_roles` joined to `roles` for at
least one currently active `ADMIN`/`SUPER_ADMIN` row. Any failure returns the same generic `401`.
On success it **(Phase A2.4) touches the session's activity timestamp if due**, then attaches
`auth_user`, `auth_session`, and `auth_admin_roles` (the caller's active Admin role codes, e.g.
`['ADMIN']` or `['ADMIN', 'SUPER_ADMIN']`) to the request for Admin controllers/Actions.

---

## Admin Session Security (Phase A2.4)

`App\Support\Admin\AdminSessionPolicy` — the single, centralized implementation used identically by
`auth.admin` (every Bearer request) and Admin refresh (§2), so the two enforcement points can never
drift. **ADMIN_WEB only** — Customer/mobile sessions never consult this class at all and keep their
existing 30-day (`AUTH_SESSION_TTL_DAYS`) behavior unchanged, including Customer refresh's existing
`last_used_at` update.

### Absolute session lifetime

`config/admin_session.php` → `AUTH_ADMIN_SESSION_TTL_HOURS` (default **12 hours**). Set once, on
`auth_sessions.expires_at`, at MFA-issued session creation (§1b) — **never extended by refresh**.
Example: login at 08:00 → `expires_at` = 20:00. A refresh at 14:00 still leaves `expires_at` = 20:00.

### Idle timeout

`AUTH_ADMIN_IDLE_TIMEOUT_MINUTES` (default **20 minutes**). A session is idle-expired once
`now >= last_used_at + idle_timeout` — the exact boundary instant is already expired (the same
"boundary is the first invalid instant" convention this codebase's absolute-expiry check already
uses). Example: `last_used_at` = 10:00 → a request at 10:19:59 is allowed; a request at 10:20:00 or
any instant after is rejected.

An idle-expired session is **revoked (`revoked_at` set) at the moment it is first detected**,
whether that detection happens on a Bearer request or a refresh call — so it can never become usable
again through either path afterward, even under a later idle-timeout config change or clock
adjustment. The rejection response is the same generic, pre-existing message
(`"This session is invalid or has expired."` for Bearer requests via `auth.admin`,
`"This refresh token is invalid or has expired."` for refresh) — the specific reason ("idle timeout"
vs. any other rejection cause) is never revealed to the caller.

### Activity touch (throttled, not on every request)

`AUTH_ADMIN_ACTIVITY_TOUCH_MINUTES` (default **5 minutes**). A successful, authenticated Admin
Bearer request updates `last_used_at` to the current time only if the stored value is already at
least this old (or was never set) — an atomic, conditional `UPDATE ... WHERE last_used_at <= ?`, so
concurrent/rapid requests can only ever move the value forward, never backward, and a healthy Admin
session does not write to `auth_sessions` on every single request.

**A token refresh is never activity.** `POST /v1/admin/auth/refresh` enforces the idle timeout (a
refresh against an already idle-expired session is rejected, exactly like a Bearer request) but
never calls the activity-touch step — a frontend that silently refreshes tokens in the background
could otherwise keep an abandoned session alive indefinitely.

### Frontend implications (for a future Admin UI — not built in this phase)

- Detect `401`/invalid-session responses, clear local Admin auth state, and return to the Admin
  login screen — never silently retry indefinitely.
- Never poll the API in the background merely to keep a session alive — server-side "activity" means
  genuine authenticated Admin API usage; deliberate keep-alive polling would defeat the idle-timeout
  policy's purpose while technically satisfying it.
- An optional local warning shortly before the idle timeout may be shown for UX purposes, based on
  the frontend's own local interaction tracking (e.g. mouse/keyboard activity) — never treat that
  local signal as the server's security source of truth, which is always `last_used_at` on the
  server.

---

## Admin WebAuthn Step-Up Authentication (Phase A2.5)

Reusable fresh-WebAuthn re-proof for sensitive Admin operations on an already-authenticated
session — **not** a second login. First (and, as of this phase, only) protected operation:
`POST /v1/admin/contracts/{contract}/cancel`.

**Prerequisites, unchanged by this phase:** the Admin must already have a valid `ADMIN_WEB`
session (`auth.admin` passes — ACTIVE account, active `ADMIN`/`SUPER_ADMIN` role, non-idle
session) and the operation's own `admin.capability:<code>` grant. Step-up adds a third, orthogonal
requirement on top of both: a *recent* WebAuthn proof, specifically for this session.

### Request — `POST /v1/admin/auth/step-up/request`

Requires `auth.admin`. No request body. Confirms the caller holds ≥1 active WebAuthn credential
(otherwise a generic `422` failure, same shape/message as every other MFA-family rejection — see
"Anti-enumeration and timing" above), issues a `STEP_UP`-purpose WebAuthn challenge **bound to the
caller's current `auth_sessions` row**, and returns it — the same `{rp_id, challenge,
allow_credentials, user_verification, timeout}` shape §1/§1b already use. Creates no session, no
token, and does not touch `auth_sessions.last_used_at`/`step_up_verified_at`.

```json
{
  "success": true,
  "message": "WebAuthn step-up verification required.",
  "data": {
    "step_up_ticket": "…uuid…",
    "webauthn": { "rp_id": "…", "challenge": "…", "allow_credentials": [ … ], "user_verification": "required", "timeout": … }
  }
}
```

### Verify — `POST /v1/admin/auth/step-up/verify`

Requires `auth.admin`. Body: `{ "step_up_ticket": "…", "credential": { …PublicKeyCredential JSON… } }`.
On success, sets **only the current session's** `auth_sessions.step_up_verified_at = now()` and
returns `{ "step_up_verified_until": "…ISO 8601…" }`. Every rejection reason — unknown/wrong-
session/expired/already-consumed ticket, wrong or revoked credential, wrong origin/RP ID, missing
user verification, bad signature, a sign-counter clone signal — returns the same generic `422`
failure used throughout this document, and marks nothing.

### Session binding (critical)

A `STEP_UP` challenge is bound to **both** the Admin user **and** the exact `auth_sessions` row
that requested it (`admin_webauthn_challenges.auth_session_id`, added by this phase). A challenge
requested under session A can never be used to step up session B, even for the same Admin signed in
twice (two tabs/devices) — enforced structurally at the database-row level (the verify lookup
filters on the presented session's id), not merely by convention.

### Freshness window

`config/admin_session.php` → `AUTH_ADMIN_STEP_UP_TTL_MINUTES` (default **5 minutes**), always
clamped to never exceed `AUTH_ADMIN_IDLE_TIMEOUT_MINUTES`. **Reusable, not consumed per action**: one
successful verify keeps `admin.stepup`-protected routes open for this session until
`step_up_verified_at + TTL`, using the same "boundary is the first invalid instant" convention as
idle timeout (`now < step_up_verified_at + TTL` — fresh; `now >=` that instant — stale). The
sensitive action itself never clears or extends `step_up_verified_at`.

### `admin.stepup` middleware

`App\Http\Middleware\EnsureAdminStepUpIsFresh`. Runs after `auth.admin` **and** after
`admin.capability:<code>` on a given route — step-up proves identity freshness, never authorization;
a caller who fails the capability check never learns whether their step-up is fresh. On a stale/
missing step-up it returns **`428 Precondition Required`** with a machine-readable top-level `code`:

```json
{ "success": false, "message": "This action requires a fresh WebAuthn verification.", "code": "STEP_UP_REQUIRED" }
```

`428`, not `403`/`401`, was chosen deliberately: `403` already means "you can never do this without a
role/permission change" (`admin.capability`'s own rejection), and the session itself is genuinely
still valid, so `401` would be actively wrong. A step-up failure **never revokes the Admin session**
— the caller stays fully authenticated and can immediately retry via request → verify.

### Login / refresh / logout interaction

- **Login** (§1b): a freshly MFA-issued session always starts with `step_up_verified_at = NULL`.
  Login MFA proves login authentication; it is deliberately never treated as an automatic step-up for
  sensitive-operation purposes.
- **Refresh** (§2): rotating the refresh token never creates, resets, or extends
  `step_up_verified_at` — whatever value the row already has is left completely untouched, mirroring
  how refresh already never touches `last_used_at`.
- **Logout / idle / absolute expiry**: once a session is revoked or expired by any existing
  mechanism, it is rejected by `auth.admin` before `admin.stepup` is ever reached — there is no
  separate step-up "session" to clean up.

### `contracts.cancel`

`POST /v1/admin/contracts/{contract}/cancel` middleware order:
`auth.admin` → `admin.capability:contracts.cancel` → `admin.stepup`. A blocked attempt (missing/
stale step-up) never reaches `App\Actions\Admin\Contract\AdminCancelContractAction` — no partial
state change, no audit-log row. Every other Contract mutation (`approve`, `send-for-acceptance`,
`suspend`) is unaffected by this phase.

### Rate limiting

`admin-auth-step-up-request` and `admin-auth-step-up-verify` — dual-bucket (authenticated-identity +
IP), 10/min identity + 30/min IP each, registered in `App\Providers\AppServiceProvider` alongside
the existing `admin-auth-*` limiters.

---

## Admin Security Audit Trail (Phase A2.6)

Security-sensitive Admin authentication events are written to the existing
`admin_audit_logs` table through `App\Support\Admin\AdminAuditLogger`.

No new audit table or schema change was required: the existing
`was_successful` / `failure_reason` columns already support both successful
and failed security events.

### Events recorded

| Action code | When it is written |
|---|---|
| `ADMIN_LOGIN_SUCCESS` | After a valid WebAuthn `LOGIN_ASSERTION` succeeds and the new `ADMIN_WEB` session is created |
| `ADMIN_LOGIN_MFA_FAILED` | When a valid Stage-2 login ticket resolves to a real Admin but WebAuthn verification fails |
| `WEBAUTHN_CREDENTIAL_REGISTERED` | After the first Admin WebAuthn credential is successfully persisted |
| `STEP_UP_VERIFIED` | After a valid session-bound WebAuthn `STEP_UP` assertion succeeds and `step_up_verified_at` is updated |
| `STEP_UP_FAILED` | When an authenticated Admin attempts Step-Up verification and the ceremony fails |
| `ADMIN_LOGOUT` | When an `ADMIN_WEB` session is successfully revoked through `/v1/auth/logout` |
| `ADMIN_LOGOUT_ALL` | When an Admin-initiated `/v1/auth/logout-all` successfully revokes the user's sessions |

### Success / failure semantics

Successful state changes and their corresponding audit rows are committed
inside the same database transaction wherever the two belong together.

Failed MFA and Step-Up records use a deliberately generic failure reason.
The audit trail does not persist the granular cryptographic rejection cause,
preventing it from becoming an oracle for credential, challenge, origin,
signature, or authenticator state.

Pre-authentication password-stage failures are deliberately not written to
`admin_audit_logs`. In particular, an unknown phone number / wrong password
attempt remains externally and internally unsuitable as an Admin identity
audit event because doing otherwise could create an account-enumeration side
channel.

Account/role eligibility failures occurring before a WebAuthn ceremony is
accepted are likewise not mislabeled as `ADMIN_LOGIN_MFA_FAILED`.

### Audit data minimization

The Admin security audit trail never stores:

- passwords or password hashes
- access tokens or refresh tokens
- refresh-token hashes
- raw WebAuthn challenges or assertions
- WebAuthn signatures
- raw WebAuthn `credential_id`
- credential public keys
- authenticator PINs or biometric data
- full request bodies

`WEBAUTHN_CREDENTIAL_REGISTERED.entity_identifier` contains only BLUE's own
internal `admin_webauthn_credentials.id` UUID, never the authenticator's raw
credential identifier.

For login/logout/step-up events, identifiers are limited to server-resolved
BLUE user/session identifiers and small safe metadata such as Admin role,
client type, or revoked-session count.

### Customer isolation

`/v1/auth/logout` and `/v1/auth/logout-all` remain shared Customer/Admin
routes. Their Actions resolve the actual session first and write
`ADMIN_LOGOUT` / `ADMIN_LOGOUT_ALL` only when the initiating session's client
type is `ADMIN_WEB`.

Customer `MOBILE_IOS` / `MOBILE_ANDROID` logout behavior is unchanged and
never creates Admin security-audit rows.

### Events deliberately not audited

Routine high-frequency lifecycle events are intentionally excluded:

- successful Admin refresh
- idle-timeout expiry
- absolute-session expiry
- ordinary authenticated activity touches

These states are already represented by `auth_sessions` and auditing every
occurrence would create high-volume operational noise without materially
improving the security trail.

---

## Not built in this phase (deliberately)

- Any Admin operational endpoint (service management, booking management, technician assignment,
  job start/complete, etc.) — Phase 9B+.
- ~~A permission framework beyond role codes — no `permissions` table exists in the schema, so none
  was invented. `SUPER_ADMIN` is treated identically to `ADMIN` for authorization purposes in this
  phase; any capability difference between the two belongs to a later phase.~~ **Superseded by
  BLUE V1 Phase A1** — see `docs/api-contracts/admin-authorization-v1.md`. A capability-based
  permission layer (`admin_permissions` / `admin_role_permissions`,
  `App\Support\Admin\AdminAuthorizationService`, the `admin.capability` route middleware) now sits
  on top of the `auth.admin` boundary this document describes, without changing anything in this
  document — `auth.admin` still only answers "is this an authenticated Admin?", never "is this
  Admin allowed to do X?".
- ~~MFA/2FA — not required by any current requirement document.~~ **Superseded by BLUE V1 Phase
  A2.3** — WebAuthn MFA is now mandatory for every Admin login; see §1/§1a/§1b above and
  `docs/api-contracts/admin-webauthn-mfa-v1.md` for the underlying credential/challenge
  infrastructure (Phase A2.1/A2.2).
- Admin password change/reset endpoints — the existing `/v1/auth/change-password`,
  `/v1/auth/forgot-password`, etc. routes remain customer-only (gated by `auth.customer` /
  unauthenticated OTP flows respectively) and were not extended to Admin accounts in this phase.
  Admin password management is a `CAN WAIT` item for a later phase; nothing in this phase weakens
  or duplicates the existing password-security mechanisms.
- ~~Login/logout and WebAuthn security-event audit rows.~~ **Implemented by BLUE V1 Phase A2.6** —
  see "Admin Security Audit Trail (Phase A2.6)" above. The seven implemented security events are
  `ADMIN_LOGIN_SUCCESS`, `ADMIN_LOGIN_MFA_FAILED`, `ADMIN_LOGOUT`, `ADMIN_LOGOUT_ALL`,
  `WEBAUTHN_CREDENTIAL_REGISTERED`, `STEP_UP_VERIFIED`, and `STEP_UP_FAILED`.
- Step-up protection on any operation other than `contracts.cancel` — the architecture
  (`admin.stepup`, `AdminSessionPolicy::isStepUpFresh()`/`markStepUpVerified()`,
  `AdminWebAuthnAssertionService`'s session-binding parameter) is intentionally reusable for future
  sensitive operations (Admin user management, permission changes, WebAuthn credential management,
  `pricing.publish`, `payments.refund`, …), but none of those routes were touched in this phase.
