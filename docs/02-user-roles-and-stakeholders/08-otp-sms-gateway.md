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

The OTP / SMS Gateway is an external system used to verify the customer’s phone number during account registration.

When a new customer creates an account, the system sends a one-time password (OTP) to the customer’s phone number. The customer must enter the correct OTP to complete the phone verification process.

This helps make sure that the phone number belongs to the customer and reduces fake or incorrect accounts.

---

## Main Purpose

The main purpose of the OTP / SMS Gateway is to provide phone number verification for customer accounts.

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

## System Responsibilities

The system should:

* Request OTP verification during account registration.
* Send the customer phone number to the OTP / SMS Gateway.
* Allow the customer to enter the OTP.
* Verify the entered OTP.
* Mark the phone number as verified after successful OTP validation.
* Prevent unverified accounts from completing the registration process.
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

* Be valid for a limited time.
* Be used only once.
* Expire after several failed attempts.
* Not be stored as plain text if stored temporarily.
* Be connected to the customer’s phone number.
* Be required before activating the customer account.

---

## Version 1 Rule

In Version 1, phone number verification using OTP is required when creating a customer account.

The customer should not be able to fully use the account before verifying the phone number.

---

## Future Notes

In future versions, OTP verification may also be used for:

* Password reset.
* Login verification.
* Payment confirmation.
* Changing phone number.
* High-risk account actions.

---

## Notes

* The OTP / SMS Gateway is an external system.
* It is not a human user.
* It is included in Version 1.
* It is required for customer account verification.
* It improves account security and customer contact reliability.
