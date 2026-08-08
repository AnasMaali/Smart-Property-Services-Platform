# BLUE V1 Authentication — Postman Collection

Companion Postman assets for `docs/api-contracts/authentication-v1.md`. Import both files below
into Postman to exercise every implemented BLUE V1 customer authentication endpoint.

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
