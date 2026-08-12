# BLUE V1 — Admin Authentication & Authorization API Contract (Phase 9A)

Base URL: `{{base_url}}` (local default: `http://127.0.0.1:8000/api/v1`)

This document describes the Admin authentication/authorization endpoints actually implemented in
`backend/routes/api.php`, their Form Requests, Actions, Controllers, and middleware, verified
against `backend/tests/Feature/Admin/*`. It documents only what exists in code — no aspirational
or planned behavior is included.

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
| 1 | Admin Login | POST | `/v1/admin/auth/login` | No (rate limited: 5/min per IP) |
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

## 1. Admin Login

- **HTTP method / route**: `POST /v1/admin/auth/login`
- **Auth required**: No
- **Rate limiting**: `throttle:5,1` — 5 requests per minute per client IP (Laravel's built-in
  rate limiter). The 6th request within the window receives `429 Too Many Requests` regardless of
  whether the credentials are correct. Successful requests are not exempted from the counter, but
  normal login usage (well under 5/min) is unaffected.
- **Headers**: `Content-Type: application/json`
- **Request JSON**:
```json
{
  "phone_number": "{{admin_phone_number}}",
  "password": "{{admin_password}}",
  "device_name": "Ops MacBook",
  "app_version": "1.0.0"
}
```
- **Fields**:
  | Field | Required | Rules |
  |---|---|---|
  | `phone_number` | Yes | string, `^\+?[0-9]{8,20}$` |
  | `password` | Yes | string |
  | `device_name` | No | string, max:120 |
  | `app_version` | No | string, max:30 |

- **Success status**: `200 OK`
- **Success response**:
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
  `role` is the single highest-priority active role used for the JWT's `role` claim
  (`SUPER_ADMIN` takes priority over `ADMIN` when a user holds both). `roles` lists every active
  Admin role code the user currently holds, for the Admin UI to consult if it ever needs to
  distinguish `SUPER_ADMIN`-only capabilities in a later phase.
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
  non-`ACTIVE` account status, and — critically — an account that exists, is `ACTIVE`, and has the
  correct password, but holds no currently active `ADMIN`/`SUPER_ADMIN` role (including a normal
  `CUSTOMER`-only account attempting to log in here). The response never reveals which of these
  occurred.
- **Business behavior**: Creates a new `auth_sessions` row (`client_type_id` = `ADMIN_WEB`,
  30-day expiry from now), stores only `SHA-256(raw refresh token)`, updates
  `users.last_login_at`, and issues an access token embedding `sub`, `sid`,
  `role` (highest-priority active Admin role), `client=ADMIN_WEB`.
- **Security notes**: identical to customer login (raw refresh token returned exactly once; IP
  stored packed; no password/hash ever in the response).

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
  original login), or a session whose client type is not the active `ADMIN_WEB` type. This means
  an Admin whose role was revoked or account deactivated after login loses the ability to refresh
  on the very next call, even though the raw refresh token itself is still otherwise valid.
- **Business behavior**: Same token-rotation strategy as customer refresh (locate by
  `SHA-256(raw token)` under `FOR UPDATE`, rotate the hash, issue a new access token) — no
  separate rotation algorithm exists. The new access token's `role` claim is recomputed from the
  user's current role membership, not cached from the previous token.

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

## `auth.admin` middleware

`App\Http\Middleware\AuthenticateAdmin`, aliased as `auth.admin`. Mirrors `auth.customer`
(`AuthenticateCustomer`) exactly in structure — see `authentication-v1.md` and that class's own
docblock for the shared design — with two differences:

1. It authorizes against `ADMIN`/`SUPER_ADMIN` (at least one currently active) instead of
   `CUSTOMER`.
2. It does not require `phone_verified_at` to be set, since Admin accounts are not provisioned
   through the OTP-verified customer flow.

On every request it: verifies the JWT signature and `exp`/`nbf`; loads the `auth_sessions` row by
the token's `sid` claim and confirms it is not revoked and not expired; loads the `users` row by
`sub` and confirms `account_status = ACTIVE`; and re-queries `user_roles` joined to `roles` for at
least one currently active `ADMIN`/`SUPER_ADMIN` row. Any failure returns the same generic `401`.
On success it attaches `auth_user`, `auth_session`, and `auth_admin_roles` (the caller's active
Admin role codes, e.g. `['ADMIN']` or `['ADMIN', 'SUPER_ADMIN']`) to the request for Admin
controllers/Actions built in later phases.

---

## Not built in this phase (deliberately)

- Any Admin operational endpoint (service management, booking management, technician assignment,
  job start/complete, etc.) — Phase 9B+.
- A permission framework beyond role codes — no `permissions` table exists in the schema, so none
  was invented. `SUPER_ADMIN` is treated identically to `ADMIN` for authorization purposes in this
  phase; any capability difference between the two belongs to a later phase.
- MFA/2FA — not required by any current requirement document.
- Admin password change/reset endpoints — the existing `/v1/auth/change-password`,
  `/v1/auth/forgot-password`, etc. routes remain customer-only (gated by `auth.customer` /
  unauthenticated OTP flows respectively) and were not extended to Admin accounts in this phase.
  Admin password management is a `CAN WAIT` item for a later phase; nothing in this phase weakens
  or duplicates the existing password-security mechanisms.
- Login/logout audit trail writes to `admin_audit_logs` — that table exists in the schema for
  privileged *operational* actions (the natural fit is Phase 9B's Admin operational endpoints,
  which is exactly where the schema's `entity_type`/`entity_identifier` design is meant to be
  used); no current requirement document calls for authentication events specifically to be
  written there, so none was added speculatively.
