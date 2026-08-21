# BLUE V1 — Customer Authentication API Contract

Base URL: `{{base_url}}` (local default: `http://127.0.0.1:8000/api/v1`)

This document describes the customer authentication endpoints as actually implemented in
`backend/routes/api.php`, their Form Requests, Actions, and Controllers, and verified against
`backend/tests/Feature/Auth/*`. It documents only what exists in code — no aspirational or
planned behavior is included.

## Global notes

- All responses are JSON. All endpoints share the envelope `{ "success": bool, "message": string, "data": object|null }`, except `logout` / `logout-all`, which return `{ "success": bool, "message": string }` with no `data` key.
- Validation failures return HTTP `422` with the envelope `{ "success": false, "message": "The given data was invalid.", "errors": { "<field>": ["<message>"] } }` (Laravel's standard `FormRequest` validation error shape).
- **Access token TTL**: 15 minutes (`AUTH_ACCESS_TOKEN_TTL_MINUTES`, default `15`). Configured in `backend/config/jwt.php`.
- **Session / refresh token lifetime**: 30 days from login, absolute (not extended by refresh calls) (`AUTH_SESSION_TTL_DAYS`, default `30`).
- **Refresh rotation**: every successful `POST /v1/auth/refresh` call invalidates the presented raw refresh token and issues a brand-new raw refresh token (and a new access token). The old raw refresh token cannot be reused.
- **Bearer token usage**: protected endpoints (see table below) require header `Authorization: Bearer {{access_token}}`. The access token is a signed HS256 JWT containing `sub` (user UUID), `sid` (session UUID), `role`, `client`, `iat`, `nbf`, `exp`, `jti`.
- **OTP policy** (identical for every OTP purpose — phone verification, password reset, phone number change): 6-digit numeric code, expires 5 minutes after issue, maximum 5 verification attempts before the code is locked out (`ATTEMPTS_EXCEEDED`), and a 60-second cooldown between resend requests for the same flow.
- **HTTP-layer rate limiting on top of the OTP policy above** (`App\Providers\AppServiceProvider`, an additional flooding boundary — the domain rules above remain authoritative): the public pre-auth OTP endpoints (`resend-otp`, `forgot-password` → `throttle:auth-otp-issue`; `verify-phone`, `verify-password-reset-otp` → `throttle:auth-otp-verify`) key their identity bucket off the request's own `phone_number`/`otp_uuid`. The authenticated phone-number-change endpoints (`change-phone-number`, `resend-phone-number-change-otp` → `throttle:auth-phone-change-issue`; `verify-phone-number-change-otp` → `throttle:auth-phone-change-verify`) instead key identity off the already-authenticated caller's own user id, since there is no anonymous body field to key on — same limits (5/min identity + 30/min IP for issuance, 10/min identity + 60/min IP for verification), same hashed-key convention, applied after `auth.customer` has already run. `DELETE /v1/auth/account` (`throttle:auth-account-delete`) uses the same authenticated-identity keying, at 5/min identity + 20/min IP.
- **Customer remains logged in through the refresh-token flow**: the access token expires every 15 minutes, but as long as the underlying session (refresh token) is valid (not revoked, not expired, ≤ 30 days old) the client can call `POST /v1/auth/refresh` repeatedly to obtain new access/refresh token pairs without requiring the customer to log in again.
- Nothing in this document exposes real OTP codes, password hashes, OTP hashes, refresh token hashes, or raw binary UUIDs. All example values are placeholders.
- **OTP delivery** (`OTP_DELIVERY_DRIVER`, `.env`): OTP codes are always generated, hashed, and stored server-side, and are never returned by any endpoint under any circumstance, in any environment — the driver below only controls what (if anything) happens to the raw code *after* it is hashed and *before* it is discarded. `App\Providers\OtpDeliveryServiceProvider` is the single place every driver's guard is enforced.
  - `"null"` (default): discards the raw code, delivers nothing. Refused (`OtpDeliveryServiceProvider` throws) under `APP_ENV=production` — a production deployment must configure real delivery rather than silently accept OTP requests it can never fulfill.
  - `"log"`: local development only. Additionally writes one log line of the form `[LOCAL OTP] purpose=... phone=...(last 4 digits only) code=... expires_at=...` to the application log for every OTP-issuing endpoint (Registration, Resend Phone OTP, Forgot Password, Request/Resend Phone Number Change OTP), so a developer with no SMS provider configured can still complete these flows through the real API — the API response and DB contents are unchanged either way. Refused outside `APP_ENV=local` (throws rather than silently falling back to safe behavior). **Never set `OTP_DELIVERY_DRIVER=log` outside local development.**
  - `"twilio"`: the approved BLUE V1 production driver (`App\Support\Otp\TwilioOtpDeliveryChannel`). Delivers the OTP by SMS through Twilio's Messages API using server-side-only credentials (`TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, and exactly one of `TWILIO_MESSAGING_SERVICE_SID` / `TWILIO_FROM_NUMBER` — see `.env.example` and `config/services.php`). `OtpDeliveryServiceProvider` validates every required credential is present before ever constructing the channel, failing closed at resolution time if not. A Twilio delivery failure never marks an OTP verified or otherwise touches `otp_verifications` — it surfaces as a generic server error to the caller (see `App\Support\Otp\OtpDeliveryException`), never a raw Twilio response body or credential. The automated test suite never calls Twilio (`Http::fake()` only).

---

## Endpoint summary

| # | Feature | Method | Route | Auth required |
|---|---|---|---|---|
| 1 | Registration | POST | `/v1/auth/register` | No |
| 2 | Verify Phone OTP | POST | `/v1/auth/verify-phone` | No |
| 3 | Resend Phone OTP | POST | `/v1/auth/resend-otp` | No |
| 4 | Login | POST | `/v1/auth/login` | No |
| 5 | Refresh Access Token | POST | `/v1/auth/refresh` | No (refresh token in body) |
| 6 | Logout | POST | `/v1/auth/logout` | Yes (Bearer) |
| 7 | Logout All Sessions | POST | `/v1/auth/logout-all` | Yes (Bearer) |
| 8 | Forgot Password | POST | `/v1/auth/forgot-password` | No |
| 9 | Verify Password Reset OTP | POST | `/v1/auth/verify-password-reset-otp` | No |
| 10 | Reset Password | POST | `/v1/auth/reset-password` | No |
| 11 | Change Password | POST | `/v1/auth/change-password` | Yes (Bearer) |
| 12 | Request Phone Number Change | POST | `/v1/auth/change-phone-number` | Yes (Bearer) |
| 13 | Verify Phone Number Change OTP | POST | `/v1/auth/verify-phone-number-change-otp` | Yes (Bearer) |
| 14 | Resend Phone Number Change OTP | POST | `/v1/auth/resend-phone-number-change-otp` | Yes (Bearer) |
| 15 | Delete Account | DELETE | `/v1/auth/account` | Yes (Bearer) |
| 16 | Get Account Deletion Status | GET | `/v1/auth/account-deletion` | Yes (Bearer) |

(The "Change Phone Number" feature from the task brief maps to 3 routes: request, verify, resend — routes 12–14 above.)

---

## 1. Registration

- **HTTP method / route**: `POST /v1/auth/register`
- **Auth required**: No
- **Headers**: `Content-Type: application/json`
- **Request JSON**:
```json
{
  "full_name": "Layla Hassan",
  "phone_number": "+971500001234",
  "email": "layla@example.com",
  "password": "Passw0rd!",
  "city_id": 1,
  "area_id": 1,
  "property_relationship_type_id": 1,
  "service_interests": [1, 2]
}
```
- **Fields**:
  | Field | Required | Rules |
  |---|---|---|
  | `full_name` | Yes | string, min:2, max:150 |
  | `phone_number` | Yes | string, `^\+?[0-9]{8,20}$`, unique in `users.phone_number` |
  | `email` | Yes | string, RFC email, max:254, unique in `users.email` |
  | `password` | Yes | see [Password policy](#password-policy) |
  | `city_id` | Yes | integer, must exist in `cities` with `is_active = 1` |
  | `area_id` | Yes | integer, must exist in `areas` with `is_active = 1` and `city_id` matching the submitted `city_id` |
  | `property_relationship_type_id` | Yes | integer, must exist in `property_relationship_types` with `is_active = 1` |
  | `service_interests` | Yes | array, min 1 item |
  | `service_interests.*` | Yes | integer, distinct, must exist in `service_categories` with `is_active = 1` |

  `email` is lower-cased and trimmed before validation; `full_name`/`phone_number` are trimmed.

- **Success status**: `201 Created`
- **Success response**:
```json
{
  "success": true,
  "message": "Registration successful. Please verify your phone number using the OTP sent to it.",
  "data": {
    "user_uuid": "3f2a1c9e-....-....-....-............",
    "full_name": "Layla Hassan",
    "phone_number": "+971500001234",
    "email": "layla@example.com",
    "account_status": "PENDING_VERIFICATION",
    "phone_verified": false,
    "otp_verification_uuid": "5b0e7a11-....-....-....-............",
    "otp_expires_at": "2026-08-08T12:05:00+00:00"
  }
}
```
- **Error status**: `422 Unprocessable Entity` (validation failure — e.g. duplicate `email`/`phone_number`, invalid `area_id` for the given `city_id`, inactive reference IDs)
- **Example error JSON**:
```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email has already been taken."]
  }
}
```
- **Business behavior**: Creates `users`, `user_profiles`, `customer_profiles`, `customer_service_interests` rows, assigns the `CUSTOMER` role, and issues a `PHONE_VERIFICATION` OTP — all in a single DB transaction. Account status starts as `PENDING_VERIFICATION`; the customer cannot log in until the phone number is verified.
- **Security notes**: The raw OTP code is never persisted or returned — only its bcrypt hash is stored (`code_hash`). It exists only in memory for the duration of the request.
- **Postman variables set**: `phone_number`, `email`, `password` (from request body, entered by user); test script captures `otp_verification_uuid` from the response into environment variable `otp_verification_uuid`.

---

## 2. Verify Phone OTP

- **HTTP method / route**: `POST /v1/auth/verify-phone`
- **Auth required**: No
- **Headers**: `Content-Type: application/json`
- **Request JSON**:
```json
{
  "otp_verification_uuid": "{{otp_verification_uuid}}",
  "otp_code": "{{otp_code}}"
}
```
- **Fields**:
  | Field | Required | Rules |
  |---|---|---|
  | `otp_verification_uuid` | Yes | string, valid UUID |
  | `otp_code` | Yes | string, exactly 6 digits |

- **Success status**: `200 OK`
- **Success response**:
```json
{
  "success": true,
  "message": "Phone number verified successfully.",
  "data": {
    "user_uuid": "3f2a1c9e-....-....-....-............",
    "phone_number": "+971500001234",
    "account_status": "ACTIVE",
    "phone_verified": true,
    "phone_verified_at": "2026-08-08T12:01:30+00:00"
  }
}
```
- **Error status**: `422 Unprocessable Entity` for both business failures (invalid/expired/wrong code) and validation failures.
- **Example error JSON**:
```json
{
  "success": false,
  "message": "The verification code you entered is incorrect.",
  "data": null
}
```
  Other possible generic/business messages returned with the same 422 status and `data: null`:
  - `"This verification request is invalid or no longer active."` (unknown/mismatched UUID, account already active, OTP not in `PENDING` status)
  - `"This verification code has expired. Please request a new one."`
  - `"Maximum verification attempts exceeded. Please request a new verification code."`
- **Business behavior**: On success, marks the OTP `VERIFIED`, sets `users.phone_verified_at`, and transitions the account from `PENDING_VERIFICATION` to `ACTIVE`. On a wrong code, increments `failed_attempt_count`; the 5th wrong attempt marks the OTP `ATTEMPTS_EXCEEDED`.
- **Security notes**: All failure branches — unknown UUID, wrong purpose, wrong code, expired, attempts exceeded — are handled explicitly, but distinct messages ARE returned per case (this endpoint is not enumeration-safe by design, since the UUID itself already scopes the flow to one registration attempt).
- **Postman variables used**: `otp_verification_uuid`, `otp_code`.

---

## 3. Resend Phone OTP

- **HTTP method / route**: `POST /v1/auth/resend-otp`
- **Auth required**: No
- **Headers**: `Content-Type: application/json`
- **Request JSON**:
```json
{
  "otp_verification_uuid": "{{otp_verification_uuid}}"
}
```
- **Fields**:
  | Field | Required | Rules |
  |---|---|---|
  | `otp_verification_uuid` | Yes | string, valid UUID |

- **Success status**: `200 OK`
- **Success response**:
```json
{
  "success": true,
  "message": "A new verification code has been sent.",
  "data": {
    "otp_verification_uuid": "9c3d2e88-....-....-....-............",
    "otp_expires_at": "2026-08-08T12:10:00+00:00",
    "resend_available_at": "2026-08-08T12:06:00+00:00",
    "phone_number": "+971500001234"
  }
}
```
- **Error status**: `422 Unprocessable Entity`
- **Example error JSON**:
```json
{
  "success": false,
  "message": "Please wait before requesting another verification code.",
  "data": null
}
```
  Other possible messages: `"This account no longer requires phone verification."`, `"This verification request is invalid or no longer active."`
- **Business behavior**: Invalidates the previous `PENDING` OTP for this registration flow (if any) and issues a new one with a fresh 5-minute expiry, subject to a 60-second cooldown measured from the previous OTP's `created_at`. The returned `otp_verification_uuid` **replaces** the old one — subsequent `verify-phone` / `resend-otp` calls must use the new UUID.
- **Postman test script**: overwrites environment variable `otp_verification_uuid` with the new value from the response.

---

## 4. Login

- **HTTP method / route**: `POST /v1/auth/login`
- **Auth required**: No
- **Headers**: `Content-Type: application/json`
- **Request JSON**:
```json
{
  "phone_number": "{{phone_number}}",
  "password": "{{password}}",
  "client_type": "{{client_type}}",
  "device_name": "Layla's iPhone",
  "app_version": "1.2.0"
}
```
- **Fields**:
  | Field | Required | Rules |
  |---|---|---|
  | `phone_number` | Yes | string, `^\+?[0-9]{8,20}$` |
  | `password` | Yes | string |
  | `client_type` | Yes | string, one of `MOBILE_IOS`, `MOBILE_ANDROID`, must also exist and be active in `auth_client_types` |
  | `device_name` | No | string, max:120 |
  | `app_version` | No | string, max:30 |

  `client_type` is upper-cased before validation.

- **Success status**: `200 OK`
- **Success response**:
```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "user_uuid": "3f2a1c9e-....-....-....-............",
    "full_name": "Layla Hassan",
    "phone_number": "+971500001234",
    "email": "layla@example.com",
    "role": "CUSTOMER",
    "session_uuid": "7e1f4b02-....-....-....-............",
    "access_token": "<jwt>",
    "access_token_expires_at": "2026-08-08T12:15:00+00:00",
    "refresh_token": "<64-hex-char raw token>",
    "session_expires_at": "2026-09-07T12:00:00+00:00"
  }
}
```
- **Error status**: `422 Unprocessable Entity` for business failure; `422` also for validation errors (e.g. invalid `client_type`).
- **Example error JSON**:
```json
{
  "success": false,
  "message": "The phone number or password you entered is incorrect.",
  "data": null
}
```
  This exact same message/status is returned for: unknown phone number, wrong password, non-`ACTIVE` account status (`PENDING_VERIFICATION`/`SUSPENDED`/`DEACTIVATED`), unverified phone number, or a missing/inactive `CUSTOMER` role — deliberately non-enumerating.
- **Business behavior**: Creates a new `auth_sessions` row (30-day expiry from now), stores only `SHA-256(raw refresh token)`, updates `users.last_login_at`, and issues an access token embedding `sub`, `sid`, `role=CUSTOMER`, `client`.
- **Security notes**: The raw refresh token is returned exactly once, in this response; only its hash is persisted. IP address is stored packed (`inet_pton`); response never includes `password`, `password_hash`, or `refresh_token_hash`.
- **Postman test script**: on `200` + `success == true`, saves `access_token`, `refresh_token`, `session_uuid` into the environment.

---

## 5. Refresh Access Token

- **HTTP method / route**: `POST /v1/auth/refresh`
- **Auth required**: No (authorization is via the `refresh_token` body field, not a Bearer header)
- **Headers**: `Content-Type: application/json`
- **Request JSON**:
```json
{
  "refresh_token": "{{refresh_token}}"
}
```
- **Fields**:
  | Field | Required | Rules |
  |---|---|---|
  | `refresh_token` | Yes | string, exactly 64 hex characters (`^[0-9a-fA-F]{64}$`) |

- **Success status**: `200 OK`
- **Success response**:
```json
{
  "success": true,
  "message": "Access token refreshed successfully.",
  "data": {
    "access_token": "<new jwt>",
    "access_token_expires_at": "2026-08-08T12:30:00+00:00",
    "refresh_token": "<new 64-hex-char raw token>",
    "session_uuid": "7e1f4b02-....-....-....-............",
    "session_expires_at": "2026-09-07T12:00:00+00:00"
  }
}
```
- **Error status**: `422 Unprocessable Entity`
- **Example error JSON**:
```json
{
  "success": false,
  "message": "This refresh token is invalid or has expired.",
  "data": null
}
```
  Same message/status for: unknown token, revoked session, expired session, non-`ACTIVE` user, unverified phone, missing/inactive `CUSTOMER` role, or inactive/non-mobile client type.
- **Business behavior**: Looks up the `auth_sessions` row by `SHA-256(raw token)` under a row lock, rotates the stored hash to a newly generated raw refresh token, updates `last_used_at`, and issues a new access token. `session_expires_at` is unchanged (absolute 30-day lifetime from original login is not extended). The old raw refresh token becomes unusable immediately — a concurrent request presenting the same old token loses the race and fails.
- **Postman test script**: on `200` + `success == true`, **replaces** environment `access_token` and `refresh_token` with the new rotated values.

---

## 6. Logout

- **HTTP method / route**: `POST /v1/auth/logout`
- **Auth required**: Yes — `Authorization: Bearer {{access_token}}`
- **Headers**: `Authorization: Bearer {{access_token}}`
- **Request JSON**: none (empty body)
- **Success status**: `200 OK`
- **Success response**:
```json
{
  "success": true,
  "message": "Logged out successfully."
}
```
  Note: no `data` key on this endpoint.
- **Error status**: `401 Unauthorized`
- **Example error JSON**:
```json
{
  "success": false,
  "message": "This session is invalid or has expired."
}
```
  Same message/status for: missing/malformed token, invalid signature, expired access token, unknown/mismatched session, already-revoked session, expired session, or non-`ACTIVE` user.
- **Business behavior**: Decodes the access token to find `sid`/`sub`, then revokes (`revoked_at = now()`) exactly that one `auth_sessions` row. Other sessions for the same user are untouched.
- **Postman variables used**: `access_token` (Bearer auth).

---

## 7. Logout All Sessions

- **HTTP method / route**: `POST /v1/auth/logout-all`
- **Auth required**: Yes — `Authorization: Bearer {{access_token}}`
- **Headers**: `Authorization: Bearer {{access_token}}`
- **Request JSON**: none (empty body)
- **Success status**: `200 OK`
- **Success response**:
```json
{
  "success": true,
  "message": "Logged out from all sessions successfully."
}
```
- **Error status**: `401 Unauthorized`, same generic message/shape as Logout.
- **Business behavior**: Revokes **every** non-revoked `auth_sessions` row belonging to the authenticated user — including the current session and sessions from other client types (`MOBILE_IOS`/`MOBILE_ANDROID` alike). The customer must log in again on every device afterward.
- **Postman variables used**: `access_token` (Bearer auth).

---

## 8. Forgot Password

- **HTTP method / route**: `POST /v1/auth/forgot-password`
- **Auth required**: No
- **Headers**: `Content-Type: application/json`
- **Request JSON**:
```json
{
  "phone_number": "{{phone_number}}"
}
```
- **Fields**:
  | Field | Required | Rules |
  |---|---|---|
  | `phone_number` | Yes | string, `^\+?[0-9]{8,20}$` |

- **Success status**: `200 OK` — **always**, regardless of whether the phone number belongs to an account.
- **Success response** (identical for every case: unknown phone number, `DEACTIVATED` account, active resend cooldown, or an OTP actually issued):
```json
{
  "success": true,
  "message": "If an account exists for this phone number, a password reset code has been sent.",
  "data": null
}
```
- **Non-enumeration behavior**: This is the key security property of this endpoint. It never reveals whether the phone number is registered. Every rejection path (unknown number, deactivated account, cooldown still active) performs a dummy `bcrypt` hash operation of equal cost to the real OTP-issuing path, specifically so that response **timing** cannot be used to distinguish "account exists" from "account does not exist" either. There is no error status for this endpoint under normal validation-passing input — only a `422` for malformed `phone_number` (missing/wrong format).
- **Example validation error JSON** (malformed `phone_number` only):
```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": {
    "phone_number": ["The phone number field is required."]
  }
}
```
- **Business behavior**: If the account exists and is not `DEACTIVATED`, and no `PENDING` `PASSWORD_RESET` OTP is within its 60-second cooldown, invalidates any previous pending OTP and issues a new `PASSWORD_RESET` OTP (6 digits, 5-minute expiry, 5 max attempts). **No OTP UUID or code is ever returned by this endpoint** — the next step (`verify-password-reset-otp`) is looked up by `phone_number`, not by UUID.
- **Postman variables used**: `phone_number`. No variables are captured from the response (none exist to capture).

---

## 9. Verify Password Reset OTP

- **HTTP method / route**: `POST /v1/auth/verify-password-reset-otp`
- **Auth required**: No
- **Headers**: `Content-Type: application/json`
- **Request JSON** (exact implemented contract — `phone_number` + `otp_code`, **not** an OTP UUID, because `forgot-password` never returns one):
```json
{
  "phone_number": "{{phone_number}}",
  "otp_code": "{{otp_code}}"
}
```
- **Fields**:
  | Field | Required | Rules |
  |---|---|---|
  | `phone_number` | Yes | string, `^\+?[0-9]{8,20}$` |
  | `otp_code` | Yes | string, exactly 6 digits |

- **Success status**: `200 OK`
- **Success response**:
```json
{
  "success": true,
  "message": "Password reset code verified successfully.",
  "data": {
    "reset_token": "{{reset_token}}",
    "reset_token_expires_at": "2026-08-08T12:20:00+00:00"
  }
}
```
- **Error status**: `422 Unprocessable Entity`
- **Example error JSON**:
```json
{
  "success": false,
  "message": "Invalid or expired verification code.",
  "data": null
}
```
  This is the **only** business-failure message this endpoint ever returns — unlike Verify Phone OTP (§2), every rejection branch (unknown phone number, deactivated account, no pending `PASSWORD_RESET` OTP, expired OTP, attempts-exceeded OTP, or simply a wrong code for a real pending OTP) collapses to this same message, same `422` status, and same `{success: false, data: null}` shape.
- **Business behavior**: Looks up the user's **latest** `PASSWORD_RESET` OTP by `phone_number` (since no UUID is available), validates it exactly like phone verification, and — only on success — creates a `password_reset_sessions` row. `reset_token` is a raw 64-hex-character token; only its SHA-256 hash is persisted. This token authorizes exactly one subsequent `reset-password` call and expires in 15 minutes. Internally, OTP state (status, `failed_attempt_count`, `last_attempt_at`) still transitions exactly as it does for Verify Phone OTP — only the externally-visible message is unified.
- **Non-enumeration behavior**: Because `forgot-password` (§8) never reveals whether a phone number is registered, this endpoint must not undo that guarantee either. A caller cannot distinguish "this phone number has no account" from "this is a real, active account whose password-reset code was wrong/expired/exhausted" through status code, message, or response shape.
- **Security notes**: `reset_token` is returned exactly once, in this response, and must never be logged.
- **Postman test script**: on `200` + `success == true`, saves `reset_token` into the environment.

---

## 10. Reset Password

- **HTTP method / route**: `POST /v1/auth/reset-password`
- **Auth required**: No (authorization is via `reset_token`, not a Bearer header)
- **Headers**: `Content-Type: application/json`
- **Request JSON**:
```json
{
  "reset_token": "{{reset_token}}",
  "password": "{{new_password}}",
  "password_confirmation": "{{new_password}}"
}
```
- **Fields**:
  | Field | Required | Rules |
  |---|---|---|
  | `reset_token` | Yes | string, exactly 64 hex characters |
  | `password` | Yes | see [Password policy](#password-policy); must also satisfy Laravel's `confirmed` rule (`password_confirmation` must match) |

- **Success status**: `200 OK`
- **Success response**:
```json
{
  "success": true,
  "message": "Your password has been reset successfully. Please log in again.",
  "data": null
}
```
- **Error status**: `422 Unprocessable Entity`
- **Example error JSON**:
```json
{
  "success": false,
  "message": "This password reset link is invalid or has expired.",
  "data": null
}
```
  Returned uniformly for: unknown/malformed token, deactivated account, OTP not `VERIFIED`, or a `password_reset_sessions` row that is already used, revoked, or expired.
- **Business behavior**: Sets a new `password_hash`, marks the `password_reset_sessions` row `used_at`, and **revokes every existing `auth_sessions` row for the user** — the customer must log in again on every device with the new password.
- **Postman variables used**: `reset_token`, `new_password`.

---

## 11. Change Password

- **HTTP method / route**: `POST /v1/auth/change-password`
- **Auth required**: Yes — `Authorization: Bearer {{access_token}}`
- **Headers**: `Authorization: Bearer {{access_token}}`, `Content-Type: application/json`
- **Request JSON**:
```json
{
  "current_password": "{{password}}",
  "new_password": "{{new_password}}",
  "new_password_confirmation": "{{new_password}}"
}
```
- **Fields**:
  | Field | Required | Rules |
  |---|---|---|
  | `current_password` | Yes | string |
  | `new_password` | Yes | see [Password policy](#password-policy); `confirmed` (`new_password_confirmation` must match) |

- **Success status**: `200 OK`
- **Success response**:
```json
{
  "success": true,
  "message": "Password changed successfully.",
  "data": null
}
```
- **Error status**: `422 Unprocessable Entity`; `401 Unauthorized` if the Bearer token itself is invalid (handled by the `auth.customer` middleware before reaching this endpoint, same generic session message as Logout).
- **Example error JSON**:
```json
{
  "success": false,
  "message": "The current password you entered is incorrect.",
  "data": null
}
```
  Other messages: `"The new password must be different from your current password."`, and the generic `"Your session is no longer valid. Please log in again."` if the authenticated user row itself is no longer `ACTIVE`.
- **Business behavior**: Updates `password_hash`, then revokes every **other** `auth_sessions` row for this user (the session used to make this call stays valid).
- **Postman variables used**: `access_token` (Bearer auth), `password`, `new_password`.

---

## 12. Change Phone Number (complete flow)

All three endpoints below require `Authorization: Bearer {{access_token}}` (middleware group `auth.customer` in `routes/api.php`).

### 12a. Request Phone Number Change

- **HTTP method / route**: `POST /v1/auth/change-phone-number`
- **Request JSON**:
```json
{
  "new_phone_number": "{{new_phone_number}}"
}
```
- **Fields**:
  | Field | Required | Rules |
  |---|---|---|
  | `new_phone_number` | Yes | string, `^\+?[0-9]{8,20}$`, unique in `users.phone_number` |

- **Success status**: `200 OK`
- **Success response**:
```json
{
  "success": true,
  "message": "A verification code has been sent to the new phone number.",
  "data": {
    "otp_verification_uuid": "a1b2c3d4-....-....-....-............",
    "new_phone_number": "+971500009999",
    "otp_expires_at": "2026-08-08T12:10:00+00:00",
    "resend_available_at": "2026-08-08T12:06:00+00:00"
  }
}
```
- **Error status**: `422 Unprocessable Entity` for business failures; `401 Unauthorized` for an invalid/expired Bearer token.
- **Example error JSON**:
```json
{
  "success": false,
  "message": "This phone number is already associated with another account.",
  "data": null
}
```
  Other messages: `"The new phone number must be different from your current phone number."`, `"Please wait before requesting another phone number change code."`, and the generic `"Your session is no longer valid. Please log in again."` for a non-`ACTIVE` account.
- **Business behavior**: Issues a `PHONE_NUMBER_CHANGE` OTP targeting `new_phone_number`. `users.phone_number` is **not** changed yet — only `verify-phone-number-change-otp` performs the actual update.
- **Postman test script**: on `200` + `success == true`, saves `otp_verification_uuid` (documented here as `phone_change_otp_uuid` in the environment to avoid colliding with the registration/login OTP flow) and `new_phone_number`.

### 12b. Verify Phone Number Change OTP

- **HTTP method / route**: `POST /v1/auth/verify-phone-number-change-otp`
- **Request JSON**:
```json
{
  "otp_verification_uuid": "{{phone_change_otp_uuid}}",
  "otp_code": "{{otp_code}}"
}
```
- **Fields**:
  | Field | Required | Rules |
  |---|---|---|
  | `otp_verification_uuid` | Yes | string, valid UUID |
  | `otp_code` | Yes | string, exactly 6 digits |

- **Success status**: `200 OK`
- **Success response**:
```json
{
  "success": true,
  "message": "Phone number changed successfully.",
  "data": {
    "phone_number": "+971500009999",
    "phone_verified": true,
    "phone_verified_at": "2026-08-08T12:07:15+00:00"
  }
}
```
- **Error status**: `422 Unprocessable Entity`; `401 Unauthorized` for an invalid Bearer token.
- **Example error JSON**:
```json
{
  "success": false,
  "message": "This phone number is no longer available.",
  "data": null
}
```
  Other messages: `"This verification request is invalid or no longer active."`, `"This verification code has expired. Please request a new one."`, `"Maximum verification attempts exceeded. Please request a new verification code."`, `"The verification code you entered is incorrect."`
- **Business behavior**: On success, updates `users.phone_number` to the verified new number and re-stamps `phone_verified_at`. Re-checks uniqueness defensively at verification time (in case the number was claimed by someone else after the OTP was issued) — the OTP is left `PENDING` in that case so the customer can request a fresh code for a different number. Every **other** session for this user is revoked; the session making this call stays valid.
- **Postman variables used**: `access_token` (Bearer auth), `phone_change_otp_uuid`, `otp_code`.

### 12c. Resend Phone Number Change OTP

- **HTTP method / route**: `POST /v1/auth/resend-phone-number-change-otp`
- **Request JSON**:
```json
{
  "otp_verification_uuid": "{{phone_change_otp_uuid}}"
}
```
- **Fields**:
  | Field | Required | Rules |
  |---|---|---|
  | `otp_verification_uuid` | Yes | string, valid UUID |

- **Success status**: `200 OK`
- **Success response**:
```json
{
  "success": true,
  "message": "A new verification code has been sent.",
  "data": {
    "otp_verification_uuid": "b7e8f901-....-....-....-............",
    "new_phone_number": "+971500009999",
    "otp_expires_at": "2026-08-08T12:15:00+00:00",
    "resend_available_at": "2026-08-08T12:11:00+00:00"
  }
}
```
- **Error status**: `422 Unprocessable Entity`; `401 Unauthorized` for an invalid Bearer token.
- **Example error JSON**:
```json
{
  "success": false,
  "message": "Please wait before requesting another verification code.",
  "data": null
}
```
  Other message: `"This verification request is invalid or no longer active."`
- **Business behavior**: Same invalidate-and-reissue pattern as `resend-otp`, scoped to the `PHONE_NUMBER_CHANGE` purpose, with the same 60-second cooldown.
- **Postman test script**: overwrites `phone_change_otp_uuid` with the new value from the response.

---

## 13. Delete Account

- **HTTP method / route**: `DELETE /v1/auth/account`
- **Auth required**: Yes — `Authorization: Bearer {{access_token}}` (middleware group `auth.customer`). The account acted on is always the authenticated caller (`$request->attributes->get('auth_user')`) — there is no request field for a target user id, and none would be honored if sent.
- **Rate limit**: `throttle:auth-account-delete` — 5/min per authenticated identity, 20/min per IP.
- **Request JSON**:
```json
{
  "current_password": "{{password}}"
}
```
- **Fields**:
  | Field | Required | Rules |
  |---|---|---|
  | `current_password` | Yes | string — re-authentication proof; no other field is read by the underlying Action even if present in the request body |

This endpoint has two possible successful outcomes, decided automatically by whether the customer currently has an active Booking/Contract/Payment obligation — the client never chooses between them:

- **Immediate deletion (`200`)** — no blocking obligation exists: the full erasure lifecycle below runs immediately, in the same request.
- **Deferred deletion (`202`)** — a blocking obligation exists: the customer's request is durably recorded as `PENDING` (`customer_account_deletion_requests`, BLUE V1 Phase 13) and completes automatically, with no further client action required, once the obligation reaches a terminal state (a scheduled command checks every five minutes — see below). This exists specifically to satisfy Apple guideline 5.1.1(v)'s requirement that a customer be able to *initiate* deletion even when completion must wait, rather than being refused outright with no durable record of having asked.

- **Success status**: `200 OK` (immediate) or `202 Accepted` (deferred)
- **Immediate success response**:
```json
{
  "success": true,
  "message": "Account deleted successfully.",
  "data": null
}
```
- **Deferred success response**:
```json
{
  "success": true,
  "message": "Account deletion has been requested and will complete automatically after your active bookings, contracts, or payments are resolved.",
  "data": {
    "deletion_status": "PENDING",
    "requested_at": "2026-08-21T16:38:18+00:00"
  }
}
```
  `requested_at` never changes on retry — calling `DELETE /v1/auth/account` again while already `PENDING` (still requiring the correct `current_password` each time) returns the same `requested_at`, or completes deletion immediately (`200`) if the obligation has since cleared. No blocking-record identifier (Booking/Contract/Payment UUID, internal id, or Stripe reference) is ever included in either response — the customer is told only that deletion was accepted, never which obligation it is waiting on.
- **Error status**: `422 Unprocessable Entity` (wrong password, or the caller's own account is no longer `ACTIVE`); `409 Conflict` (the account also holds an active ADMIN/SUPER_ADMIN role — see below); `401 Unauthorized` for an invalid/expired Bearer token (handled by `auth.customer` before reaching this endpoint).
- **Example error JSON**:
```json
{
  "success": false,
  "message": "This account cannot be deleted through self-service while it retains administrative access. Please contact support.",
  "data": null
}
```
  Other messages: `"The current password you entered is incorrect."`, and the generic `"Your session is no longer valid. Please log in again."` if the authenticated user row itself is no longer `ACTIVE`.

- **Deferral conditions (`202`, not a refusal)** — any one of the following defers rather than blocks deletion:
  - A `bookings` row (via any of the customer's carts) whose `status_id` is not `COMPLETED` or `CANCELLED`.
  - A `payment_attempts` row (via any of the customer's carts) that is still open (`finalized_at IS NULL`), still `requires_reconciliation`, or is `SUCCESSFUL` with no `bookings` row converted from it yet (the same state `bookings:convert-successful-payments` exists to recover).
  - A `service_contracts` row whose `status_id` is not `EXPIRED` or `CANCELLED` (this covers `REQUESTED`, `PENDING_ADMIN_APPROVAL`, `PENDING_CUSTOMER_ACCEPTANCE`, and `ACTIVE` — including a contract still being billed through Stripe, since a contract stays `ACTIVE` through `CANCEL_AT_PERIOD_END` until the provider confirms cancellation).

  No Stripe call is ever made as part of this endpoint or its scheduled processor — a contract still tied to live Stripe billing is simply left alone (deferred), never cancelled as a side effect of account deletion.

- **Sessions while `PENDING`**: unaffected. The customer's current session (and any other) remains fully valid — deliberately, so they can use the app to resolve the obligation (view/cancel a Booking, wait out a Contract term, etc.). Only actual completion (`200`, whether immediate or via the scheduled processor) revokes sessions.

- **New obligations while `PENDING`**: blocked. `App\Support\Auth\PendingAccountDeletionGuard` rejects (`409`, generic message, no internal details) any attempt to create a **new** Payment, Contract request, or CONTRACT-entitlement Booking while a deletion request is outstanding — a customer cannot indefinitely postpone their own deletion by continuously creating fresh obligations. Resolving an **existing** obligation is never blocked: reading Bookings/Contracts/profile, cancelling an existing Booking, and ordinary cart preparation (adding items, no Payment yet) all remain fully usable.

- **Automatic completion**: `accounts:process-pending-deletions`, scheduled every five minutes (`withoutOverlapping`), re-evaluates every `PENDING` request using the exact same eligibility rules above and completes deletion — via the same erasure logic described below, never a separate implementation — the moment the obligation clears. No client action is required; `GET /v1/auth/account-deletion` (below) lets the client observe this happening.

- **Dual-role Admin identity (`409`, unaffected by this phase)**: an account currently holding an active `ADMIN`/`SUPER_ADMIN` role is refused outright — never deferred, never queued. A `users` row can legitimately be both a Customer and a company Admin at once, and the erasure lifecycle's tombstoning is not scoped to "just the Customer half" of a shared identity; queuing such an account for automatic completion could later tombstone an active company Admin, which must never happen.

- **Business behavior (only once every eligibility check above passes, all inside one DB transaction — immediate path)**:
  - **This is genuine erasure, not the existing account-deactivation status flip reused verbatim.** `account_status_id` does move to `DEACTIVATED` (the same status value `AuthenticateCustomer`/`LoginAction` already require to be `ACTIVE`, so it alone is sufficient to block every future login/authenticated request), but a `DEACTIVATED` account and a *deleted* account are not otherwise the same thing in this schema: deletion additionally sets a dedicated `users.deleted_at` timestamp, and — unlike any other current use of `DEACTIVATED` — permanently destroys the account's live PII and access below. A future "temporarily deactivate my account" feature landing on the same status value would *not* imply any of this.
  - `users.phone_number` and `users.email` are overwritten with non-personal, randomly generated, collision-free placeholder values (`DEL` + random hex for phone; `deleted+<random-hex>@deleted.invalid` for email — `.invalid` is the RFC 2606-reserved TLD for exactly this "never resolves" use). **The original phone number and email become immediately available for a new registration.**
  - `users.password_hash` is overwritten with a hash of random data — the original password can never again authenticate.
  - `user_profiles.full_name` is overwritten to `"Deleted User"`; profile location/relationship reference-data ids are left as non-personal foreign keys.
  - The `CUSTOMER` row in `user_roles` for this user is deleted — even if the `users` row itself must remain (see below), the account no longer carries customer authorization.
  - `customer_profiles.stripe_customer_id` is cleared.
  - `customer_service_interests` rows for this customer are deleted.
  - Every `auth_sessions` row for this user is revoked (including the one used to make this call) — old access and refresh tokens stop working immediately.
  - Every still-`PENDING` `otp_verifications` row for this user (any purpose) is marked `INVALIDATED` — no in-flight OTP (phone verification, password reset, phone number change) can be used afterwards, and `forgot-password` issues no new OTP for a tombstoned phone number.
  - Each of the customer's `carts`: if it has no `bookings` and no `payment_attempts` row (pure transient shopping/appointment-hold state), the cart is deleted outright (its items, option selections, `cart_locations`, and any `appointment_holds` all cascade). If it does carry financial history, the cart and its items are left in place as historical order content, and only `cart_locations` (the cart's own contact/address PII) is stripped.
  - Each of the customer's `customer_properties`: if never referenced by any `service_contracts` row (including a historical cancelled/expired one), it is deleted outright. If it is referenced, it cannot be deleted (a `RESTRICT` foreign key) and is instead anonymized in place (`is_active = 0`, label/address/contact fields overwritten, non-personal reference ids like `area_id`/`property_type_id` left intact) — never exposed back through any customer-facing property listing afterward, since the customer's own session and role no longer exist.
  - **Deliberately never touched**: `payment_attempts` rows that are `SUCCESSFUL` and already converted, `bookings` in a terminal state (`COMPLETED`/`CANCELLED`) and their `booking_status_history`, `service_contracts` in a terminal state (`EXPIRED`/`CANCELLED`) and their `service_contract_billings` (including Stripe subscription/customer/invoice references needed for reconciliation), and every immutable `checkout_snapshot`/`checkout_snapshot_hash`. Most of this is genuinely non-personal financial/audit data. **`checkout_snapshot` is a partial exception**: it embeds the exact service address and `visit_contact_phone` captured at the moment of payment — real personal data, not just financial metadata. `checkout_snapshot_hash` is not merely a decorative integrity stamp: `ProcessPaymentWebhookAction` and `CreateBookingFromSuccessfulPaymentAction` both actively recompute and verify it against the live snapshot on every webhook delivery, including provider retries — a currently-exercised code path. Redacting the snapshot's personal fields post-deletion, even while recomputing a new hash, would silently change what that hash validates against for any future retried webhook on that payment. Retention today is driven primarily by this technical constraint, not by a BLUE-documented legal retention requirement for `visit_contact_phone` specifically — no such policy exists in this codebase. This endpoint is **designed to support** Apple's account-deletion requirement for every field it can safely erase; this specific residual gap is disclosed here rather than assumed compliant, and should be closed by a future redesign that separates the hashed financial core from a redactable fulfillment-PII subset. It is never exposed through any customer-facing read path after deletion, since the deleted account has no working credentials, role, or session left to query it with.
- **Atomicity**: the entire operation (both the immediate-deletion path and the scheduled processor's per-request completion) runs inside one `DB::transaction()`; any failure rolls back every write, leaving the account exactly as it was (including its live PII) rather than a partially-deleted state.
- **Lock order**: `users` (the target row) → `carts` (all, ordered by id) → `customer_properties` (all, ordered by id) — the same "lock the user row first" convention already used by `AddCartItemAction`/`CreatePaymentAttemptAction`. `App\Actions\Payment\CreatePaymentAttemptAction`, `App\Actions\Contract\RequestContractAction`, and `App\Actions\Contract\CreateContractBookingAction` now all lock `users` as their own first step too (Phase 13), specifically so `PendingAccountDeletionGuard`'s check cannot race against this endpoint's own `users` lock — whichever transaction acquires that single shared row first fully serializes the other. The scheduled processor locks `users` → the deletion request row → `carts` → (inside the erasure step) `customer_properties`; this differs in the relative order of the request row vs. `carts` from the HTTP path, which is safe for the same reason — `users` being the universal first lock on both paths means no second, distinct resource can ever be held by one side while the other waits on it. No external network/provider (Stripe, Twilio) call is ever made inside any of these transactions.
- **Postman variables used**: `access_token` (Bearer auth), `password`.

---

## 13a. Get Account Deletion Status

- **HTTP method / route**: `GET /v1/auth/account-deletion`
- **Auth required**: Yes — `Authorization: Bearer {{access_token}}` (middleware group `auth.customer`). Always reflects only the authenticated caller's own state — there is no user-id parameter, so reading another customer's deletion status is architecturally impossible, not merely forbidden.
- **Success status**: `200 OK`
- **Success response (no request ever made)**:
```json
{
  "success": true,
  "message": "Account deletion status retrieved successfully.",
  "data": { "deletion_status": "NONE", "requested_at": null }
}
```
- **Success response (deferred, still pending)**:
```json
{
  "success": true,
  "message": "Account deletion status retrieved successfully.",
  "data": { "deletion_status": "PENDING", "requested_at": "2026-08-21T16:38:18+00:00" }
}
```
  No database or request UUID is ever returned. A fully completed deletion cannot normally reach this endpoint at all — `auth.customer` already rejects the request once the account's session is revoked and its `CUSTOMER` role removed, independent of anything this endpoint does. There is no cancel-deletion endpoint in this phase — a deletion request is intentional and reauthenticated; the system completes it once eligible rather than offering to abandon it.

---

## Password policy

Applies to `register`, `reset-password`, and `change-password`: minimum 8 characters, at least one letter, at least one number. Outside the automated test environment, Laravel's `uncompromised()` check additionally rejects passwords found in known data breaches (via the Have I Been Pwned k-anonymity API).

---

## Reference: OTP purposes and cooldown/expiry summary

| Purpose | Issued by | Code format | Expiry | Max attempts | Resend cooldown |
|---|---|---|---|---|---|
| `PHONE_VERIFICATION` | Registration, Resend Phone OTP | 6 digits | 5 minutes | 5 | 60 seconds |
| `PASSWORD_RESET` | Forgot Password | 6 digits | 5 minutes | 5 | 60 seconds |
| `PHONE_NUMBER_CHANGE` | Request Phone Number Change, Resend Phone Number Change OTP | 6 digits | 5 minutes | 5 | 60 seconds |

## Reference: session/token lifetimes

| Token | Lifetime | Source |
|---|---|---|
| Access token (JWT) | 15 minutes | `config('jwt.access_token_ttl_minutes')`, env `AUTH_ACCESS_TOKEN_TTL_MINUTES` |
| Refresh token / session | 30 days, absolute from login | `config('jwt.session_ttl_days')`, env `AUTH_SESSION_TTL_DAYS` |
| Password reset token | 15 minutes | `VerifyPasswordResetOtpAction::RESET_SESSION_TTL_MINUTES` |
