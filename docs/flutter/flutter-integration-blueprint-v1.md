# BLUE V1 — Flutter Customer App Integration Blueprint (v1)

**Status**: Authoritative planning document for Flutter development. Not implementation.

**Source of truth**: this document is derived entirely from the current repository state —
`backend/routes/api.php`, `docs/api-contracts/*.md`, `app/Actions/*`, `app/Http/Controllers/Api/V1/*`,
`app/Http/Requests/*`, `app/Http/Middleware/*`, `config/*`, `bootstrap/app.php`, and
`backend/tests/Feature/*` — verified against a full `php artisan route:list` run on:

```
HEAD: 8db0738 feat(auth): add deferred customer account deletion
origin/main: in sync with HEAD
Working tree: clean
```

Nothing in this document describes a route, field, or behavior that does not exist in the backend
today. Where the low-level request/response JSON shape for an endpoint is already fully specified in
`docs/api-contracts/*.md`, this document does not duplicate every field a second time — it names the
authoritative contract file and gives Flutter the integration-level detail (screen mapping, sequencing,
state-machine, error handling) that the contract files themselves don't cover. **When in doubt about
an exact field name, the file under `docs/api-contracts/` is the tie-breaker, not this document.**

---

## Table of contents

1. [Customer API inventory](#1-customer-api-inventory)
2. [Authentication architecture](#2-authentication-architecture)
3. [Token storage & session management](#3-token-storage--session-management)
4. [App startup / splash decision tree](#4-app-startup--splash-decision-tree)
5. [Screen inventory](#5-screen-inventory)
6. [Service catalog → cart flow](#6-service-catalog--cart-flow)
7. [Checkout + appointment flow](#7-checkout--appointment-flow)
8. [Stripe payment flow](#8-stripe-payment-flow)
9. [Booking flow](#9-booking-flow)
10. [Contract flow](#10-contract-flow)
11. [Property flow](#11-property-flow)
12. [Profile](#12-profile)
13. [Account deletion](#13-account-deletion)
14. [Network / API client design](#14-network--api-client-design)
15. [State management](#15-state-management)
16. [Error model](#16-error-model)
17. [Offline / connectivity behavior](#17-offline--connectivity-behavior)
18. [Flutter security rules — non-negotiable](#18-flutter-security-rules--non-negotiable)
19. [Flutter folder structure](#19-flutter-folder-structure)
20. [Navigation map](#20-navigation-map)
21. [Implementation order](#21-implementation-order)
22. [API ↔ screen matrix](#22-api--screen-matrix)
23. [Backend gaps that block Flutter](#23-backend-gaps-that-block-flutter)

---

## 1. Customer API inventory

Base URL: `{{base_url}}/api` → every route below is under `/v1/...` (local default
`http://127.0.0.1:8000/api/v1`).

Envelope for every endpoint below (unless noted): `{ "success": bool, "message": string, "data": object|null }`.
Validation failures: `422` with `{ "success": false, "message": "The given data was invalid.", "errors": {...} }`.
`logout`/`logout-all` omit `data` entirely.

### A. Public / pre-auth (no Authorization header)

| Method | URI | Middleware | Controller | Purpose | Success | Common failures |
|---|---|---|---|---|---|---|
| POST | `/v1/auth/register` | `throttle:auth-register` | `Auth\RegisterController` | Create account, issue phone OTP | 201 | 422 |
| POST | `/v1/auth/verify-phone` | `throttle:auth-otp-verify` | `Auth\VerifyPhoneController` | Verify registration OTP, activate account | 200 | 422 |
| POST | `/v1/auth/resend-otp` | `throttle:auth-otp-issue` | `Auth\ResendOtpController` | Resend registration OTP | 200 | 422 |
| POST | `/v1/auth/login/request-otp` | `throttle:auth-login-otp-issue` | `Auth\RequestLoginOtpController` | **Canonical Customer login, step 1**: issue Login OTP (non-enumerating) | 200 always | 422 (malformed input only) |
| POST | `/v1/auth/login/verify-otp` | `throttle:auth-login-otp-verify` | `Auth\VerifyLoginOtpController` | **Canonical Customer login, step 2**: verify OTP, issue tokens | 200 | 422 |
| POST | `/v1/auth/login/resend-otp` | `throttle:auth-login-otp-issue` | `Auth\ResendLoginOtpController` | Resend Login OTP | 200 always | 422 (malformed input only) |
| POST | `/v1/auth/refresh` | `throttle:auth-refresh` | `Auth\RefreshController` | Rotate access+refresh token pair | 200 | 422, 429 |
| POST | `/v1/auth/logout` | none (manual Bearer decode) | `Auth\LogoutController` | Revoke current session | 200 | 401 |
| POST | `/v1/auth/logout-all` | none (manual Bearer decode) | `Auth\LogoutAllController` | Revoke every session | 200 | 401 |
| POST | `/v1/auth/forgot-password` | `throttle:auth-otp-issue` | `Auth\ForgotPasswordController` | Issue password-reset OTP (non-enumerating) | 200 always | 422 (malformed input only) |
| POST | `/v1/auth/verify-password-reset-otp` | `throttle:auth-otp-verify` | `Auth\VerifyPasswordResetOtpController` | Verify reset OTP, issue reset token | 200 | 422 |
| POST | `/v1/auth/reset-password` | `throttle:auth-reset` | `Auth\ResetPasswordController` | Set new password with reset token | 200 | 422 |
| GET | `/v1/reference-data/registration` | none | `ReferenceData\ReferenceDataController` | Cities→areas, relationship types, service categories | 200 | — |
| GET | `/v1/service-categories` | none | `ServiceCatalog\ListServiceCategoriesController` | Home category list | 200 | — |
| GET | `/v1/service-categories/{category}/services` | none | `ServiceCatalog\ListCategoryServicesController` | Services in a category + pricing preview | 200 | 404 (unknown/inactive category) |
| GET | `/v1/services/{service}` | none | `ServiceCatalog\GetServiceDetailsController` | Full service detail, options, pricing preview | 200 | 404 (unknown/inactive slug) |
| GET | `/v1/health` | none | closure in `routes/api.php` | Liveness/DB check | 200 | 503 |

`logout`/`logout-all` are declared **outside** the `auth.customer` middleware group in `routes/api.php`,
but still require `Authorization: Bearer {{access_token}}` — `LogoutAction`/`LogoutAllAction` decode the
token manually rather than going through the `AuthenticateCustomer` middleware. Functionally, treat them
as authenticated endpoints; the distinction only matters if you're grepping the route file.

### B. Authenticated Customer (`auth.customer` middleware, `Authorization: Bearer {{access_token}}` required)

| Method | URI | Rate limit | Controller | Purpose | Success | Common failures |
|---|---|---|---|---|---|---|
| POST | `/v1/auth/change-password` | — | `Auth\ChangePasswordController` | Change password, revoke other sessions | 200 | 401, 422 |
| DELETE | `/v1/auth/account` | `throttle:auth-account-delete` | `Auth\DeleteAccountController` | Delete account (immediate or deferred) | 200 or 202 | 401, 409, 422 |
| GET | `/v1/auth/account-deletion` | — | `Auth\GetAccountDeletionStatusController` | Read own deletion request state | 200 | 401 |
| POST | `/v1/auth/change-phone-number` | `throttle:auth-phone-change-issue` | `Auth\RequestPhoneNumberChangeController` | Start phone-number change, issue OTP | 200 | 401, 422 |
| POST | `/v1/auth/verify-phone-number-change-otp` | `throttle:auth-phone-change-verify` | `Auth\VerifyPhoneNumberChangeOtpController` | Confirm new phone number | 200 | 401, 422 |
| POST | `/v1/auth/resend-phone-number-change-otp` | `throttle:auth-phone-change-issue` | `Auth\ResendPhoneNumberChangeOtpController` | Resend phone-change OTP | 200 | 401, 422 |
| GET | `/v1/profile` | — | `Profile\GetProfileController` | Profile screen data | 200 | 401 |
| PATCH | `/v1/profile` | — | `Profile\UpdateProfileController` | Update name/email/area/relationship/interests | 200 | 401, 422 |
| GET | `/v1/cart` | — | `Cart\GetCartController` | Cart screen data | 200 always | 401 |
| POST | `/v1/cart/items` | — | `Cart\AddCartItemController` | Add a service to cart | 201 | 401, 404, 422 |
| PATCH | `/v1/cart/items/{item}` | — | `Cart\UpdateCartItemController` | Update quantity/options | 200 | 401, 404, 422 |
| DELETE | `/v1/cart/items/{item}` | — | `Cart\RemoveCartItemController` | Remove one item | 200 | 401, 404 |
| DELETE | `/v1/cart` | — | `Cart\ClearCartController` | Clear cart (keeps cart row) | 200 always | 401 |
| GET | `/v1/checkout` | — | `Checkout\GetCheckoutController` | Checkout summary screen | 200 always | 401 |
| PUT | `/v1/checkout/location` | — | `Checkout\SaveCheckoutLocationController` | Save service-visit address | 200 | 401, 404, 422 |
| GET | `/v1/checkout/appointment-slots` | — | `Checkout\GetAppointmentSlotsController` | List bookable slots | 200 | 401, 404 (no cart) |
| POST | `/v1/checkout/appointment-hold` | — | `Checkout\CreateAppointmentHoldController` | Hold a slot | 201 | 401, 404, 422 |
| DELETE | `/v1/checkout/appointment-hold` | — | `Checkout\ReleaseAppointmentHoldController` | Release the held slot | 200 always | 401 |
| POST | `/v1/payments` | — (Idempotency-Key required) | `Payment\CreatePaymentController` | Start a payment for checkout | 201 or 200 | 401, 404, 409, 422 |
| GET | `/v1/payments/{payment}` | — | `Payment\GetPaymentController` | Poll payment status | 200 | 401, 404 |
| GET | `/v1/bookings` | — | `Booking\ListBookingsController` | Booking list screen | 200 | 401 |
| GET | `/v1/bookings/{booking}` | — | `Booking\GetBookingController` | Booking detail screen | 200 | 401, 404 |
| POST | `/v1/bookings/{booking}/cancel` | — | `Booking\CancelBookingController` | Customer-initiated cancellation | 200 always (idempotent) | 401, 404, 409 |
| GET | `/v1/properties` | — | `Property\ListPropertiesController` | Properties list screen | 200 | 401 |
| POST | `/v1/properties` | — | `Property\CreatePropertyController` | Add a property | 201 | 401, 422 |
| GET | `/v1/properties/{property}` | — | `Property\GetPropertyController` | Property detail + its contracts | 200 | 401, 404 |
| PATCH | `/v1/properties/{property}` | — | `Property\UpdatePropertyController` | Edit a property | 200 | 401, 404, 409, 422 |
| DELETE | `/v1/properties/{property}` | — | `Property\DeletePropertyController` | Archive a property (never hard-deletes) | 200 always | 401, 404 |
| GET | `/v1/contracts` | — | `Contract\ListContractsController` | Contracts list screen | 200 | 401 |
| POST | `/v1/contracts/requests` | — | `Contract\RequestContractController` | Request a new Service Contract | 201 | 401, 404, 409, 422 |
| GET | `/v1/contracts/{contract}` | — | `Contract\GetContractController` | Contract detail screen | 200 | 401, 404 |
| POST | `/v1/contracts/{contract}/accept` | — | `Contract\AcceptContractController` | Accept contract terms → `PENDING_PAYMENT` | 200 | 401, 404, 409 |
| POST | `/v1/contracts/{contract}/services/{contractItem}/book` | — | `Contract\CreateContractBookingController` | Consume one contract visit into a Booking | 201 | 401, 404, 409, 422 |
| POST | `/v1/contracts/{contract}/billing/checkout` | — | `Contract\Billing\CreateContractBillingCheckoutController` | Start/resume Stripe Checkout subscription | 200 | 401, 404, 409 |

### C. Provider/webhook — Flutter must NEVER call these

| Method | URI | Auth |
|---|---|---|
| POST | `/v1/payments/webhooks/stripe` | Stripe signature only |
| POST | `/v1/contracts/billing/webhooks/stripe` | Stripe signature only (separate secret) |

These exist purely so Stripe's servers can push payment/subscription state into BLUE. Calling either
from Flutter would be pointless (no client can produce a valid Stripe signature) and is explicitly out
of the customer app's contract.

### D. Admin — Flutter Customer app must NEVER call these

| Method | URI |
|---|---|
| POST | `/v1/admin/auth/login` |
| POST | `/v1/admin/auth/refresh` |
| GET | `/v1/admin/me` |
| GET | `/v1/admin/bookings` |
| GET | `/v1/admin/bookings/{booking}` |
| GET | `/v1/admin/technicians` |
| GET | `/v1/admin/booking-items/{bookingItem}/technician-candidates` |
| POST | `/v1/admin/booking-items/{bookingItem}/assign-technician` |
| POST | `/v1/admin/booking-items/{bookingItem}/reassign-technician` |
| POST | `/v1/admin/booking-items/{bookingItem}/start-work` |
| POST | `/v1/admin/booking-items/{bookingItem}/complete-work` |
| GET | `/v1/admin/contracts` |
| GET | `/v1/admin/contracts/{contract}` |
| POST | `/v1/admin/contracts/{contract}/approve` |
| POST | `/v1/admin/contracts/{contract}/send-for-acceptance` |
| POST | `/v1/admin/contracts/{contract}/suspend` |
| POST | `/v1/admin/contracts/{contract}/cancel` |

`auth.admin` re-checks `ADMIN`/`SUPER_ADMIN` role membership **and** requires the session's
`client_type` to be `ADMIN_WEB` on every request — a customer mobile-issued token can never pass this
check even for a user who happens to also hold an Admin role. There is no reason for the Flutter
customer app to ever hold an admin token, and no code path in this API design expects it to.

**Customer-usable route count: 49** (10 public pre-auth incl. health, 5 public catalog/reference,
34 `auth.customer`-protected). Provider/webhook: 2. Admin-only: 17. Total routes in `route:list`: 72
(the remaining 4 are the Laravel framework's `web.php` root, `storage/{path}` GET/PUT, and `/up`).

---

## 2. Authentication architecture

Full low-level contract: `docs/api-contracts/authentication-v1.md`. This section is the sequencing and
state-machine view Flutter needs on top of it.

### Global session facts

- Access token: HS256 JWT, **15 minutes**, embeds `sub` (user UUID), `sid` (session UUID), `role`,
  `client`, `iat`, `nbf`, `exp`, `jti`. Flutter should treat it as an opaque bearer string — never
  decode/trust its claims client-side for authorization decisions, only for reading its own `exp` to
  pre-emptively refresh.
- Refresh token: 64 hex-char opaque string, **30 days absolute from login, not extended by refresh
  calls**. Every successful `POST /v1/auth/refresh` **rotates both tokens** — the old raw refresh token
  is invalidated the instant a new one is issued. There is no sliding/renewing session window.
- OTP policy (identical for registration, password reset, phone-number change, **and login**): 6 digits,
  5-minute expiry, 5 max verification attempts (`ATTEMPTS_EXCEEDED` on the 6th), 60-second resend
  cooldown.
- Non-enumeration: `forgot-password` always returns `200` with an identical message regardless of
  whether the phone number exists — Flutter must never imply "phone number not found" from this
  endpoint's response.

### 2.1 Registration → phone verification

```
POST /v1/auth/register
  → 201, data.otp_verification_uuid, data.otp_expires_at, data.account_status = PENDING_VERIFICATION
POST /v1/auth/verify-phone { otp_verification_uuid, otp_code }
  → 200, account_status = ACTIVE, phone_verified = true
  (Flutter now routes to Login — verify-phone does NOT return tokens)
POST /v1/auth/resend-otp { otp_verification_uuid }   [optional, on cooldown expiry]
  → 200, NEW otp_verification_uuid — replaces the one in local state
```

Flutter must hold `otp_verification_uuid` only in memory/screen state for the duration of the OTP
screen — never persist it. A resend **replaces** the UUID; the old one becomes invalid for both verify
and resend.

### 2.2 Login → tokens (Phone + OTP, passwordless)

**BLUE V1 product decision: Customer login is passwordless.** Flutter does **not** plan a
phone+password login screen — the canonical (and only customer-facing) login flow is:

```
Phone Number
  ↓
POST /v1/auth/login/request-otp { phone_number }
  → 200 ALWAYS (non-enumerating — never implies "phone number not found")
6-Digit LOGIN OTP
  ↓
POST /v1/auth/login/verify-otp { phone_number, otp_code, client_type: "MOBILE_IOS"|"MOBILE_ANDROID", device_name?, app_version? }
  → 200, data: { user_uuid, full_name, phone_number, email, role, session_uuid,
                 access_token, access_token_expires_at, refresh_token, session_expires_at }
  → 422 "Invalid or expired verification code." (unified for every rejection reason)
POST /v1/auth/login/resend-otp { phone_number }   [optional, on cooldown expiry]
  → 200 ALWAYS (same non-enumerating response as request-otp)
```

`client_type` must be `MOBILE_IOS` or `MOBILE_ANDROID` — set per platform at build/runtime, never
hardcoded to one value if the app ships on both platforms. Every `verify-otp` failure mode (unknown
phone, ineligible account, wrong code, expired, attempts-exceeded) collapses to the same generic 422
message — **the OTP screen must show one generic "invalid or expired verification code" error**, never
branch UI on which specific reason failed. Same non-enumeration discipline as Forgot Password (§2.6):
`request-otp`/`resend-otp` always return `200` regardless of whether the phone number is registered or
eligible, so the phone-entry screen must never imply "phone number not found." There is no
`otp_verification_uuid` anywhere in this flow — keep `phone_number` in local screen state across the
phone-entry and OTP screens instead (do not persist it beyond the login flow).

**Removed, do not implement**: `POST /v1/auth/login` (phone + password) has been removed from the
backend entirely — it is no longer a registered route and is not part of the Customer contract.
Password-based account-security features elsewhere (Change Password, Reset Password, Delete Account
re-authentication) are unaffected and remain part of the app.

### 2.3 Authenticated calls + silent refresh

Every `auth.customer` call: `Authorization: Bearer {{access_token}}`. On `401`, the interceptor attempts
exactly one silent refresh (see §3) before surfacing anything to the UI.

### 2.4 Refresh

```
POST /v1/auth/refresh { refresh_token }
  → 200, data: { access_token, access_token_expires_at, refresh_token, session_uuid, session_expires_at }
```

No Bearer header on this call — the refresh token in the body **is** the credential. On `422` (invalid,
expired, revoked, or the account/role/client-type check fails), the session is dead: clear all stored
credentials and route to Login. `429` (rate-limited, 60/min per IP) should back off and retry once
briefly, never loop.

### 2.5 Logout / Logout All

```
POST /v1/auth/logout        → 200, revokes only this session
POST /v1/auth/logout-all    → 200, revokes every session for this user (all devices)
```

Both require Bearer. Always clear local credentials after either call succeeds — and also on a `401`
from either (the session was already gone). Logout is not idempotent in the sense of "safe to retry with
the same now-invalid token" — after the first successful call, a retry with the same access token
returns `401`; treat that as an already-achieved logout, not a failure to surface to the user.

### 2.6 Forgot Password → Reset

```
POST /v1/auth/forgot-password { phone_number }
  → 200 ALWAYS, generic message, no otp_verification_uuid returned
POST /v1/auth/verify-password-reset-otp { phone_number, otp_code }
  → 200, data: { reset_token, reset_token_expires_at }   (15-minute TTL)
POST /v1/auth/reset-password { reset_token, password, password_confirmation }
  → 200, all sessions for the user revoked — customer must log in again
```

Note the shape difference from registration OTP: this flow is keyed by `phone_number` + `otp_code`
throughout (never an `otp_verification_uuid` — `forgot-password` never returns one, by design, to stay
non-enumerating). Do not reuse the registration OTP screen's request-shape assumptions here.

### 2.7 Change Password (authenticated)

```
POST /v1/auth/change-password { current_password, new_password, new_password_confirmation }
  → 200, only OTHER sessions revoked — the session making this call stays valid
```

### 2.8 Change Phone Number (authenticated, 3-step OTP flow)

```
POST /v1/auth/change-phone-number { new_phone_number }
  → 200, data.otp_verification_uuid (new — do not confuse with any earlier registration/reset OTP UUID)
POST /v1/auth/verify-phone-number-change-otp { otp_verification_uuid, otp_code }
  → 200, phone_number updated; every OTHER session revoked (current one stays valid)
POST /v1/auth/resend-phone-number-change-otp { otp_verification_uuid }
  → 200, new otp_verification_uuid
```

### Password policy

Register / Reset Password / Change Password: min 8 chars, ≥1 letter, ≥1 number. Outside the automated
test environment, Laravel additionally rejects passwords found in known breach corpora (HIBP
k-anonymity) — Flutter should treat this the same as any other 422 validation message, not special-case
it, since the message text comes back through the standard `errors` object.

### HTTP status handling summary for every auth endpoint

| Status | Meaning | Flutter behavior |
|---|---|---|
| 200/201 | Success | proceed |
| 401 | Bearer token invalid/expired/revoked | attempt refresh once (see §3); if refresh also fails, force logout → Login |
| 422 | Business rejection or validation error | show the returned `message` (and per-field `errors` if present) inline; never retry automatically |
| 429 | Rate limited | show a generic "too many attempts, try again shortly" state; do not auto-retry in a loop |

---

## 3. Token storage & session management

### What to store, and where

| Item | Storage | Rationale |
|---|---|---|
| Access token (JWT) | OS-backed secure storage (iOS Keychain / Android Keystore via `flutter_secure_storage` or equivalent) | Short-lived but still a live bearer credential |
| Refresh token | OS-backed secure storage, same store as access token | Long-lived (30 days), the single most sensitive credential the app holds |
| `session_uuid` | Secure storage alongside the tokens, or simply not persisted (it is not required to make any authenticated call — only `access_token`/`refresh_token` are) | Only useful for support/debugging correlation; **never required by Flutter to drive any request** |
| Current user/profile snapshot (`user_uuid`, `full_name`, `phone_number`, `email`, `role`) | In-memory app state (Riverpod/Bloc — see §15); may be mirrored to `SharedPreferences`/Hive as a **non-secure, best-effort UI cache** for instant splash-screen display, never as the source of truth | Not a secret, but stale display data must never be trusted for authorization |
| `current_password` / any password field | **Never stored anywhere**, not even in memory beyond the single request that needs it | See §18 |
| Cart / checkout / payment / booking / contract state | In-memory app state only, always re-fetched from the API on screen entry | Server is authoritative for all of this (§6–§10) |

Do **not** use plain `SharedPreferences` (or Android's un-encrypted prefs / iOS `UserDefaults` directly)
for the access or refresh token. Use secure, OS-backed storage.

### Interceptor / refresh state machine

```
On every outgoing authenticated request:
  attach "Authorization: Bearer <access_token>" from secure storage

On response 401 from an authenticated endpoint:
  if a refresh is already in flight:
    queue this request's retry behind that in-flight refresh's completion
  else:
    mark "refreshing = true"
    call POST /v1/auth/refresh with the CURRENT stored refresh_token
    if refresh succeeds (200):
      atomically overwrite BOTH stored access_token and refresh_token with the response values
      mark "refreshing = false"
      release every queued request, each retrying ONCE with the new access_token
    if refresh fails (422/429/network):
      mark "refreshing = false"
      clear stored access_token + refresh_token + in-memory session state
      fail every queued request as "unauthenticated"
      navigate the app to Login (see §4)

Never:
  - send two concurrent /v1/auth/refresh calls from the same app instance
    (guard with a single in-flight Future/mutex the interceptor checks first)
  - retry the SAME original request more than once after a refresh
    (a second 401 after a successful refresh means something else is wrong —
     surface it as a hard auth failure, do not loop)
  - let a request that started before a refresh retry with the OLD refresh
    token after rotation — only the interceptor's single refresh call ever
    reads/writes the refresh token; regular requests only ever read the
    current access token at send time
```

This is exactly the hazard `docs/api-contracts/authentication-v1.md` §5 describes server-side ("the old
raw refresh token becomes unusable immediately — a concurrent request presenting the same old token
loses the race and fails") — the single-flight mutex above is what prevents Flutter from ever being the
side that loses that race against itself.

### Proactive refresh (recommended, not required)

Since the access token's `exp` is known from `access_token_expires_at` in every login/refresh response,
Flutter may optionally refresh a few seconds before expiry on app-foreground/resume rather than waiting
for a live `401`, to avoid a visible retry flicker. This is a UX optimization, not a correctness
requirement — the reactive 401→refresh→retry path above must work correctly on its own regardless.

---

## 4. App startup / splash decision tree

```
App launch
  │
  ├─ No access_token AND no refresh_token in secure storage
  │     → Login (Welcome/onboarding screens, if any, are frontend-only and precede this)
  │
  ├─ access_token present (regardless of whether it looks expired by exp)
  │     → attempt ONE authenticated call to establish session validity:
  │       recommended: GET /v1/profile (cheap, already needed for the app shell)
  │       │
  │       ├─ 200 → session valid → Main App (Home)
  │       │        also fetch GET /v1/auth/account-deletion once, in the
  │       │        background, to decide whether to show the pending-deletion
  │       │        banner (see §13) — never block Home on this call
  │       │
  │       └─ 401 → fall through to the refresh_token branch below
  │
  ├─ refresh_token present, access_token missing/rejected
  │     → POST /v1/auth/refresh
  │       ├─ 200 → store new tokens → Main App (Home)
  │       └─ 422/429/network failure → clear storage → Login
  │
  └─ Never a distinct "account deleted" or "session revoked" startup state:
        both surface identically as a 401/422 on the calls above, and both
        resolve the same way — clear storage, go to Login. The backend does
        not (and by design should not) tell an already-logged-out client
        WHY its session died.
```

**Pending account deletion does not change this tree.** Per `docs/api-contracts/authentication-v1.md`
§13, sessions remain fully valid while a deletion request is `PENDING` — the customer can keep using the
app normally to resolve the blocking obligation. Startup routes to Main App exactly as if no deletion
were pending; only after landing in the app does the pending-deletion banner/screen (§13) become
relevant.

---

## 5. Screen inventory

Every screen below is backed by an endpoint that exists today. No screen here requires backend work
beyond what's already shipped.

### AUTH

| Screen | Endpoint(s) | Key data | Actions | Next | Empty/loading | Errors |
|---|---|---|---|---|---|---|
| Splash | `GET /v1/profile` or `POST /v1/auth/refresh` | — | none | Login or Home (§4) | brief spinner | silent → Login |
| Welcome/onboarding | none (frontend-only) | — | Get Started / Login | Register or Login | — | — |
| Register | `POST /v1/auth/register`, `GET /v1/reference-data/registration` | city/area/relationship-type/service-category options | submit form | Verify Phone OTP | reference data spinner | 422 inline per-field |
| Verify Phone OTP | `POST /v1/auth/verify-phone`, `POST /v1/auth/resend-otp` | `otp_verification_uuid`, `otp_expires_at`, cooldown | enter code / resend | Login (with "verified, please log in" message) | countdown to resend | 422 (wrong/expired/exceeded) |
| Login — Enter Phone | `POST /v1/auth/login/request-otp` | phone | submit | Login — Verify OTP (always, regardless of whether the number is real) | spinner | — (non-enumerating, 200 always; 422 malformed phone only) |
| Login — Verify OTP | `POST /v1/auth/login/verify-otp`, `POST /v1/auth/login/resend-otp` | phone, code (session state, not `otp_verification_uuid`), cooldown | enter code / resend | Home | countdown to resend | 422 generic "invalid or expired" |
| Forgot Password | `POST /v1/auth/forgot-password` | phone | submit | Verify Reset OTP (always, regardless of whether the number is real) | spinner | 422 (malformed phone only) |
| Verify Reset OTP | `POST /v1/auth/verify-password-reset-otp` | phone, code | submit | Reset Password (with `reset_token`) | countdown | 422 generic "invalid or expired" |
| Reset Password | `POST /v1/auth/reset-password` | `reset_token`, new password | submit | Login | spinner | 422 |

### MAIN CUSTOMER APP

| Screen | Endpoint(s) | Key data | Actions | Next | Empty/loading | Errors |
|---|---|---|---|---|---|---|
| Home | `GET /v1/service-categories` | category id/code/name/description | tap category | Category Services | skeleton list | 5xx retry banner |
| Category Services | `GET /v1/service-categories/{category}/services` | service card + `pricing_preview` | tap service | Service Detail | skeleton grid | 404 → category no longer exists, pop back |
| Service Detail | `GET /v1/services/{service}` | media, options (TEXT/NUMBER/BOOLEAN/SINGLE_SELECT/MULTI_SELECT), `pricing_preview` | configure options, Add to Cart | Cart (or stay, "added" toast) | skeleton | 404 slug gone |
| Cart | `GET /v1/cart`, `PATCH/DELETE /v1/cart/items/{item}`, `DELETE /v1/cart` | items, per-item `pricing`, aggregate `pricing_status`/`total` | edit qty/options, remove, clear, proceed | Checkout Location | empty-cart illustration when `items: []` | 422 on edit → revert local edit, show message |
| Checkout: Location | `PUT /v1/checkout/location`, `GET /v1/reference-data/registration` (areas) | property type, area, address fields | save | Appointment Slot Selection | — | 422 inline |
| Appointment Slot Selection | `GET /v1/checkout/appointment-slots`, `POST /v1/checkout/appointment-hold` | slot list w/ `remaining_capacity`, `time_window` | pick slot | Checkout Review | empty state "no slots available" | 422 (slot passed/full) → refresh list |
| Checkout Review | `GET /v1/checkout` | full summary, `ready_for_payment` | confirm | Payment | — | `ready_for_payment=false` → disable CTA, explain why |
| Payment | `POST /v1/payments` | `client_secret`, `publishable_key` | hand off to Stripe PaymentSheet | Payment Processing/Result | spinner while creating attempt | 404/409/422 (see §8) |
| Payment Processing/Result | `GET /v1/payments/{payment}` (poll) | `status` | wait / retry payment method | Booking Detail (on success) or back to Payment (on failure) | spinner with status text | see §8 |
| Booking List | `GET /v1/bookings` | booking cards: number, status, total | tap | Booking Detail | empty state | 5xx retry |
| Booking Detail | `GET /v1/bookings/{booking}`, `POST /v1/bookings/{booking}/cancel` | full detail incl. items, `refund_due` | cancel (if eligible) | stays on screen, refreshed | — | 404, 409 (already completed) |
| Contracts List | `GET /v1/contracts` | contract summaries | tap | Contract Detail | empty state + "Request a Contract" CTA | 5xx retry |
| Contract Detail | `GET /v1/contracts/{contract}` | services, entitlements, CONTRACT bookings | accept / book a visit / pay billing | Accept / Contract Service Booking / Contract Billing Checkout | — | 404, 409 |
| Request Contract | `POST /v1/contracts/requests`, `GET /v1/properties` (pick one) | property, services or "all", desired start date | submit | Contracts List (status `REQUESTED`) | — | 404 (no property), 409 (archived property), 422 (ineligible service) |
| Accept Contract | `POST /v1/contracts/{contract}/accept` | contract terms to display | accept | Contract Billing Checkout | — | 409 (wrong status) |
| Contract Billing Checkout | `POST /v1/contracts/{contract}/billing/checkout` | `checkout_url` | open Stripe Checkout (web view) | back to Contract Detail after Stripe redirect | spinner | 404, 409 |
| Contract Service Booking | `GET /v1/checkout/appointment-slots`-style flow, `POST /v1/contracts/{contract}/services/{contractItem}/book` | slot picker | pick slot, confirm | Booking Detail | — | 404, 409 (billing not ACTIVE, entitlement exhausted), 422 |

### ACCOUNT

| Screen | Endpoint(s) | Key data | Actions | Next | Empty/loading | Errors |
|---|---|---|---|---|---|---|
| Profile | `GET /v1/profile` | name, email, phone, location, relationship, interests | navigate to edit/settings | — | — | 401 |
| Edit Profile | `PATCH /v1/profile`, `GET /v1/reference-data/registration` | editable fields | save | Profile | — | 422 |
| Properties List | `GET /v1/properties?status=active\|archived\|all` | property cards | tap, add | Property Detail / Add Property | empty state + CTA | 5xx retry |
| Add Property | `POST /v1/properties`, `GET /v1/reference-data/registration` | label, relationship, type, area, address | save | Properties List | — | 422 |
| Property Detail | `GET /v1/properties/{property}` | full property + its contracts summary | edit / archive | Edit Property | — | 404 |
| Edit Property | `PATCH /v1/properties/{property}` | editable fields | save | Property Detail | — | 409 (archived), 422 |
| Archive Property | `DELETE /v1/properties/{property}` | confirm dialog | confirm | Properties List | — | 404 (already gone — treat as success) |
| Change Password | `POST /v1/auth/change-password` | current + new password | submit | Profile/Settings | — | 422 |
| Change Phone Number | `POST /v1/auth/change-phone-number` | new number | submit | OTP verification screen | — | 422 |
| OTP verification (phone change) | `POST /v1/auth/verify-phone-number-change-otp`, `POST /v1/auth/resend-phone-number-change-otp` | code | submit/resend | Profile (updated) | countdown | 422 |
| Delete Account | `DELETE /v1/auth/account` | password confirmation | confirm | Login (200) or Pending Deletion Status (202) | — | 401, 409 (dual admin role), 422 (wrong password) |
| Pending Account Deletion Status | `GET /v1/auth/account-deletion` | `deletion_status`, `requested_at` | none (informational; resolve obligations elsewhere in the app) | — | — | 401 |

No screen above was invented beyond what an endpoint supports. Two items explicitly **not** built as
screens because no backend supports them: a technician-tracking/live-map screen (no technician-facing
API or customer-visible technician identity exists — see `docs/api-contracts/bookings-v1.md` "Not
implemented"), and an in-app booking reschedule flow (no reschedule endpoint exists; cancellation +
rebooking is the only supported path today).

---

## 6. Service catalog → cart flow

```
GET /v1/service-categories                       (Home)
  → GET /v1/service-categories/{category}/services  (category grid, each card's pricing_preview)
    → GET /v1/services/{service}                     (service detail: media, options, pricing_preview)
      → POST /v1/cart/items                           (add — server reprices and validates)
        → GET/PATCH/DELETE /v1/cart/items/{item}, DELETE /v1/cart
```

### Identifiers Flutter must track

| Concept | Identifier | Notes |
|---|---|---|
| Service | `service.uuid` (add to cart), `service.slug` (fetch detail) | Two different identifiers for the same service — don't conflate them |
| Option | `option.uuid` | one entry per configured option in the `options[]` request array |
| Choice (SINGLE_SELECT/MULTI_SELECT) | `choice.uuid` | sent as `choice_uuids: [...]` under the matching option |
| Cart item | `cart_item.uuid` (called `{item}` in the route) | used for PATCH/DELETE |
| Quantity | plain integer, 1–1000, default 1 | means "N identical instances of this service line" — never a substitute for a NUMBER option like hours/rooms/units |

### Option value field by type

| `service_option_types.code` | Request field | Shape |
|---|---|---|
| `TEXT` | `text_value` | string, 1–1000 chars |
| `NUMBER` | `numeric_value` | number, validated against the option's numeric rule (min/max/step/decimals) |
| `BOOLEAN` | `boolean_value` | `true`/`false` |
| `SINGLE_SELECT` / `MULTI_SELECT` | `choice_uuids` | array of choice UUIDs, validated against the option's selection rule (min/max selections) |

### Pricing fields Flutter renders (never computes)

`pricing_status` (`PRICED` / `QUOTE_REQUIRED` / `MISSING_CONTEXT` / `UNAVAILABLE`), `currency`
(`{code, symbol, decimal_places}`), `base_amount`, `adjustments[]` (label + running total, display-only),
`unit_total`, `quantity`, `line_total`, `required_context[]` (attribute codes still missing — render as
"depends on your location/appointment," never as an error), `requires_quote` (cart/checkout aggregate).

**Flutter never calculates an authoritative price.** The service-detail `pricing_preview` may be shown
as an estimate while configuring options, but every screen past that (Cart, Checkout, Payment) must
render the server's live `pricing.*`/`total` from the actual response — never a locally re-derived
number, and never a cached number from an earlier response. `PricingEngine` runs server-side on every
single Cart/Checkout/Payment response; there is exactly one pricing implementation in the whole system,
and it is not in Flutter.

`Update Cart Item`'s `options` field is a **full replacement**, not a merge — Flutter must always send
the complete current selection set on a PATCH, not just the field that changed.

---

## 7. Checkout + appointment flow

```
GET /v1/checkout                         (safe even with no cart/location/hold — never creates state)
PUT /v1/checkout/location                (upsert — full replace, one row per cart)
GET /v1/checkout/appointment-slots       (requires an existing ACTIVE cart → 404 if none)
POST /v1/checkout/appointment-hold       (replaces any prior open hold on this cart)
DELETE /v1/checkout/appointment-hold     (safe no-op if nothing held)
```

- **Location payload**: `property_type_id`, `other_property_type_name` (required only when the type's
  code is `OTHER`), `area_id`, `street_name`, `address_line`, `building_name_or_number`, `floor_number`,
  `unit_number`, `nearby_landmark`, `additional_location_notes`, `visit_contact_phone`. `area_id` comes
  from the same `GET /v1/reference-data/registration` cities→areas tree used at registration — there is
  no separate `city_id` field.
- **Reference-data dependency**: fetch `/v1/reference-data/registration` once (cache in memory for the
  session) to drive the property-type/area pickers; property types themselves aren't in that payload —
  they come from whatever reference list the property/checkout forms already use in the app (same
  `property_types` catalog `SaveCheckoutLocationAction` validates against).
- **Appointment hold**: request body is `{ "appointment_slot_uuid": "<uuid>" }` only — no time window,
  no price. Response is the full checkout object with `checkout.appointment.hold_uuid`,
  `.slot.starts_at/.ends_at/.time_window`, and `.expires_at`.
- **Hold expiration UX**: the hold TTL is **10 minutes** by default
  (`CHECKOUT_APPOINTMENT_HOLD_TTL_MINUTES`). Flutter should show a visible countdown from
  `checkout.appointment.expires_at` once a slot is held, and treat a checkout screen whose countdown
  hits zero as needing to re-fetch `GET /v1/checkout` — an expired hold reports back as
  `"appointment": null`, at which point Flutter must send the customer back to slot selection, never
  silently proceed to Payment with a stale hold.
- **Readiness**: `ready_for_payment` is the single authoritative "can we proceed" flag — `true` only
  when the cart has ≥1 item, a location is saved, an unexpired/unreleased hold exists, and
  `pricing_status === "PRICED"`. The Payment CTA on Checkout Review must be disabled/hidden whenever
  `ready_for_payment` is `false`, and the screen should explain which piece is missing (no items /
  no location / no appointment / pricing not resolved) rather than just graying out the button.
- **Cart freeze once Payment starts**: the moment `POST /v1/payments` succeeds in creating an attempt,
  the backend flips the cart `ACTIVE → CHECKOUT`. **Flutter must not attempt to mutate that cart**
  (add/update/remove items, change location, change hold) once a payment attempt is open for it — those
  Cart/Checkout mutation endpoints only ever operate on the customer's *current ACTIVE* cart, so calling
  them while a `CHECKOUT` cart is frozen silently starts a **brand-new, separate** cart rather than
  erroring. Practically: once the user is on the Payment screen, the app's Cart/Checkout mutation UI for
  *that* order must be inaccessible (e.g. no back-navigation into "edit cart" mid-payment) until the
  payment resolves (§8) — going back to Home/Cart afterward is fine and will correctly show a fresh
  empty/new cart if the customer starts shopping again.

---

## 8. Stripe payment flow

Full low-level contract: `docs/api-contracts/payments-v1.md`. **Stripe is the approved BLUE V1
provider**, targeting PaymentIntent semantics with `automatic_payment_methods.enabled = true` (which is
what makes Apple Pay availability a pure client/Stripe-Dashboard configuration question later — see
`docs/api-contracts/apple-pay-future-checklist.md` — with zero backend change required).

### 8.1 Creating a payment

```
POST /v1/payments
Headers: Idempotency-Key: <client-generated UUID, one per logical "start this payment" action>
Body: {}   (no financial fields accepted — amount/currency/status/cart_uuid are all `prohibited`)
```

- Generate a **fresh UUID once** when the customer taps "Pay" and reuse that exact same
  `Idempotency-Key` for every retry of that one logical attempt (network error, app relaunch mid-flow,
  etc.). Generating a new key on every tap defeats its purpose and can create a `409` (see below) if the
  customer double-taps.
- Success is `201` (new attempt) or `200` (same key resolved to an existing attempt — no duplicate
  charge risk).
- Response `data.payment`: `uuid`, `checkout_reference`, `status` (`PENDING` at this point),
  `requested_amount`, `currency`, `expires_at` (mirrors the renewed appointment hold), `provider`
  (`STRIPE`), and — **only on the call that actually reached Stripe** — `client_secret` and
  `publishable_key` together.

### 8.2 `client_secret` availability rule (critical)

`client_secret` is a **one-time, single-PaymentIntent capability token**. It is present:

- on a **fresh** `POST /v1/payments` call that successfully reached Stripe, or
- on a **same-Idempotency-Key retry** where the previous call never got far enough to confirm a
  provider object (network loss/timeout — outcome `UNKNOWN`).

It is **never** present on:

- `GET /v1/payments/{payment}` (any time, any status),
- a same-key retry that already has a confirmed provider reference,
- any other endpoint.

**Flutter must capture `client_secret` at creation time and hand it directly to the Stripe PaymentSheet
in the same flow — there is no way to "come back later" and fetch it again.** If the app is killed
between creating the payment and confirming it in PaymentSheet, the recovery path is: call
`POST /v1/payments` again with the **same** `Idempotency-Key` — if the previous attempt is still
recoverable (`PENDING`, no confirmed provider reference), the backend calls Stripe again with its own
derived idempotency key and can return a fresh `client_secret` for the same underlying PaymentIntent; if
the previous attempt already reached a provider-confirmed state, the response omits `client_secret` and
Flutter must fall back to polling `GET /v1/payments/{payment}` for the outcome instead of trying to
re-confirm.

### 8.3 Flutter payment sequence

```
1. Generate Idempotency-Key (UUID) once for this checkout attempt
2. POST /v1/payments  → { payment.uuid, client_secret, publishable_key }
3. Initialize Stripe PaymentSheet with client_secret + publishable_key
4. Customer completes payment in PaymentSheet (card, or Apple Pay once configured — see §8.6)
5. PaymentSheet reports its own client-side result (succeeded / canceled / failed)
   — this is a UX signal only, NEVER treated as booking confirmation
6. Regardless of the PaymentSheet's own result, poll/refresh:
     GET /v1/payments/{payment}   (path parameter is the payment's uuid)
   until status is terminal (SUCCESSFUL / FAILED / CANCELLED), or show a
   "processing" state and allow the customer to leave/return to Booking List
7. On status = SUCCESSFUL: the Booking is created server-side, asynchronously,
   by the Stripe webhook pipeline (see 8.4) — poll/refresh GET /v1/bookings
   (or navigate to Booking List) rather than assuming a Booking UUID is
   immediately available at step 6
```

**The backend webhook is authoritative, not the Stripe client SDK's own success callback.** A
PaymentSheet "succeeded" result means Stripe accepted confirmation from the client; it does not mean
BLUE's server has processed the webhook yet. Never route directly to a Booking Detail screen using a
guessed/assumed Booking UUID — there is no `POST /v1/bookings` and no client-supplied Booking creation
path at all. The correct pattern is: show a "Payment successful, finalizing your booking..." transitional
state, poll `GET /v1/payments/{payment}` (and/or `GET /v1/bookings` for a new entry) for a few seconds,
then land on Booking Detail once it appears.

### 8.4 Payment → Booking is server-side and asynchronous

`ProcessPaymentWebhookAction` (triggered by Stripe's webhook, never by Flutter) transitions the payment
attempt, and — only once it reaches `SUCCESSFUL` with `requires_reconciliation = false` — a **separate**
server-side action (`CreateBookingFromSuccessfulPaymentAction`) converts it into exactly one Booking.
This can take anywhere from under a second to (rarely) longer if Stripe's webhook delivery is delayed;
there is also a recovery Artisan command (`bookings:convert-successful-payments`) an operator can run if
a webhook was ever missed entirely. **Flutter's job is only to poll/refresh, never to assume timing.**

### 8.5 Status meanings for the Processing/Result screen

| `payment.status` | Meaning | Flutter UI |
|---|---|---|
| `PENDING` | Attempt open, provider outcome not yet final | "Processing your payment..." spinner, keep polling |
| `SUCCESSFUL` | Money captured (Booking conversion may still be in flight) | "Payment successful" → poll for the Booking, then navigate to Booking Detail |
| `FAILED` | Definitively failed at creation (e.g. rejected request/config) — no provider object exists | "Payment failed" → offer retry (new Idempotency-Key + `POST /v1/payments` again, cart/hold were auto-restored to `ACTIVE`) |
| `CANCELLED` | Provider-side PaymentIntent canceled | Same as `FAILED` — offer retry |
| (not shown to customer) `requires_reconciliation` | Backend-internal flag on an already-`SUCCESSFUL` payment where automatic Booking creation was withheld for safety | Flutter never sees or branches on this field — `GetPaymentController` never returns it. If the customer sees "payment successful" but no Booking ever appears after reasonable polling, treat it as a support case, not a retryable client error |

A declined card typically does **not** produce BLUE `FAILED` — the underlying PaymentIntent usually
stays in a non-terminal Stripe state (`requires_payment_method`), which PaymentSheet itself already
surfaces to the customer as "try a different payment method" without a new BLUE payment attempt being
needed. Only route back through `POST /v1/payments` (new attempt) when the *previous BLUE attempt itself*
reached `FAILED`/`CANCELLED`.

### 8.6 Stripe Flutter package strategy

Use the official `flutter_stripe` package with `PaymentSheet` (`initPaymentSheet` +
`presentPaymentSheet`), configured with the `publishable_key` returned per-payment (never hardcoded — it
is safe to log/embed, but always source it from the API response, since a future key rotation must not
require an app release). Apple Pay support inside PaymentSheet is additive configuration once the
Apple Developer / Stripe Dashboard prerequisites in `docs/api-contracts/apple-pay-future-checklist.md`
are complete — no backend change is needed to enable it. Do not pin an exact `flutter_stripe` version in
this document; select the latest stable release compatible with the target Flutter/Dart SDK when
implementation begins.

### 8.7 Idempotency-Key requirements recap

- Required header on every `POST /v1/payments` call — a UUID, missing or malformed → `422`.
- One key per logical "customer tapped Pay" action; reused verbatim on retries of that same action.
- A **different** key while an open attempt already exists for this checkout → `409` ("a payment is
  already in progress") — Flutter should surface this as "you already have a payment in progress for
  this order," not a generic error, and route to the Payment Processing screen for the existing attempt
  rather than letting the customer create a second one.

---

## 9. Booking flow

Full contract: `docs/api-contracts/bookings-v1.md`. There is no `POST /v1/bookings` — every Booking is
created server-side from a successful payment (STANDARD) or from consuming a Contract entitlement
(CONTRACT, §10). Flutter only ever **reads** and **cancels**.

### Customer-visible Booking status lifecycle

```
PAID → ASSIGNED → IN_PROGRESS → COMPLETED
{PAID, ASSIGNED, IN_PROGRESS} → CANCELLED
```

`COMPLETED` and `CANCELLED` are terminal. Each Booking Item inside a Booking has its own independent
status (`PENDING_ASSIGNMENT → ASSIGNED → IN_PROGRESS → COMPLETED`, or `CANCELLED`) — a multi-service
Booking's items are **not** guaranteed to move in lockstep; render each item's own status on Booking
Detail rather than inferring it from the parent Booking's status.

### What drives these transitions

`ASSIGNED`/`IN_PROGRESS`/`COMPLETED` on the Booking and its items are all driven by **Admin/technician
back-office action** (technician assignment, start work, complete work) — none of it is Flutter-reachable
or Flutter-triggered. The customer app only ever finds out about these transitions by re-fetching
`GET /v1/bookings/{booking}` — there is no push/webhook channel into Flutter for this in the current
backend, so **Booking Detail should be refreshed on screen focus/pull-to-refresh**, not left showing a
stale status indefinitely. Booking List should likewise refresh on focus.

### Cancellation

```
POST /v1/bookings/{booking}/cancel   (no request body)
  → 200 always (idempotent — cancelling twice returns the same persisted result)
  data: { booking: { uuid, status: "CANCELLED", cancelled_at }, refund_due }
```

- Cancellable from `PAID`, `ASSIGNED`, `IN_PROGRESS`. `409` if already `COMPLETED`.
- `refund_due` is `null` for a CONTRACT-sourced Booking (nothing was separately paid for it). For a
  STANDARD Booking it is `{ percentage, amount, execution: "MANUAL" }`, computed **once**, at the
  moment of first cancellation, from the company's cancellation policy: **100% before the calendar day
  of the appointment, 75% from that day onward** (business-local calendar day, not a rolling 24-hour
  window). `execution: "MANUAL"` means BLUE V1 never auto-refunds through Stripe — Flutter should
  display this as "You are eligible for a refund of `amount`; our team will process it manually," never
  imply an automatic instant refund.
- This is a **historical snapshot** — re-fetching the same cancelled Booking always returns the exact
  same `refund_due`, even if the company's policy config changes later.
- Cancelling a CONTRACT Booking automatically frees the entitlement it consumed (§10) — no separate
  Flutter action needed; a refreshed Contract Detail screen will show the visit as available again.

### What Flutter should poll vs. simply display

| Data | Behavior |
|---|---|
| Booking List / Detail status | Refresh on screen focus and pull-to-refresh; no continuous background polling needed once past the immediate post-payment window (§8.3 step 7) |
| `refund_due` | Static once a Booking is `CANCELLED` — fetch once, no need to re-poll |
| Appointment slot times | Static/display-only (`appointment_slots` are immutable once created) |
| Technician identity/contact | **Never displayed — not returned by any customer-facing endpoint** in this backend version |

---

## 10. Contract flow

Full contract: `docs/api-contracts/contracts-v1.md`. This is the most stateful customer flow in the app
— much of it is genuinely **waiting on Admin action**, and Flutter's job is to render each waiting state
honestly rather than implying the customer can accelerate it.

### Full lifecycle

```
REQUESTED --(Admin approves)--> APPROVED --(Admin sends)--> PENDING_CUSTOMER_ACCEPTANCE
  --(customer accepts)--> PENDING_PAYMENT --(customer completes Stripe Checkout)--> ... --> ACTIVE
ACTIVE --(term ends)--> EXPIRED   [lazy — computed on read, no scheduler dependency]
{REQUESTED, APPROVED, PENDING_CUSTOMER_ACCEPTANCE, PENDING_PAYMENT, ACTIVE, SUSPENDED} --> CANCELLED
```

### Customer-driven steps

| Step | Endpoint | Notes |
|---|---|---|
| Request | `POST /v1/contracts/requests` | `property_uuid`, `all_services` or `service_uuids[]`, `desired_start_date`, optional `customer_note`. Customer never sets status/price/entitlements. |
| (wait for Admin approval + entitlement definition — no Flutter action) | — | Contract sits `REQUESTED` then `APPROVED`. Show a "waiting for review" state. |
| (wait for Admin to send for acceptance — no Flutter action) | — | Contract sits `APPROVED`. |
| Accept | `POST /v1/contracts/{contract}/accept` | Only valid from `PENDING_CUSTOMER_ACCEPTANCE`. No e-signature provider in V1 — "accept" is just this authenticated call. Moves to `PENDING_PAYMENT`, **not** `ACTIVE`. |
| Billing checkout | `POST /v1/contracts/{contract}/billing/checkout` | No request body — amount/interval/currency are server-frozen from Admin approval. Returns `{ checkout_session_id, checkout_url }`. Open `checkout_url` in an in-app web view / external browser (Stripe-hosted Checkout page, not a native PaymentSheet — this is a *subscription* Checkout Session, a different Stripe primitive from the one-time PaymentIntent flow in §8). Safe to call again — resumes the same session rather than creating a duplicate. |
| (wait for Stripe webhook: `checkout.session.completed` then `invoice.paid` — no Flutter action) | — | Contract becomes `ACTIVE` only on confirmed first-invoice payment. Flutter should poll/refresh `GET /v1/contracts/{contract}` after returning from the Stripe Checkout web view. |
| Book a covered visit | `POST /v1/contracts/{contract}/services/{contractItem}/book` `{ appointment_slot_uuid }` | Only when Contract is `ACTIVE`, current date ≥ `starts_at`, billing status is `ACTIVE` or `CANCEL_AT_PERIOD_END` (not `PAST_DUE`/`PENDING_CHECKOUT`/`INCOMPLETE`), entitlement not exhausted, slot has capacity. Creates a real Booking (source `CONTRACT`) with **no Payment step**. |

### Entitlements

Each covered service on a Contract (`service_contract_items`) is either:

- `LIMITED_VISITS` — `included_visits` is a fixed cap; `used_visits`/`remaining_visits` are always
  **derived live** from actual non-cancelled CONTRACT Bookings against that item — never a separate
  mutable counter Flutter needs to reconcile itself.
- `UNLIMITED` — no cap; `included_visits`/`remaining_visits` are `null`.

Cancelling a CONTRACT Booking automatically and immediately frees the visit it consumed — refresh
Contract Detail after a cancellation to see the updated `remaining_visits`.

### Billing status blocks bookings independently of Contract status

A Contract can read `ACTIVE` while its billing is temporarily `PAST_DUE` (a failed recurring charge) —
in that state, **new** CONTRACT bookings are blocked (`409`) even though the Contract itself isn't
`SUSPENDED` yet. If `PAST_DUE` persists beyond a grace period, the Contract itself escalates to
`SUSPENDED` via a scheduled maintenance command. Flutter should surface the billing status distinctly
from the contract status on Contract Detail (e.g. a "payment issue — please update your billing" banner)
rather than only showing the top-level Contract status.

### Standard Booking vs. Contract Booking vs. Contract subscription billing — three separate things

| Concept | What it is | Payment mechanism |
|---|---|---|
| **Standard Booking** | One-off paid service, created via Cart → Checkout → `POST /v1/payments` → Stripe PaymentIntent → webhook → Booking | One-time Stripe PaymentIntent (§8) |
| **Contract subscription billing** | The recurring charge that keeps a Contract `ACTIVE` | Stripe Checkout `mode=subscription` + Subscriptions + Invoices (§10 billing checkout above) — a **different** Stripe primitive and webhook endpoint than Standard Booking payment |
| **Contract-covered Booking** | Consuming one entitlement visit under an already-`ACTIVE` Contract | No payment at all — `payment_attempt_id` is `null` on the resulting Booking; price is recorded as `0.000000` (visit already paid for via the subscription) |

Flutter must not conflate these three — e.g. never show a "pay for this visit" CTA on a Contract Service
Booking flow, and never route Contract subscription billing through the PaymentSheet/`client_secret` UI
built for §8 (it's a hosted Checkout web-view flow instead).

### UI states while waiting on Admin

Since `REQUESTED → APPROVED → PENDING_CUSTOMER_ACCEPTANCE` requires Admin action with no customer
control, Contract Detail should render explicit, distinct waiting states for each status rather than a
single generic "pending" spinner — the customer genuinely cannot do anything to speed up `REQUESTED` or
`APPROVED`, but *can* act once it reaches `PENDING_CUSTOMER_ACCEPTANCE` (Accept) or `PENDING_PAYMENT`
(Billing Checkout).

---

## 11. Property flow

Full contract: `docs/api-contracts/properties-v1.md`.

```
GET /v1/properties?status=active|archived|all
POST /v1/properties
GET /v1/properties/{property}
PATCH /v1/properties/{property}
DELETE /v1/properties/{property}   (archives — never a hard delete)
```

- Fields: `label`, `property_relationship_type_id`, `property_type_id`, `other_property_type_name`
  (required only when the type's code is `OTHER`), `area_id`, and the same address-field set as
  Checkout Location (`street_name`, `address_line`, `building_name_or_number`, `floor_number`,
  `unit_number`, `nearby_landmark`, `additional_location_notes`, `visit_contact_phone`).
- `area_id` drives city derivation exactly as elsewhere — no separate `city_id` field.
- **Archived is immutable**: `PATCH` on an archived property returns `409`. Flutter should hide the Edit
  action (not just disable it silently) once `is_active: false`.
- **Archiving is idempotent**: `DELETE` on an already-archived property still returns `200` — treat a
  retry the same as first success.
- **Contract dependency**: `GET /v1/properties/{property}` includes a lightweight summary of every
  Contract ever requested against it. A Property referenced by any Contract (even a historical
  cancelled/expired one) can never be hard-deleted by the backend — this is enforced server-side; Flutter
  doesn't need to pre-check it, but should not be surprised that an old property with contract history
  stays listed (archived, not gone) even after the customer "removes" it.

---

## 12. Profile

Full contract: `docs/api-contracts/profile-and-reference-data-v1.md`.

`GET /v1/profile` → `user_uuid`, `full_name`, `email`, `phone_number` (display-only here), `phone_verified`,
`account_status`, `location` (`city`, `area`), `property_relationship`, `service_interests[]`.

### Editable via `PATCH /v1/profile`

`full_name`, `email`, `area_id`, `property_relationship_type_id`, `service_interests[]` (full replacement
of the set, not a merge — send the complete desired list every time).

### NOT editable via `PATCH /v1/profile` — require a dedicated flow instead

| Field | Flow |
|---|---|
| `phone_number` | `POST /v1/auth/change-phone-number` → OTP verify (§2.8) |
| password | `POST /v1/auth/change-password` (§2.7) |
| account existence | `DELETE /v1/auth/account` (§13) |

Any of these fields present in a `PATCH /v1/profile` body are silently ignored server-side (no
validation error) — Flutter's Edit Profile form must not even offer them as editable fields, to avoid
implying a save that never happens.

---

## 13. Account deletion

**This section is written for App Store review compliance (Apple Guideline 5.1.1(v)).** Full backend
contract: `docs/api-contracts/authentication-v1.md` §13/§13a. Treat this as the most carefully-implemented
UX in the app — it is the one flow Apple reviewers will specifically test.

### 13.1 What the backend actually does

```
DELETE /v1/auth/account
Body: { "current_password": "..." }
```

The backend decides, automatically and server-side, between two outcomes — **Flutter never chooses
between them**:

| Outcome | Status | Meaning |
|---|---|---|
| **Immediate deletion** | `200` | No blocking obligation exists (no active Booking/Contract/open-or-unconverted Payment). The full erasure lifecycle runs in the same request. Sessions revoked immediately. |
| **Deferred deletion** | `202` | A blocking obligation exists. A durable `PENDING` deletion request is recorded (`requested_at` timestamp). **The account stays fully usable** — sessions remain valid — while the customer resolves the obligation (or simply waits it out). Completion is fully automatic once eligible; no further client action is ever required. |

Error outcomes: `422` (wrong password, or caller's own account already inactive), `409` (the account
also holds an active ADMIN/SUPER_ADMIN role — self-service deletion is refused outright, "contact
support" is the correct message *only* in this specific dual-role case), `401` (bad/expired session).

### 13.2 Reading deletion status

```
GET /v1/auth/account-deletion
→ { "deletion_status": "NONE" | "PENDING", "requested_at": string|null }
```

No blocking-obligation identifier is ever returned (Flutter cannot and must not try to show "waiting on
Booking #X" — the backend deliberately never reveals which obligation it's waiting on). There is no
"COMPLETED" state reachable through this endpoint — the moment deletion actually completes, the session
and role are gone, so `auth.customer` itself rejects the call before this endpoint's own logic runs.

### 13.3 What "PENDING" means for the rest of the app

- **Sessions stay valid.** Do not log the customer out or block navigation because a deletion is
  pending — this is deliberate backend behavior so the customer can resolve their own obligation.
- **New blocking obligations are refused (`409`)**, specifically: creating a new Payment
  (`POST /v1/payments`), requesting a new Contract (`POST /v1/contracts/requests`), and booking a new
  Contract-entitlement visit (`POST /v1/contracts/{contract}/services/{contractItem}/book`). Flutter
  should catch this specific `409` on those three calls while a deletion is pending and explain it
  ("Your account is scheduled for deletion — you can't start anything new, but you can still finish what's
  in progress") rather than showing a generic error.
- **Resolving an existing obligation is never blocked** — viewing/cancelling an existing Booking, reading
  Contracts/Profile/Properties, and ordinary cart building (adding items, before Payment) all work
  normally.
- **Automatic completion**: a scheduled backend job (`accounts:process-pending-deletions`, every 5
  minutes) re-checks eligibility and completes deletion the moment the obligation clears — through the
  exact same erasure logic as the immediate path. No client action, no polling requirement — though
  showing the Pending Deletion Status screen (§13.4) is still useful so the customer can see it resolve.

### 13.4 Recommended Flutter UX

```
Settings → Account → Delete Account
  1. Destructive warning screen — explain plainly what will happen (data erased/anonymized,
     cannot be undone) and, honestly, that active bookings/contracts/payments may delay completion
  2. Password confirmation (current_password)
  3. Final "are you sure" confirmation
  4. DELETE /v1/auth/account
       200 → clear all local credentials/state immediately → Login, with a
             one-time "Your account has been deleted" confirmation message
       202 → do NOT clear credentials/log out → navigate to a dedicated
             "Account Deletion Requested" screen (§13.4.1)
       409 (dual admin role) → show the exact returned message, do not
             offer any in-app retry — this genuinely requires contacting support
       422 (wrong password) → return to step 2 with an inline error
```

#### 13.4.1 "Account Deletion Requested" screen (the 202 case)

Show, persistently reachable from Settings → Account for as long as `GET /v1/auth/account-deletion`
returns `PENDING`:

- A clear statement that deletion has been requested and **will complete automatically** — never word
  this as "please contact support to finish" (that would fail Apple's requirement that the app itself be
  the deletion mechanism).
- `requested_at`, formatted for the customer's locale — this value is stable across repeated deletion
  attempts (retrying `DELETE /v1/auth/account` while already `PENDING` returns the *same*
  `requested_at`, or completes immediately if the obligation has since cleared).
- An honest, generic explanation that active bookings, contracts, or payments must resolve first (no
  specific obligation identifier — the backend doesn't expose one).
- A way back into the app to actually resolve things (e.g. a link to Booking List) — since the account
  stays fully usable while pending.
- No "cancel my deletion request" control — the backend has no such endpoint in this phase; a deletion
  request, once made, is only ever resolved by completing.

**Do not require contacting support as the only deletion mechanism** — the in-app flow above satisfies
Apple's requirement on its own for both the immediate and deferred cases. Support contact is correct only
for the dual-admin-role `409` edge case, which is not the path most customers will ever hit.

### 13.5 Known residual gap — disclose, don't hide

The backend team has explicitly documented a known limitation: `checkout_snapshot` (an immutable,
already-hashed record on old payment attempts) retains the exact service address and
`visit_contact_phone` captured at the moment of a historical payment, and is deliberately **not**
redacted on deletion — redacting it would silently break Stripe webhook-retry hash verification for that
payment. This data is never exposed through any customer-facing read path after deletion (the deleted
account has no working session/role left to query it with), so it does not affect the Flutter UX or
create a customer-visible gap — it's an internal storage fact, not something the app needs to explain to
users. It's recorded here only so this blueprint doesn't claim erasure is more complete than the backend
itself documents.

---

## 14. Network / API client design

Keep this intentionally boring and layered — one HTTP client, one auth interceptor, one repository per
domain. No speculative abstraction beyond what the app's actual 13 domains need.

| Component | Responsibility |
|---|---|
| `ApiClient` | Wraps the HTTP client (e.g. `dio`), base URL, default headers (`Content-Type: application/json`, `Accept: application/json`), JSON (de)serialization, and central error mapping into the app's error taxonomy (§16). One instance, app-wide. |
| `AuthInterceptor` | Attaches `Authorization: Bearer` from `TokenStore`; owns the single-flight refresh state machine (§3); rewrites requests after a successful refresh; triggers the "force logout" side effect on refresh failure. |
| `TokenStore` | Thin wrapper over secure OS storage (`flutter_secure_storage`) for access/refresh tokens. No business logic — just get/set/clear. |
| `AuthRepository` | `register`, `verifyPhone`, `resendOtp`, `login`, `refresh`, `logout`, `logoutAll`, `forgotPassword`, `verifyPasswordResetOtp`, `resetPassword`, `changePassword`, `requestPhoneNumberChange`, `verifyPhoneNumberChangeOtp`, `resendPhoneNumberChangeOtp`. |
| `ProfileRepository` | `getProfile`, `updateProfile`, plus `getRegistrationReferenceData` (shared by Register/Edit Profile/Add Property/Checkout Location screens — fetch once, cache in memory for the session). |
| `ServiceCatalogRepository` | `listCategories`, `listCategoryServices`, `getServiceDetails`. |
| `CartRepository` | `getCart`, `addItem`, `updateItem`, `removeItem`, `clearCart`. |
| `CheckoutRepository` | `getCheckout`, `saveLocation`, `getAppointmentSlots`, `createHold`, `releaseHold`. |
| `PaymentRepository` | `createPayment(idempotencyKey)`, `getPayment(uuid)`. Owns Idempotency-Key generation/lifecycle for the current checkout attempt. |
| `BookingRepository` | `listBookings`, `getBooking`, `cancelBooking`. |
| `ContractRepository` | `listContracts`, `requestContract`, `getContract`, `acceptContract`, `bookContractService`, `createBillingCheckout`. |
| `PropertyRepository` | `listProperties`, `createProperty`, `getProperty`, `updateProperty`, `archiveProperty`. |
| `AccountDeletionRepository` | `deleteAccount(currentPassword)`, `getDeletionStatus`. |

### DTO/model mapping

One Dart model per API resource shape actually documented in `docs/api-contracts/*.md` (e.g. `Cart`,
`CartItem`, `PricingResult`, `Checkout`, `Payment`, `Booking`, `BookingItem`, `Contract`,
`ContractItem`, `Property`, `Profile`). Money fields (`base_amount`, `unit_total`, `line_total`, `total`,
`requested_amount`, etc.) arrive as **decimal strings**, not JSON numbers — parse them into a
decimal-safe type (e.g. `Decimal`/`String`-backed value object), never a plain `double`, to avoid binary
floating-point rounding on currency. UUID fields are plain strings — no special wrapper needed. Avoid a
generic untyped `Map<String, dynamic>` passthrough anywhere past the repository boundary — every screen
should consume a typed model.

---

## 15. State management

**Recommendation: Riverpod** (or, equivalently, Bloc/Cubit if the team has stronger existing familiarity
with it — the reasoning below applies either way; pick one and use it consistently, don't mix).

Why one clear choice matters here specifically: this app has exactly one genuinely global,
long-lived piece of state (the authenticated session) that many independent feature areas need to read
and react to (a 401 anywhere must be able to force-navigate to Login from anywhere), plus several
medium-lived, feature-scoped states (cart, in-progress checkout, in-progress payment) that must be
**re-fetched from the server on every real screen visit** rather than cached indefinitely — because the
backend, not Flutter, is authoritative for cart pricing, checkout readiness, payment status, booking
status, and contract entitlements (§6–§10 all say this explicitly). That combination — one shared
session provider plus several screen-scoped, server-driven feature providers, no offline-first
synchronization requirement — is exactly Riverpod's sweet spot, and does not need a second paradigm
(e.g. no need for a separate persistence-sync framework) on top of it.

| State | Scope | Lives in |
|---|---|---|
| Auth/session (`isAuthenticated`, current user snapshot) | App-wide | one `AuthNotifier`/provider, read by the router for guards (§20) and by any screen needing "am I logged in" |
| Cart | Session-scoped, always re-fetched on Cart screen entry | `CartNotifier`, invalidated after every mutation |
| Checkout (location/hold/readiness) | Same cart's checkout session | `CheckoutNotifier`, invalidated after every mutation and on hold-expiry countdown reaching zero |
| Payment (current attempt) | Single in-flight payment | `PaymentNotifier`, holds the Idempotency-Key + polling state for the active attempt only |
| Bookings | List/detail, refreshed on focus | simple `FutureProvider`-style fetch-on-build, no long-lived cache needed |
| Contracts | List/detail, refreshed on focus | same pattern as Bookings |
| Profile | Fetched once per session, refreshed after edits | `ProfileNotifier` |

Do not build a single monolithic "AppState" object holding all of the above — keep each feature's
provider independently invalidatable, since e.g. a Cart mutation must never force-refetch Bookings.

---

## 16. Error model

One centralized mapping from HTTP response → a small closed error taxonomy, applied by `ApiClient`
before any repository/UI code sees the result — no screen should ever pattern-match on raw status codes
itself.

| HTTP | Taxonomy category | Backend meaning (verified in contracts) | User-facing handling |
|---|---|---|---|
| 401 | `SessionExpired` | Bearer token missing/invalid/expired/revoked, or the underlying session/role/client-type check failed | Attempt one silent refresh (§3); if that also fails, clear credentials and route to Login. Never shown as a raw error to the user if the refresh succeeds. |
| 404 | `NotFound` | Resource doesn't exist **or** belongs to another customer — deliberately never distinguished (ownership-safe not-found), per every contract doc's "never 403, always 404" convention | Generic "not found" state; for a resource the user just navigated from a list (e.g. tapped a Booking), treat as "this may have just changed — pull to refresh" |
| 409 | `Conflict` | Business-lifecycle conflict — wrong status for the requested transition, an in-progress payment already exists, archived-property immutability, dual-admin-role deletion refusal, pending-deletion new-obligation block | Show the server's own `message` verbatim where it's already customer-appropriate (all of these are written to be shown, per the contract docs) — do not invent a different message |
| 422 | `ValidationOrBusinessRejection` | Either Laravel `FormRequest` field validation (`errors: {field: [...]}`) or a business rule rejection (`message`, no `errors`) | If `errors` present: show per-field inline messages. If only `message`: show as a form-level/toast error. Never auto-retry. |
| 429 | `RateLimited` | Too many requests against one of the documented buckets (identity or IP) | Generic "too many attempts, please wait a moment" — do not auto-retry in a loop; a simple one-time backoff (a few seconds) is acceptable for idempotent GETs only |
| 500/503 | `ServerError` | Unexpected server failure, or `/v1/health` reporting the DB unavailable | Generic "something went wrong, please try again" with a manual retry action; never surface raw exception text |
| network unavailable | `NetworkUnavailable` | No connectivity / DNS/TLS failure before a response is received | "You're offline" state, distinct from ServerError, with retry |
| timeout | `Timeout` | Client-side request timeout | Treat like `NetworkUnavailable` for display purposes, but note that for a **mutating** call (Cart, Payment, Contract actions) a timeout does *not* prove the server didn't process it — never blindly auto-retry a mutating POST/PATCH/DELETE after a timeout; let the user retry explicitly, and rely on Idempotency-Key for Payment specifically (§8.7) |

**Never expose raw error internals to users** — no stack traces, no raw Laravel exception messages, no
`errors` object keys shown unformatted. Every `message` string returned by the documented contracts is
already written to be customer-facing (verified throughout `docs/api-contracts/*.md`, e.g. "The current
password you entered is incorrect."); this is safe to display directly. A response that doesn't match
any documented shape (should not happen against these contracts, but defensively) falls back to the
generic `ServerError` UI, never a raw dump.

---

## 17. Offline / connectivity behavior

| Safe to cache locally (display convenience only) | Never cache as authoritative — always re-fetch |
|---|---|
| Service categories, category service lists, service detail (media/options/pricing_preview) | Current cart contents/pricing |
| `GET /v1/reference-data/registration` (cities/areas/relationship types/service categories) | Payment status |
| Non-sensitive profile display fields, for instant splash/shell rendering | Appointment slot availability |
| Property list summaries, for instant list rendering before refresh completes | Contract entitlement state (`used_visits`/`remaining_visits`) |
| | Booking status |
| | Account deletion status |

Cached catalog/reference data should be treated purely as a "show something instantly, then refresh in
the background" optimization — every screen that reads it must still hit the live endpoint and replace
the cached view once the response arrives, since prices and availability change server-side independent
of the app. Never let a cached `pricing_preview` or slot list be what the customer actually pays against
— only a live Checkout/Payment response is authoritative for money.

**Secrets are never part of this caching discussion** — access/refresh tokens live only in secure OS
storage (§3), never in whatever generic cache mechanism (Hive/SharedPreferences/in-memory LRU) backs the
"display convenience" cache above.

---

## 18. Flutter security rules — non-negotiable

- **Never log Access Tokens.**
- **Never log Refresh Tokens.**
- **Never log Stripe `client_secret`.**
- **Never log raw OTP codes** (the backend itself never returns one in any environment — Flutter never
  has one to log in the first place, but this also means: never echo the user's typed OTP back into any
  analytics/crash-reporting breadcrumb).
- **Never persist `current_password`** (or any password field) beyond the single request that needs it —
  not in memory longer than necessary, never in any local storage, never in a form-state cache that
  survives navigation away from the screen.
- **Never trust local pricing** — every price shown past the service-detail preview stage must come from
  the live Cart/Checkout/Payment response for that exact request (§6).
- **Never call an Admin endpoint (`/v1/admin/...`) from the customer app** — Section 1.D lists every one
  that exists; none of them belongs in this client.
- **Never store full raw API responses blindly** — parse into typed models (§14); don't cache an entire
  JSON payload "just in case," especially anything containing `checkout_snapshot`-adjacent detail.
- **Never retry a destructive request infinitely** — `DELETE /v1/auth/account`, `POST .../cancel`,
  `DELETE /v1/properties/{property}` etc. are safe to retry *once, explicitly, on user action* (most are
  idempotent server-side), but never in an automatic loop.
- **Use `Idempotency-Key` correctly for Payment creation** — one key per logical attempt, reused on
  retries of that same attempt, never regenerated per network error (§8.7).
- **Clear credentials after refresh failure or account deletion** — both branches must leave the app in
  an unambiguous "not authenticated" state with nothing sensitive left in secure storage or in-memory
  session state.
- **HTTPS only outside local development** — the local-dev `http://127.0.0.1:8000` base URL is a
  dev-only exception; any non-local build configuration must use `https://`.
- **No secrets hard-coded into the Flutter bundle** — `STRIPE_PUBLISHABLE_KEY` is the one Stripe value
  that's safe client-side, and even it should be sourced from the per-payment API response, not baked
  into the app binary, so a future key rotation needs no app release. `STRIPE_SECRET_KEY` and
  `STRIPE_WEBHOOK_SECRET` must never exist anywhere in the Flutter codebase, build config, or bundled
  assets — they are backend-only by the API's own design and the backend never returns them.

**Clarification on Stripe keys**: `publishable_key` is deliberately public/client-safe (Stripe's own
design — it's meant to ship in client code) and is returned by `POST /v1/payments` specifically so
Flutter never needs to hardcode it. `client_secret` is per-payment and single-use-scoped (§8.2) — safe to
hold in memory for the duration of confirming that one payment, never safe to log or persist.

---

## 19. Flutter folder structure

Feature-first, with `data`/`domain`/`presentation` layers used **only** where the feature actually has
enough shape to justify the split — most BLUE V1 features are thin enough that `data` + `presentation`
(repository + models, then UI/state) is sufficient; a full separate `domain` layer with its own
use-case classes is warranted only for the two genuinely multi-step orchestrations (`checkout` tying
together cart/location/slot/hold, and `payment` tying together idempotency/polling/Stripe SDK handoff).

```
lib/
  app/                          # MaterialApp/CupertinoApp shell, theming, top-level router config
  core/
    network/                    # ApiClient, AuthInterceptor, base request/response types
    storage/                    # TokenStore (secure), lightweight display-cache wrapper (§17)
    errors/                     # Error taxonomy (§16), failure → user-message mapping
    routing/                    # Route definitions, auth guards (§20)
  features/
    auth/
      data/                     # AuthRepository, auth DTOs
      presentation/             # Splash, Welcome, Register, Verify OTP, Login, Forgot/Reset Password screens + state
    home/
      data/                     # ServiceCatalogRepository (categories)
      presentation/             # Home screen + state
    catalog/
      data/                     # ServiceCatalogRepository (category services, service detail) — shared with home/
      presentation/             # Category Services, Service Detail screens + state
    cart/
      data/                     # CartRepository, Cart/CartItem/PricingResult models
      presentation/             # Cart screen + state
    checkout/
      domain/                   # Checkout orchestration (location → slots → hold → readiness)
      data/                     # CheckoutRepository
      presentation/             # Location, Slot Selection, Review screens + state
    payment/
      domain/                   # Payment orchestration (idempotency key lifecycle, poll-until-terminal)
      data/                     # PaymentRepository
      presentation/             # Payment, Processing/Result screens + state
    bookings/
      data/                     # BookingRepository, Booking/BookingItem models
      presentation/             # List, Detail (incl. cancel) screens + state
    contracts/
      data/                     # ContractRepository, Contract/ContractItem models
      presentation/             # List, Detail, Request, Accept, Billing Checkout, Service Booking screens + state
    properties/
      data/                     # PropertyRepository, Property model
      presentation/             # List, Add, Detail, Edit screens + state
    profile/
      data/                     # ProfileRepository, Profile model, shared registration reference data
      presentation/             # Profile, Edit Profile screens + state
    account_settings/
      presentation/             # Change Password, Change Phone Number (+OTP) screens + state
    account_deletion/
      data/                     # AccountDeletionRepository
      presentation/             # Delete Account flow, Pending Deletion Status screens + state
```

`core/` is the only place allowed to know about HTTP/token mechanics; every `features/*/data` repository
depends on `core/network` and returns typed models, never raw JSON, to its own `presentation` layer.

---

## 20. Navigation map

```
Splash
  ├─(no session)──────────────► Auth stack
  └─(valid/refreshable session)► Main app (bottom nav)

Auth stack (unauthenticated only — guarded: authenticated users never see these)
  Welcome/Onboarding (optional, frontend-only)
    → Register → Verify Phone OTP → Login
    → Login ──(success)──► Main app
    → Login → Forgot Password → Verify Reset OTP → Reset Password → Login

Main app (bottom navigation — guarded: requires a valid/refreshable session; a 401 that
survives one refresh attempt anywhere pops the whole stack back to Auth)
  ┌─ Home
  │    → Category Services → Service Detail → (Add to Cart)
  ├─ Cart (badge shows item count)
  │    → Checkout: Location → Appointment Slot Selection → Checkout Review
  │       → Payment → Payment Processing/Result → Booking Detail
  ├─ Bookings
  │    → Booking List → Booking Detail (→ cancel)
  ├─ Contracts
  │    → Contracts List → Contract Detail
  │       → Request Contract (from Contracts List or Property Detail)
  │       → Accept Contract → Contract Billing Checkout (Stripe web view) → Contract Detail
  │       → Contract Service Booking (slot picker) → Booking Detail
  └─ Profile / Account
       → Edit Profile
       → Properties List → Add Property / Property Detail → Edit Property
       → Account Settings
            → Change Password
            → Change Phone Number → OTP Verification
            → Delete Account (warning → password → confirm) → Pending Account Deletion Status (if 202)
```

### Auth guards

- **Auth stack routes** (Register, Verify OTP, Login, Forgot/Reset Password): accessible only when no
  valid session exists. If a deep link or back-navigation would land an already-authenticated user here,
  redirect to Home instead.
- **Main app routes**: every route requires a valid (or silently-refreshable, per §3) session. The router
  should listen to the global `AuthNotifier` (§15) and pop the entire Main app stack back to the Auth
  stack the instant session state flips to "unauthenticated" (i.e. after a failed refresh) — this must
  work from *any* screen depth, not just the ones that happen to be showing an error at that moment.
- **Pending account deletion is not a navigation guard** — per §4 and §13.3, a `PENDING` deletion state
  does not restrict which Main app screens are reachable; it only changes what three specific *mutating*
  calls return (`409`) and adds the Pending Deletion Status screen as an additional reachable
  destination from Account Settings.

---

## 21. Implementation order

Derived from actual backend dependency order — each phase only needs what the previous phases already
built.

| Phase | Screens | Repositories/services | Key endpoints | Acceptance criteria |
|---|---|---|---|---|
| **F1** — Project skeleton | — | `core/` scaffolding only | — | App boots to a placeholder Home behind a router; theming/design tokens in place |
| **F2** — API client + secure storage | — | `ApiClient`, `TokenStore`, `AuthInterceptor` (refresh state machine stubbed against a test endpoint) | `GET /v1/health` | A request round-trips through the client and error taxonomy end-to-end against the real backend |
| **F3** — Auth | Splash, Welcome, Register, Verify Phone OTP, Login (Phone + OTP), Forgot/Reset Password | `AuthRepository` | §1.A + `POST /v1/auth/change-password` deferred to F9 | Full register→verify→login (phone+OTP)→refresh→logout cycle works against the real backend, including the 401→refresh→retry path (§3) proven with an artificially-expired token |
| **F4** — Home/catalog | Home, Category Services, Service Detail | `ServiceCatalogRepository` | `GET /v1/service-categories`, `.../services`, `GET /v1/services/{service}` | Full browse path renders real catalog data incl. all five option input types |
| **F5** — Cart | Cart | `CartRepository` | §6 cart endpoints | Add/update/remove/clear all correctly reprice from live server responses; 422 on invalid options handled inline |
| **F6** — Checkout | Location, Slot Selection, Review | `CheckoutRepository` | §7 checkout endpoints | `ready_for_payment` correctly gates the Payment CTA; hold-expiry countdown + re-fetch verified |
| **F7** — Stripe | Payment, Processing/Result | `PaymentRepository`, `flutter_stripe` integration | §8 payment endpoints | End-to-end payment with Stripe test cards produces a real Booking, verified by polling; retry-after-`FAILED` path verified; Idempotency-Key double-tap produces no duplicate charge |
| **F8** — Booking | Booking List, Booking Detail (+ cancel) | `BookingRepository` | §9 booking endpoints | Cancellation + `refund_due` display verified for both before-appointment-day and appointment-day cases |
| **F9** — Properties/Profile/Account settings | Properties (list/add/detail/edit/archive), Profile, Edit Profile, Change Password, Change Phone Number + OTP | `PropertyRepository`, `ProfileRepository` | §11, §12, `POST /v1/auth/change-password`, phone-change trio | Full property CRUD-minus-hard-delete verified; profile edit + phone-change OTP flow verified |
| **F10** — Contracts | Contracts List/Detail, Request, Accept, Billing Checkout, Service Booking | `ContractRepository` | §10 contract endpoints | Full lifecycle exercised against a backend test/seed Contract taken through Admin approval out-of-band; billing web-view handoff and return-and-refresh verified |
| **F11** — Account deletion | Delete Account flow, Pending Account Deletion Status | `AccountDeletionRepository` | §13 endpoints | Both `200` and `202` paths exercised (the latter requires a backend test account with an open obligation); pending-state `409` guard on the three blocked actions verified; App Store review checklist (§13.4) satisfied |
| **F12** — Polish/release | — | — | — | Full error-taxonomy sweep (§16) exercised against real 401/404/409/422/429/5xx responses; security-rules checklist (§18) audited; release build config (HTTPS enforcement, no dev-only base URLs) verified |

Stripe (F7) is sequenced after Checkout (F6) and before Booking (F8) because Booking has literally
nothing to display until a payment has produced one — there is no way to build/test Booking Detail
meaningfully without a working payment path first. Contracts (F10) is sequenced late because it depends
on Properties (F9) existing (a Contract is requested against a Property) and its own billing step reuses
patterns already proven in F7. Account deletion (F11) is sequenced last among features because
meaningfully testing its `202` deferred path requires Bookings/Contracts/Payments to already exist as
real blocking obligations.

---

## 22. API ↔ screen matrix

| Screen / Feature | Endpoint(s) | Method | Auth | Success | Key errors | Flutter state owner |
|---|---|---|---|---|---|---|
| Splash | `/v1/profile` or `/v1/auth/refresh` | GET / POST | Bearer / refresh token | 200 | 401→refresh, 422 | `AuthNotifier` |
| Register | `/v1/auth/register` | POST | none | 201 | 422 | `AuthRepository` (screen-local form state) |
| Verify Phone OTP | `/v1/auth/verify-phone`, `/v1/auth/resend-otp` | POST | none | 200 | 422 | screen-local |
| Login — Enter Phone | `/v1/auth/login/request-otp` | POST | none | 200 always | 422 (malformed only) | screen-local |
| Login — Verify OTP | `/v1/auth/login/verify-otp`, `/v1/auth/login/resend-otp` | POST | none | 200 | 422 | `AuthNotifier` |
| Forgot Password | `/v1/auth/forgot-password` | POST | none | 200 always | 422 (malformed only) | screen-local |
| Verify Reset OTP | `/v1/auth/verify-password-reset-otp` | POST | none | 200 | 422 | screen-local |
| Reset Password | `/v1/auth/reset-password` | POST | none | 200 | 422 | screen-local |
| (any authenticated screen) | `/v1/auth/refresh` | POST | refresh token | 200 | 422, 429 | `AuthInterceptor` |
| Logout | `/v1/auth/logout`, `/v1/auth/logout-all` | POST | Bearer | 200 | 401 | `AuthNotifier` |
| Home | `/v1/service-categories` | GET | none | 200 | 5xx | `ServiceCatalogRepository` |
| Category Services | `/v1/service-categories/{category}/services` | GET | none | 200 | 404 | `ServiceCatalogRepository` |
| Service Detail | `/v1/services/{service}` | GET | none | 200 | 404 | `ServiceCatalogRepository` |
| Cart | `/v1/cart`, `/v1/cart/items`, `/v1/cart/items/{item}` | GET/POST/PATCH/DELETE | Bearer | 200/201 | 401, 404, 422 | `CartNotifier` |
| Checkout Location | `/v1/checkout`, `/v1/checkout/location` | GET/PUT | Bearer | 200 | 401, 404, 422 | `CheckoutNotifier` |
| Slot Selection | `/v1/checkout/appointment-slots`, `/v1/checkout/appointment-hold` | GET/POST/DELETE | Bearer | 200/201 | 401, 404, 422 | `CheckoutNotifier` |
| Checkout Review | `/v1/checkout` | GET | Bearer | 200 | 401 | `CheckoutNotifier` |
| Payment | `/v1/payments` | POST | Bearer + Idempotency-Key | 201/200 | 401, 404, 409, 422 | `PaymentNotifier` |
| Payment Processing/Result | `/v1/payments/{payment}` | GET | Bearer | 200 | 401, 404 | `PaymentNotifier` |
| Booking List | `/v1/bookings` | GET | Bearer | 200 | 401 | fetch-on-build |
| Booking Detail | `/v1/bookings/{booking}`, `/v1/bookings/{booking}/cancel` | GET/POST | Bearer | 200 | 401, 404, 409 | fetch-on-build |
| Contracts List | `/v1/contracts` | GET | Bearer | 200 | 401 | fetch-on-build |
| Contract Detail | `/v1/contracts/{contract}` | GET | Bearer | 200 | 401, 404 | fetch-on-build |
| Request Contract | `/v1/contracts/requests` | POST | Bearer | 201 | 401, 404, 409, 422 | `ContractRepository` |
| Accept Contract | `/v1/contracts/{contract}/accept` | POST | Bearer | 200 | 401, 404, 409 | `ContractRepository` |
| Contract Billing Checkout | `/v1/contracts/{contract}/billing/checkout` | POST | Bearer | 200 | 401, 404, 409 | `ContractRepository` |
| Contract Service Booking | `/v1/contracts/{contract}/services/{contractItem}/book` | POST | Bearer | 201 | 401, 404, 409, 422 | `ContractRepository` |
| Properties List | `/v1/properties` | GET | Bearer | 200 | 401 | fetch-on-build |
| Add Property | `/v1/properties` | POST | Bearer | 201 | 401, 422 | `PropertyRepository` |
| Property Detail | `/v1/properties/{property}` | GET | Bearer | 200 | 401, 404 | fetch-on-build |
| Edit Property | `/v1/properties/{property}` | PATCH | Bearer | 200 | 401, 404, 409, 422 | `PropertyRepository` |
| Archive Property | `/v1/properties/{property}` | DELETE | Bearer | 200 always | 401, 404 | `PropertyRepository` |
| Profile | `/v1/profile` | GET | Bearer | 200 | 401 | `ProfileNotifier` |
| Edit Profile | `/v1/profile` | PATCH | Bearer | 200 | 401, 422 | `ProfileNotifier` |
| Change Password | `/v1/auth/change-password` | POST | Bearer | 200 | 401, 422 | `AuthRepository` |
| Change Phone Number | `/v1/auth/change-phone-number` | POST | Bearer | 200 | 401, 422 | `AuthRepository` |
| OTP Verify (phone change) | `/v1/auth/verify-phone-number-change-otp`, `/v1/auth/resend-phone-number-change-otp` | POST | Bearer | 200 | 401, 422 | `AuthRepository` |
| Delete Account | `/v1/auth/account` | DELETE | Bearer | 200 or 202 | 401, 409, 422 | `AccountDeletionRepository` + `AuthNotifier` |
| Pending Account Deletion Status | `/v1/auth/account-deletion` | GET | Bearer | 200 | 401 | `AccountDeletionRepository` |
| Reference data (shared: Register/Edit Profile/Add Property/Checkout Location) | `/v1/reference-data/registration` | GET | none | 200 | 5xx | `ProfileRepository` (cached in memory per session) |

---

## 23. Backend gaps that block Flutter

| Item | Classification | Notes |
|---|---|---|
| Any customer-facing endpoint documented in §1.A/B | **NONE** | Every screen in §5 is backed by a real, tested, currently-implemented endpoint. Flutter integration can begin immediately. |
| Stripe account/keys (`STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET`, and the separate `STRIPE_CONTRACT_BILLING_WEBHOOK_SECRET`) | **BLOCKER for F7/F10 end-to-end testing, not for F1–F6 development** | Confirmed empty in `.env`/`.env.example` by design — this is documented, intentional backend state, not a bug. `StripePaymentGateway` fails closed (never fabricates success) when unconfigured. Flutter's Payment/Contract Billing screens can be fully built and unit/widget-tested against the fake/mocked shapes documented here, but real end-to-end Stripe testing (PaymentSheet, Checkout web view, webhook-driven Booking/Contract activation) requires a real or Stripe-test-mode set of keys to be configured on the backend first. This is an ops/config task, not a code change — flag it early so it's ready before F7 needs it. |
| Apple Pay Merchant ID / entitlement / Stripe Dashboard registration | **CAN WAIT** | Backend architecture already supports it with zero further backend change (`automatic_payment_methods.enabled = true` is already set). Everything outstanding is Apple Developer/Stripe Dashboard/Flutter-project configuration — see `docs/api-contracts/apple-pay-future-checklist.md` for the exact checklist. Not required for initial release; card payments work fully without it. |
| `database/phase11_contract_billing_migration.sql` "has not yet been applied to any environment" (per `docs/api-contracts/contracts-v1.md`) | **SHOULD FIX (ops task, not a Flutter blocker)** | The consolidated `database/blue_v1_schema.sql` already contains every Phase 11 table (`service_contract_billing_statuses`, `service_contract_billings`, `service_contract_billing_webhook_events`) and both local dev and the automated test suite build from that consolidated file — so Contract billing endpoints work correctly in every environment Flutter will develop/test against today. The note in the contract doc refers to a real deployed environment (e.g. a persistent staging/production database created before Phase 11 existed) that hasn't had the incremental migration run against it yet. Confirm with the backend team that any environment Flutter will point at for QA/staging has this migration applied before F10 (Contracts) QA begins — this is a deployment checklist item, not something Flutter code can work around. |
| Technician-facing / customer-visible technician identity | **CAN WAIT (explicitly out of scope by design, not a gap)** | No customer-facing endpoint exposes technician name/contact/live location, and none is planned in this backend version — `docs/api-contracts/bookings-v1.md` documents this as a deliberate, not-yet-decided future scope item. No Flutter screen in this blueprint depends on it. |
| Booking reschedule | **CAN WAIT (not implemented, not required for V1)** | No reschedule endpoint exists. The supported customer path today is cancel (§9) + create a new order through Cart/Checkout. Confirmed intentional via `docs/api-contracts/bookings-v1.md`'s explicit "Not implemented" list. |
| Cancel a pending Contract request | **CAN WAIT** | No customer-facing "withdraw my contract request" endpoint exists for `REQUESTED`/`APPROVED`/`PENDING_CUSTOMER_ACCEPTANCE` states — only Admin can cancel a Contract (`POST /v1/admin/contracts/{contract}/cancel`, not customer-reachable). Not required for V1 per the current contract doc; Flutter should not offer a "withdraw request" action on Contract Detail before that Contract reaches `PENDING_PAYMENT` or later (where none exists either — cancellation of an active/pending contract is currently Admin-only end to end). Worth flagging to product as a possible V1.1 gap, not a blocker for initial release. |
| `checkout_snapshot` PII retention after account deletion (§13.5) | **CAN WAIT (disclosed, non-blocking for Flutter)** | Documented, deliberate backend limitation, never exposed through any customer-facing read path. Does not affect any Flutter screen or flow in this blueprint. |

**Overall conclusion: there is no backend blocker to beginning Flutter development today (F1–F6
immediately; F7/F10 need Stripe keys configured before their end-to-end testing milestones, which is an
operational task the backend/DevOps side should schedule in parallel with early Flutter work, not a code
gap).**
