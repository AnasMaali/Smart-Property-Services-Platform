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
* Password
* City
* Area
* Customer Type
* Preferred Service Interest

---

## OTP Verification

Phone number verification is required during registration.

The customer cannot fully use the account before verifying the phone number using OTP.

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

The system should allow login only for verified accounts.

---

## Logout

The customer can log out from the website or mobile application.

---

## Profile Management

The customer can update basic personal information, such as:

* Full Name
* City
* Area
* Email Address, if added
* Preferred Service Interest

The phone number should not be changed directly without OTP verification.

---

## Password Management

In Version 1, the system should support basic password protection.

Future versions may include:

* Forgot password
* Reset password using OTP
* Change password from profile settings

---

## Version 1 Rules

* Phone number verification using OTP is required.
* The customer must have a verified account before booking a service.
* Login should be done using phone number and password.
* The system should keep registration simple.
* Property details should not be collected during registration.
* Property details will be entered during the booking process only.

---

## Notes

* This module is included in Version 1.
* OTP is required for account activation.
* Email is optional.
* Phone number is the main customer contact method.
* More advanced account security can be added in future versions.
