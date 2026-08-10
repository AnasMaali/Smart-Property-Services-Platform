# BLUE V1 Authentication — Postman Collection

Companion Postman assets for `docs/api-contracts/authentication-v1.md`. Import both files below
into Postman to exercise every implemented BLUE V1 customer authentication endpoint.

## Cart collection

`BLUE-V1-Cart.postman_collection.json` is a separate collection covering the Phase 4 Cart endpoints
(companion to `docs/api-contracts/cart-v1.md`): Get Cart, Add Cart Item, Update Cart Item, Remove
Cart Item, Clear Cart. Import it alongside the two files below and the same `BLUE V1 Local`
environment. Every Cart request requires `access_token` (run Login from this collection first).
`service_uuid` must be set manually to a real, active, `CART_ELIGIBLE` service uuid seeded in the
target environment — it is not provided by the environment file since it is seed-specific, not a
Cart concept. `cart_item_uuid` is captured automatically by the Add Cart Item request's test script.

## Checkout collection

`BLUE-V1-Checkout.postman_collection.json` covers the Phase 5 Checkout & Scheduling endpoints
(companion to `docs/api-contracts/checkout-v1.md`): Get Checkout, Save Checkout Location, List
Appointment Slots, Create Appointment Hold, Release Appointment Hold. Import it alongside the Cart
collection and the same `BLUE V1 Local` environment — every request needs `access_token` plus an
ACTIVE cart with at least one item (run Cart's Add Cart Item first). `area_id` must be set manually
to a real, active area id (see `GET /v1/reference-data/registration`); `appointment_slot_uuid` is
captured automatically by List Appointment Slots (or set manually to a real, active, future
`appointment_slots` row); `appointment_hold_uuid` is captured automatically by Create Appointment
Hold. Payment is out of scope for this collection — there is no payment/booking request here.

## Payment collection

`BLUE-V1-Payment.postman_collection.json` covers the Phase 6A Payment Core endpoints (companion to
`docs/api-contracts/payments-v1.md`): Create Payment, Get Payment. Import it alongside the Checkout
collection and the same `BLUE V1 Local` environment — Create Payment requires the caller's ACTIVE
cart to already be `ready_for_payment` (run the Checkout collection's Save Checkout Location and
Create Appointment Hold first). The request body carries no amount/currency/status field — the
server derives everything from the cart; sending one of those fields is rejected with 422. The
`Idempotency-Key` header defaults to a fresh `{{$guid}}` per send — replace it with a fixed value
across two sends to observe idempotent reuse (the same payment is returned, HTTP 200 instead of
201, and no second provider operation happens). `payment_uuid` is captured automatically by Create
Payment's test script. Stripe is the approved BLUE V1 provider, but no Stripe account/keys exist yet
— none are required to use this collection, since local/testing environments bind a deterministic
fake gateway instead; the collection intentionally does not include a webhook request, since a
valid Stripe signature cannot be produced without the real `STRIPE_WEBHOOK_SECRET`.

## 1. Start the backend

```
cd backend
php artisan serve
```

This serves the API at `http://127.0.0.1:8000` by default, matching the `base_url` in the local
environment file.

## 2. Import into Postman

Import both files (File → Import, or drag-and-drop):

- `BLUE-V1-Authentication.postman_collection.json`
- `BLUE-V1-Local.postman_environment.json`

## 3. Select the environment

In the top-right environment selector in Postman, choose **BLUE V1 Local**.

## 4. Recommended execution order — new customer

Run requests in this order for a fresh customer sign-up:

1. **01 - Registration → Register** — creates the account and issues a phone-verification OTP.
2. **02 - Phone Verification → Verify Phone OTP** — activates the account.
   - `otp_code` is **not** returned by any API response (see note below). Set it manually in the
     environment before running this request.
3. **03 - Login & Session → Login** — authenticates and saves `access_token`, `refresh_token`,
   and `session_uuid` into the environment automatically.
4. **03 - Login & Session → Refresh Access Token** — rotates `access_token` and `refresh_token`.
5. **03 - Login & Session → Logout** — revokes the current session.

`city_id`, `area_id`, `property_relationship_type_id`, and `service_interests` in the Register
request body use placeholder IDs (`1`, `[1, 2]`). Adjust these to match whatever reference data
exists in your local database — they are not provided by the environment file since they are
environment-specific seed IDs, not authentication concepts.

## 5. Password recovery flow

1. **04 - Password Recovery → Forgot Password** — always returns a generic success message,
   whether or not the phone number is registered (see contract doc for why). No OTP is returned.
2. Obtain the OTP code through the future SMS channel / a development-only mechanism (see note
   below), and set it as the `otp_code` environment variable.
3. **04 - Password Recovery → Verify Password Reset OTP** — saves `reset_token` into the
   environment automatically.
4. **04 - Password Recovery → Reset Password** — sets the new password and revokes every existing
   session for the account.
5. **03 - Login & Session → Login** again with the new password.

## 6. Phone-change flow

Requires a valid `access_token` (run Login first):

1. **06 - Phone Number Change → Request Phone Number Change** — saves `phone_change_otp_uuid`
   and `new_phone_number` into the environment automatically.
2. Obtain the OTP code the same way as above and set `otp_code`.
3. **06 - Phone Number Change → Verify Phone Number Change OTP** — updates the account's phone
   number and revokes every other session for the user.
4. **06 - Phone Number Change → Resend Phone Number Change OTP** is available at any point after
   step 1 if the code needs to be reissued (60-second cooldown applies).

## A note on OTP codes

**SMS delivery is not implemented yet.** Every OTP-issuing endpoint in this collection generates
and hashes a 6-digit code server-side, but the raw code is never included in any API response —
this is intentional and matches production behavior, not a gap in the collection.

**The raw OTP cannot be retrieved from the database.** BLUE stores only a one-way hash of each
OTP (`otp_verifications.code_hash`); the plaintext code is never persisted anywhere, so there is
no table or log line to read it back from. Do **not** attempt to invent, guess, weaken this
hash-only storage, or expose OTP codes through an API/debug endpoint or Postman scripts.

For local development and testing, `otp_code` must be set by hand in the environment after
obtaining the raw OTP through the future SMS delivery mechanism (or an equivalent development
delivery mechanism, once one exists) — not by reading it out of the database.

## Folder reference

| Folder | Endpoints |
|---|---|
| 01 - Registration | Register |
| 02 - Phone Verification | Verify Phone OTP, Resend Phone OTP |
| 03 - Login & Session | Login, Refresh Access Token, Logout, Logout All Sessions |
| 04 - Password Recovery | Forgot Password, Verify Password Reset OTP, Reset Password |
| 05 - Password Management | Change Password |
| 06 - Phone Number Change | Request Phone Number Change, Verify Phone Number Change OTP, Resend Phone Number Change OTP |

See `docs/api-contracts/authentication-v1.md` for the full request/response contract, validation
rules, and error cases for every endpoint listed above.
