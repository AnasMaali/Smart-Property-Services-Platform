# Authentication Session & Token Design — Version 1

## Purpose

This document is the technical source of truth for how Version 1 issues, renews, and revokes
customer authentication after login. It resolves the generic session/token requirements in
`docs/05-system-requirements/03-security-and-privacy-requirements.md` (§6) and
`docs/03-features-and-requirements/02-authentication-and-account-management.md` (Login) into
concrete, implementable values.

This design covers **customer** authentication only. Admin authentication is out of scope for
Version 1 and will reuse the same `auth_sessions` / `auth_client_types` schema with its own
client type (`ADMIN_WEB`) and rules, defined later.

---

## 1. Client Types (Version 1)

Customer login is only issued for the following `auth_client_types`:

* `MOBILE_IOS`
* `MOBILE_ANDROID`

`ADMIN_WEB` exists in `auth_client_types` but is reserved for future Admin authentication and
shall not be accepted as a client type for customer login. There is no `CUSTOMER_WEB` client
type in Version 1 — customers authenticate only through the mobile application.

---

## 2. Login Experience

The customer logs in once with phone number and password and remains signed in across app
restarts. The mobile application renews access automatically in the background using the
refresh token. The customer is not asked to re-enter phone number and password, and is not
asked for OTP, on normal subsequent app opens — re-authentication with credentials is only
required after the underlying session has expired or been revoked (see §5).

---

## 3. Access Token

* **Type:** JSON Web Token (JWT).
* **Algorithm:** HS256.
* **TTL:** 15 minutes from issuance.
* **Persistence:** Not stored in the database in any form. It is a self-contained, short-lived
  credential validated by signature and expiry (plus the `sid` session check described in §6).
* **Signing secret:** `AUTH_JWT_SECRET`, a dedicated application secret. It shall not reuse
  Laravel's `APP_KEY`.
* **Library:** `firebase/php-jwt` is the approved library for issuing and verifying these
  tokens once implementation begins. It is not installed as part of this documentation update.

### 3.1 Claims

| Claim | Meaning                                             |
|-------|------------------------------------------------------|
| `sub` | User UUID                                             |
| `sid` | `auth_sessions` UUID for the session backing this token |
| `role`| `CUSTOMER`                                            |
| `client` | `MOBILE_IOS` or `MOBILE_ANDROID`                   |
| `iat` | Issued-at time                                        |
| `nbf` | Not-before time                                       |
| `exp` | Expiry time (`iat` + 15 minutes)                      |
| `jti` | Unique token identifier                               |

The access token shall never include email, phone number, password, password hash, or other
sensitive profile data.

---

## 4. Refresh Token

* **Type:** Opaque random token (not a JWT).
* **Generation:** `random_bytes(32)` — 256 bits of entropy.
* **Exposure:** The raw refresh token is returned to the client only at the moment it is issued
  or rotated (login, and future refresh). It is never stored or logged in raw form anywhere on
  the backend.
* **Storage:** The backend stores only `SHA-256(raw token)` as a 32-byte binary value, in
  `auth_sessions.refresh_token_hash` (matches the existing `BINARY(32)` column — no schema
  change required).

---

## 5. Session (`auth_sessions`)

`auth_sessions` is the server-side record of a customer session and is the only place session
state is authoritative (the access token itself carries no revocation state).

* **Absolute lifetime:** 30 days from the moment of login.
* `auth_sessions.expires_at` is set once, at login, to `created_at + 30 days`, and represents
  this original, fixed session expiry.
* Refreshing (see §6) does **not** extend `expires_at`. A session can only ever live for 30
  days from its original login, regardless of how often it is refreshed.
* `auth_sessions.revoked_at` being non-null immediately invalidates the session, independent of
  `expires_at`.

This lets a customer stay signed in for up to 30 days without re-entering credentials, while
guaranteeing that every session has a hard ceiling and can be revoked out-of-band (logout, admin
action) at any time.

---

## 6. Refresh Behavior (future `/refresh` endpoint — not implemented yet)

On refresh, the backend:

1. Validates the presented raw refresh token by comparing `SHA-256(raw token)` against
   `auth_sessions.refresh_token_hash`.
2. Rejects the request if the session is not found, `revoked_at` is set, or `expires_at` has
   passed.
3. Rotates the refresh token: generates a new `random_bytes(32)` token and replaces
   `auth_sessions.refresh_token_hash` with its SHA-256 hash. The previous refresh token becomes
   invalid immediately (it no longer matches any stored hash).
4. Issues a new 15-minute access token (JWT) with a fresh `iat`/`nbf`/`exp`/`jti`, keeping the
   same `sid`.
5. Does **not** change `auth_sessions.expires_at`.

This is what allows the app to keep the customer signed in silently: the access token expires
every 15 minutes, the app exchanges the (rotating) refresh token for a new one in the
background, and the 30-day absolute session ceiling from step 5 of §5 is what eventually forces
a real login again.

This endpoint is **not** part of this documentation update and is not implemented yet.

---

## 7. Protected Request Validation (future auth middleware — not implemented yet)

A protected request's access token shall be considered valid only if all of the following hold:

1. The JWT signature verifies against `AUTH_JWT_SECRET`.
2. The JWT has not expired (`exp`) and is not used before `nbf`.
3. The `sid` claim identifies a session that exists in `auth_sessions`.
4. That session's `revoked_at` is `NULL`.
5. That session's `expires_at` has not passed.
6. The user identified by `sub` has an `ACTIVE` account status.
7. The role required by the endpoint (`CUSTOMER`) is assigned to the user and is active.

The `auth_sessions` lookup (steps 3–5) is mandatory on every protected request — it is what
allows logout/revocation to immediately reject an otherwise unexpired, signature-valid JWT.
Signature and expiry checks alone are not sufficient.

This middleware is **not** part of this documentation update and is not implemented yet.

---

## 8. Logout (future — not implemented yet)

Logout sets `auth_sessions.revoked_at` for the session identified by the presented token's
`sid`. This is not implemented as part of this documentation update.

---

## 9. Security Notes

* Access tokens and refresh tokens shall never appear in URLs or query strings.
* Access tokens and refresh tokens shall never be written to logs.
* Production traffic carrying tokens requires HTTPS (see `SEC-TRN-01`).
* The mobile application stores the refresh token using secure device storage (see `SEC-SES-04`,
  `SEC-MOB-03`).
