# OTP / SMS Gateway

## Role Name

**OTP / SMS Gateway**

## Arabic Name

**بوابة الرسائل / التحقق برمز OTP**

---

## Role Type

**External System / Non-Human Stakeholder**

---

## Role Status

**Included in Version 1**

---

## Description

The OTP / SMS Gateway is an external system used to verify the customer’s phone number during account registration, to verify the customer’s identity during password recovery, and to verify a new phone number during a phone number change.

When a new customer creates an account, the system sends a one-time password (OTP) to the customer’s phone number. The customer must enter the correct OTP to complete the phone verification process.

When a customer requests a password reset (forgot password), the system sends an OTP to the customer's registered phone number. The customer must enter the correct OTP before being allowed to set a new password.

When a customer requests a phone number change, the system sends an OTP to the new phone number. The customer must enter the correct OTP before the account phone number is updated.

This helps make sure that the phone number belongs to the customer and reduces fake or incorrect accounts.

---

## Main Purpose

The main purpose of the OTP / SMS Gateway is to provide phone number verification for customer accounts, to verify customer identity during password recovery, and to verify new phone numbers during a phone number change.

This improves account security and ensures that the platform can contact the customer using a valid phone number.

---

## Version 1 OTP Flow

The Version 1 OTP flow is:

1. The customer starts creating a new account.
2. The customer enters personal information and phone number.
3. The system sends the phone number to the OTP / SMS Gateway.
4. The OTP / SMS Gateway sends a verification code to the customer’s phone.
5. The customer enters the OTP inside the application or website.
6. The system checks if the OTP is correct.
7. If the OTP is correct, the phone number is verified.
8. The customer account is created or activated.
9. If the OTP is incorrect or expired, the customer must request a new code or try again.

---

## Version 1 Password Reset OTP Flow

The Version 1 password reset (forgot password) OTP flow is:

1. The customer requests a password reset using their registered phone number.
2. The system sends a verification code to the customer's phone through the OTP / SMS Gateway.
3. The customer enters the OTP inside the application or website.
4. The system checks if the OTP is correct.
5. If the OTP is correct, the customer is allowed to set a new password.
6. If the OTP is incorrect or expired, the customer must request a new code or try again.

---

## Version 1 Phone Number Change OTP Flow

The Version 1 phone number change OTP flow is:

1. The customer requests to change their account phone number and enters the new phone number.
2. The system sends a verification code to the new phone number through the OTP / SMS Gateway.
3. The customer enters the OTP inside the application or website.
4. The system checks if the OTP is correct.
5. If the OTP is correct, the new phone number is verified and replaces the previous account phone number.
6. If the OTP is incorrect or expired, the customer must request a new code or try again.

---

## System Responsibilities

The system should:

* Request OTP verification during account registration.
* Request OTP verification during password reset (forgot password).
* Request OTP verification during a phone number change.
* Send the customer phone number to the OTP / SMS Gateway.
* Allow the customer to enter the OTP.
* Verify the entered OTP.
* Mark the phone number as verified after successful OTP validation.
* Prevent unverified accounts from completing the registration process.
* Prevent a password reset from completing without successful OTP verification.
* Prevent a phone number change from completing without successful OTP verification of the new number.
* Allow the customer to request a new OTP if needed.
* Handle expired or incorrect OTP attempts.

---

## Possible OTP Statuses

Possible OTP statuses may include:

* Pending
* Sent
* Verified
* Failed
* Expired

---

## Security Notes

The OTP should:

* Consist of 6 numeric digits.
* Be valid for 5 minutes after it is issued.
* Be used only once.
* Be rejected after 5 failed verification attempts.
* Require a 60-second cooldown before a new OTP can be requested for the same phone number and purpose.
* Be invalidated automatically whenever a new OTP is issued for the same customer and purpose.
* Never be stored as plain text — only a secure hash of the OTP is stored.
* Be connected to the customer’s phone number.
* Be required before activating the customer account.

---

## Version 1 Rule

In Version 1, phone number verification using OTP is required when creating a customer account.

The customer should not be able to fully use the account before verifying the phone number.

In Version 1, password recovery (forgot password) also requires OTP verification before a new password can be set.

In Version 1, changing the account phone number also requires OTP verification of the new phone number before the change is applied.

---

## Future Notes

In future versions, OTP verification may also be used for:

* Login verification.
* Payment confirmation.
* High-risk account actions.

---

## Notes

* The OTP / SMS Gateway is an external system.
* It is not a human user.
* It is included in Version 1.
* It is required for customer account verification, password recovery, and phone number change verification.
* It improves account security and customer contact reliability.
