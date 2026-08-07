# External Integration Requirements — Version 1

## 1. Purpose

This document defines the external integration requirements for Version 1 of the Smart Property Services Platform.

Version 1 requires integration with the following external systems:

* Payment Gateway
* OTP / SMS Gateway

All external integrations shall be handled through the Backend API.

The mobile application, portfolio website, and Admin Management Interface shall not communicate directly with external service providers using private credentials.

---

## 2. General Integration Requirements

### EIR-GEN-01

The Backend API shall manage all communication with external service providers.

### EIR-GEN-02

External service secret keys and credentials shall be stored securely on the backend.

### EIR-GEN-03

External service credentials shall not be included in:

* Mobile application source code
* Public website source code
* Public GitHub repositories
* Application logs
* Error messages

### EIR-GEN-04

The system shall use the official integration methods and APIs provided by each external service provider.

### EIR-GEN-05

The system shall separate testing credentials from production credentials.

### EIR-GEN-06

Production operations shall not use testing credentials.

### EIR-GEN-07

The system shall use encrypted HTTPS communication when connecting to external services.

### EIR-GEN-08

The system shall safely handle:

* Successful responses
* Failed responses
* Delayed responses
* Repeated responses
* Unexpected responses
* Temporary external service unavailability

### EIR-GEN-09

An external integration failure shall not corrupt existing customer, cart, booking, or payment data.

### EIR-GEN-10

The system shall display clear customer messages when an external service is temporarily unavailable.

### EIR-GEN-11

Technical error details shall be recorded in secure logs and shall not be displayed to customers.

### EIR-GEN-12

The system shall assign a unique internal reference to each external integration request when needed.

---

## 3. Payment Gateway Integration

### Integration Purpose

The Payment Gateway processes customer payments for the total amount of the selected cart.

The customer pays once for all services inside the cart.

A paid Booking shall be created only after successful payment confirmation.

---

## 4. Payment Request Requirements

### EIR-PAY-01

The Backend shall create the payment request after the customer confirms:

* Selected services
* Service options
* Property information
* Booking date and time
* Total cart amount

### EIR-PAY-02

Before sending the payment request, the Backend shall verify:

* Customer identity
* Active services
* Current service prices
* Selected service options
* Cart total
* Appointment availability

### EIR-PAY-03

The Backend shall calculate the trusted final payment amount.

### EIR-PAY-04

The system shall not trust a total amount sent only by the mobile application.

### EIR-PAY-05

The payment request may include:

* Internal payment reference
* Customer reference
* Cart or checkout reference
* Total amount
* Currency
* Payment description
* Success return information
* Failure return information
* Payment notification information

### EIR-PAY-06

The system shall use one payment request for the full cart amount.

### EIR-PAY-07

The system shall prevent repeated clicks from creating duplicate payment requests when possible.

---

## 5. Payment Processing Requirements

### EIR-PAY-08

The Payment Gateway shall process the customer payment through its secure payment interface.

### EIR-PAY-09

The platform shall not store:

* Complete card numbers
* Card security codes
* Payment account passwords
* Sensitive payment authentication data

### EIR-PAY-10

The Payment Gateway may return:

* Payment status
* Payment reference number
* Paid amount
* Currency
* Payment date and time
* Failure reason or code when available

### EIR-PAY-11

Possible payment statuses shall include:

* Pending
* Successful
* Failed
* Cancelled
* Refunded

### EIR-PAY-12

The system shall initially store the payment as Pending while waiting for a final payment result.

---

## 6. Payment Confirmation Requirements

### EIR-PAY-13

The Backend shall verify the payment result using trusted information received from the Payment Gateway.

### EIR-PAY-14

The system shall not create a paid Booking based only on a success message displayed in the mobile application.

### EIR-PAY-15

The system shall verify incoming payment notifications or callbacks before updating the Payment status.

### EIR-PAY-16

The system shall verify that the confirmed paid amount matches the trusted cart total.

### EIR-PAY-17

The system shall verify that the payment reference is connected to the correct Customer and checkout operation.

### EIR-PAY-18

After successful payment confirmation, the system shall:

1. Mark the Payment as Successful.
2. Store the Payment Gateway reference number.
3. Create one paid Booking.
4. Create the related Booking Items.
5. Mark the Cart as Converted to Booking.
6. Generate the basic Receipt.
7. Make the paid Booking available to the Admin.

### EIR-PAY-19

One successful payment shall create one paid Booking only.

### EIR-PAY-20

Repeated Payment Gateway notifications shall not create duplicate Bookings or Receipts.

### EIR-PAY-21

The system shall support an idempotent payment confirmation process.

---

## 7. Failed and Cancelled Payment Requirements

### EIR-PAY-22

If payment fails:

* The Payment status shall become Failed.
* A paid Booking shall not be created.
* The customer shall receive a clear failure message.
* The customer may retry the payment.
* The Cart information shall remain available.

### EIR-PAY-23

If the customer cancels the payment:

* The Payment status shall become Cancelled.
* A paid Booking shall not be created.
* The Cart shall remain available for review.
* The customer may return to the Booking review step.

### EIR-PAY-24

Payment failure messages shall not expose private payment data or internal technical information.

### EIR-PAY-25

A payment retry shall create or use a valid payment attempt without creating duplicate successful charges.

---

## 8. Delayed Payment Requirements

### EIR-PAY-26

The system shall handle delayed Payment Gateway responses.

### EIR-PAY-27

A payment shall remain Pending until a trusted final result is received or the payment attempt expires.

### EIR-PAY-28

The system shall not create a paid Booking while the Payment status is Pending.

### EIR-PAY-29

When a delayed successful confirmation is received, the system shall safely complete the Booking creation process once.

### EIR-PAY-30

The system shall record delayed, repeated, and failed payment confirmation attempts.

---

## 9. Refund Status Requirements

### EIR-PAY-31

The system shall be able to store a Refunded Payment status when the Payment Gateway confirms a refund.

### EIR-PAY-32

Only authorized Admin or financial processes shall initiate or record refund-related actions.

### EIR-PAY-33

The customer shall not directly change the Payment status to Refunded.

### EIR-PAY-34

The system shall preserve:

* Original Payment reference
* Original paid amount
* Refund status
* Refund reference when available
* Refund date and time when available

### EIR-PAY-35

Refund handling shall follow the company’s approved policy.

---

## 10. Payment Data Requirements

### EIR-PAY-36

The system shall store:

* Internal Payment ID
* Customer ID
* Booking ID when created
* Cart or checkout reference
* Payment Gateway reference number
* Total amount
* Currency
* Payment status
* Payment date and time
* Creation date
* Last update date

### EIR-PAY-37

The system shall connect the Payment record to the related Booking and Receipt.

### EIR-PAY-38

Payment records shall remain available for authorized operational review.

### EIR-PAY-39

Only authorized Admin users shall view Payment references and statuses.

---

## 11. Payment Gateway Error Handling

### EIR-PAY-40

The system shall handle:

* Gateway timeout
* Invalid gateway response
* Connection failure
* Incorrect amount
* Unknown payment reference
* Repeated notification
* Expired payment attempt

### EIR-PAY-41

The system shall record technical Payment Gateway errors in secure logs.

### EIR-PAY-42

Technical logs shall not store complete card information or other sensitive payment data.

### EIR-PAY-43

The customer shall receive a simple and understandable message when the Payment Gateway is unavailable.

---

## 12. OTP / SMS Gateway Integration

### Integration Purpose

The OTP / SMS Gateway delivers verification codes to customer phone numbers.

OTP verification is used in Version 1 for:

* New customer account registration
* Customer password reset (forgot password)
* Customer phone number change

OTP is not required during every normal login after the phone number has been verified.

---

## 13. OTP Request Requirements

### EIR-OTP-01

The Backend shall create an OTP verification request for the correct phone number and verification purpose.

### EIR-OTP-02

The OTP request shall include:

* Phone number
* Verification purpose
* Internal verification reference
* OTP expiration period
* Request date and time

### EIR-OTP-03

The system shall verify that the phone number format is valid before requesting OTP delivery.

### EIR-OTP-04

The system shall prevent excessive OTP requests for the same phone number.

### EIR-OTP-05

The system shall require a 60-second cooldown before allowing another OTP request for the same phone number and purpose.

### EIR-OTP-06

Requesting a new OTP shall invalidate the previous active OTP when appropriate.

### EIR-OTP-07

The Backend shall communicate with the OTP / SMS Gateway using official and secure integration methods.

---

## 14. OTP Delivery Requirements

### EIR-OTP-08

The OTP / SMS Gateway shall send the verification code to the requested phone number.

### EIR-OTP-09

The OTP / SMS Gateway may return:

* Delivery request reference
* Delivery status
* Provider response code
* Failure reason when available

### EIR-OTP-10

Possible OTP delivery statuses may include:

* Pending
* Sent
* Delivered
* Failed
* Expired

### EIR-OTP-11

The system shall store only the information needed to track the verification process.

### EIR-OTP-12

The raw OTP code shall never be stored, and shall not appear in Admin screens, API responses, or technical logs.

---

## 15. OTP Verification Requirements

### EIR-OTP-13

The customer shall enter the received OTP code inside the mobile application.

### EIR-OTP-14

The Backend shall verify the OTP code or provider verification result.

### EIR-OTP-15

Each OTP code shall:

* Consist of 6 numeric digits
* Be valid for 5 minutes after issuance
* Be usable once only
* Be connected to one phone number
* Be connected to one verification purpose

### EIR-OTP-16

The system shall reject:

* Incorrect OTP codes
* Expired OTP codes
* Previously used OTP codes
* OTP codes connected to another phone number
* OTP codes connected to another verification operation

### EIR-OTP-17

The system shall reject verification after a maximum of 5 incorrect OTP attempts for the same OTP code.

### EIR-OTP-18

After successful account registration verification, the system shall:

1. Mark the phone number as verified.
2. Activate the Customer account.
3. Prevent reuse of the OTP.
4. Allow the Customer to access protected application functions.

### EIR-OTP-19

After successful phone number change verification, the system shall:

1. Verify the new phone number.
2. Replace the previous account phone number.
3. Preserve the Customer account and historical Booking data.
4. Prevent reuse of the OTP.

### EIR-OTP-19A

After successful password-reset OTP verification, the system shall:

1. Allow the customer to set a new password.
2. Store only the new password hash.
3. Prevent reuse of the OTP.
4. Not change the customer's phone number, email address, or any other account data.

---

## 16. Failed OTP Delivery Requirements

### EIR-OTP-20

If OTP delivery fails:

* The account shall not be activated.
* The phone number change shall not be completed.
* The customer shall receive a clear error message.
* The customer may request another OTP when permitted.

### EIR-OTP-21

OTP delivery failure shall not delete or corrupt the Customer’s entered registration information.

### EIR-OTP-22

The system shall record failed OTP delivery attempts in secure technical logs.

### EIR-OTP-23

The system shall handle temporary OTP / SMS Gateway unavailability without damaging Customer data.

---

## 17. OTP Security Requirements

### EIR-OTP-24

OTP codes shall be stored only as a secure hash. The raw OTP code shall never be stored, regardless of whether storage is temporary.

### EIR-OTP-25

OTP codes shall not be included in:

* Public application logs
* Admin screens
* Error messages
* Public source code

### EIR-OTP-26

The system shall limit repeated OTP requests and verification attempts.

### EIR-OTP-27

The system shall not activate an account based only on a success message from the mobile application.

### EIR-OTP-28

OTP verification shall be confirmed by the Backend or trusted provider verification result.

### EIR-OTP-29

OTP Gateway credentials shall be stored securely on the Backend.

---

## 18. OTP Data Requirements

### EIR-OTP-30

OTP verification data may include:

* Verification ID
* Phone number
* Verification purpose
* Provider reference
* OTP hash
* Expiration date and time
* Verification status
* Failed attempt count
* Creation date and time
* Verification date and time

### EIR-OTP-31

Possible OTP verification statuses may include:

* Pending
* Verified
* Failed
* Expired

### EIR-OTP-32

Expired and completed OTP records may be removed or archived according to the company’s data retention policy.

---

## 19. Integration Logging Requirements

### EIR-LOG-01

The system shall record important external integration events.

### EIR-LOG-02

Payment integration logs may include:

* Internal Payment reference
* Provider reference
* Response status
* Error code
* Date and time

### EIR-LOG-03

OTP integration logs may include:

* Internal verification reference
* Provider reference
* Delivery status
* Error code
* Date and time

### EIR-LOG-04

Integration logs shall not contain:

* Readable passwords
* Readable OTP codes
* Authentication tokens
* Complete card numbers
* Card security codes

### EIR-LOG-05

Integration logs shall be accessible only to authorized technical personnel.

---

## 20. Integration Testing Requirements

### EIR-TST-01

Payment Gateway integration shall be tested using the provider’s approved testing environment.

### EIR-TST-02

OTP / SMS Gateway integration shall be tested using the provider’s approved testing process.

### EIR-TST-03

Testing shall cover successful, failed, cancelled, delayed, and repeated Payment Gateway responses.

### EIR-TST-04

Testing shall cover:

* Successful OTP delivery
* Failed OTP delivery
* Incorrect OTP
* Expired OTP
* Repeated OTP request
* Repeated incorrect verification attempts

### EIR-TST-05

Production credentials shall not be used in development or automated testing environments.

### EIR-TST-06

The system shall be tested to confirm that repeated Payment notifications do not create duplicate Bookings.

### EIR-TST-07

The system shall be tested to confirm that an account is not activated without verified OTP confirmation.

### EIR-TST-08

Critical external integration issues shall be resolved before the Version 1 production release.

---

## 21. Important Version 1 Integration Rules

* All external integrations shall be managed through the Backend API.
* External secret keys shall not be included in the mobile application or public website.
* The Payment Gateway shall process one payment for the full cart amount.
* A paid Booking shall be created only after trusted payment confirmation.
* One successful payment shall create one paid Booking only.
* Failed, cancelled, or Pending payments shall not create active paid Bookings.
* The platform shall not store complete payment card information.
* OTP shall be required for account registration, password reset, and phone number changes.
* OTP shall not be required during every normal login.
* An account shall not be activated before successful OTP verification.
* External integration failures shall not corrupt system data.
