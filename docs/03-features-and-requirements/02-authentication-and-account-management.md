# Authentication & Account Management

## Purpose

This document defines how customers create accounts, verify their phone numbers, log in, and manage their personal information.

This module is required in Version 1 because customers must have verified accounts before booking and paying for services.

---

## Version 1 Scope

In Version 1, this module includes:

* Customer account registration
* Phone number verification using OTP
* Customer login
* Customer logout
* Profile information update
* Basic password management
* Password recovery (forgot password) using OTP

---

## Account Registration Flow

The customer registration flow is:

1. The customer opens the website or mobile application.
2. The customer chooses to create a new account.
3. The customer enters the required registration data.
4. The system sends an OTP code to the customer’s phone number.
5. The customer enters the OTP code.
6. The system verifies the OTP.
7. If the OTP is correct, the account is activated.
8. The customer can log in and use the system.

---

## Required Registration Data

The required data is documented in:

**Customer Registration Data**

The main required fields are:

* Full Name
* Phone Number
* Email Address
* Password
* City
* Area
* Customer Type
* Preferred Service Interest (at least one)

---

## OTP Verification

Phone number verification is required during registration.

The customer cannot fully use the account before verifying the phone number using OTP.

Version 1 OTP policy:

* The OTP code is 6 numeric digits.
* The OTP code expires 5 minutes after it is issued.
* The customer has a maximum of 5 failed verification attempts before the code is invalidated.
* The customer must wait 60 seconds before requesting another OTP for the same purpose.
* Issuing a new OTP invalidates the previous active OTP for the same customer and purpose.
* The OTP is stored only as a secure hash; the raw code is never stored, logged, or returned in an API response.

Possible OTP cases:

* OTP sent successfully
* OTP verified successfully
* OTP is incorrect
* OTP is expired
* Customer requests a new OTP

---

## Login

The customer can log in using:

* Phone Number
* Password

The system should allow login only for verified, active accounts.

A successful login creates a session for the requesting mobile client (iOS or Android) and
returns a short-lived access token plus a refresh token. The customer stays signed in across
app restarts: the application renews the access token automatically in the background using
the refresh token, so the customer is not asked to log in again or complete OTP on every app
open. Re-authentication with phone number and password is only required once the underlying
session has expired or been revoked (for example, by logout).

The full technical design — token types, lifetimes, and renewal/revocation rules — is defined
in `docs/06-technical-system-design/02-authentication-session-and-token-design.md`.

---

## Logout

The customer can log out from the website or mobile application.

---

## Profile Management

The customer can update basic personal information, such as:

* Full Name
* City
* Area
* Email Address
* Preferred Service Interest

The phone number should not be changed directly without OTP verification.

---

## Password Management

In Version 1, the system requires passwords to meet the following minimum policy:

* Minimum 8 characters
* At least one letter
* At least one number
* Common or previously compromised passwords are rejected
* Only a secure password hash is stored; the plaintext password is never stored or logged

### Password Recovery (Forgot Password)

Password recovery is included in Version 1. The customer can regain access to their account using an OTP-based password reset flow:

1. The customer requests a password reset for their phone number.
2. The system sends an OTP code to the customer's registered phone number.
3. The customer enters the OTP code to verify the password-reset request.
4. After successful OTP verification, the customer sets a new password.
5. The new password must meet the same password policy as registration.

Future versions may include:

* Change password from profile settings

---

## Version 1 Rules

* Phone number verification using OTP is required.
* The customer must have a verified account before booking a service.
* Login should be done using phone number and password.
* The system should keep registration simple.
* Property details should not be collected during registration.
* Property details will be entered during the booking process only.
* Password recovery uses OTP verification and is included in Version 1.

---

## Notes

* This module is included in Version 1.
* OTP is required for account activation.
* Email is required at registration and must be unique.
* Phone number is the main customer contact method.
* More advanced account security can be added in future versions.
