# Security & Privacy Requirements — Version 1

## 1. Purpose

This document defines the security and privacy requirements for Version 1 of the Smart Property Services Platform.

These requirements apply to:

* Customer mobile application
* Admin Management Interface
* Backend API
* Shared database
* Portfolio website
* Payment Gateway integration
* OTP / SMS Gateway integration

---

## 2. Authentication Requirements

### SEC-AUTH-01

The system shall require customers to authenticate using a verified phone number and password.

### SEC-AUTH-02

The system shall verify the customer’s phone number using OTP during account registration.

### SEC-AUTH-03

The system shall not require OTP during every normal login after the phone number has been successfully verified.

### SEC-AUTH-04

The system shall require OTP verification when the customer changes the account phone number.

### SEC-AUTH-05

The system shall prevent an unverified customer account from fully accessing protected application functions.

### SEC-AUTH-06

The system shall display a general login error message without revealing whether the phone number or password was incorrect.

### SEC-AUTH-07

The system shall limit repeated failed login attempts to reduce unauthorized access attempts.

### SEC-AUTH-08

The system shall temporarily restrict login attempts after several consecutive failed attempts.

---

## 3. Password Security Requirements

### SEC-PWD-01

Customer and Admin passwords shall never be stored as plain text.

### SEC-PWD-02

Passwords shall be stored using a secure password hashing algorithm with an individual salt.

### SEC-PWD-03

The system shall require passwords to meet minimum security rules.

### SEC-PWD-04

Password requirements should include:

* A minimum accepted length
* At least one letter
* At least one number
* Rejection of commonly used weak passwords

### SEC-PWD-05

Password values shall not appear in application logs, error messages, or Admin screens.

### SEC-PWD-06

The system shall use protected password input fields that hide entered characters.

### SEC-PWD-07

The system shall not send customer passwords through SMS, email, or support messages.

---

## 4. OTP Security Requirements

### SEC-OTP-01

Each OTP code shall be valid for a limited period.

### SEC-OTP-02

Each OTP code shall be usable only once.

### SEC-OTP-03

A successfully verified or expired OTP code shall not be accepted again.

### SEC-OTP-04

The system shall limit the number of incorrect OTP attempts.

### SEC-OTP-05

The system shall limit how frequently a customer can request a new OTP code.

### SEC-OTP-06

Requesting a new OTP shall invalidate the previous active OTP when appropriate.

### SEC-OTP-07

OTP codes shall not be stored as readable plain text when temporary storage is required.

### SEC-OTP-08

OTP codes shall not appear in technical logs or Admin Management Interface screens.

### SEC-OTP-09

The system shall connect each OTP request to the correct phone number and verification operation.

---

## 5. Role and Access Control Requirements

### SEC-ACC-01

The system shall enforce role-based access control for Customer and Admin functions.

### SEC-ACC-02

A Customer account shall not access Admin functions or Admin data.

### SEC-ACC-03

Only authorized Admin accounts shall be able to:

* Manage services and categories
* Manage service prices
* Manage appointment availability
* View paid bookings
* Assign technicians
* Update Booking and Booking Item statuses
* View support requests
* View customer ratings and feedback

### SEC-ACC-04

Access control shall be enforced by the Backend API and shall not depend only on hidden buttons or screens.

### SEC-ACC-05

Every protected request shall verify the authenticated user’s identity and role before processing the request.

### SEC-ACC-06

Customers shall only access their own:

* Profile
* Bookings
* Receipts
* Support messages
* Ratings

### SEC-ACC-07

The system shall prevent a customer from viewing or modifying another customer’s information by changing an identifier.

### SEC-ACC-08

Technicians shall not have system accounts or direct system access in Version 1.

---

## 6. Session and Token Security Requirements

### SEC-SES-01

The system shall create a secure authenticated session after successful login.

### SEC-SES-02

Authentication tokens shall be generated securely and shall not contain readable passwords or sensitive payment information.

### SEC-SES-03

Authentication tokens shall have a limited validity period.

### SEC-SES-04

The mobile application shall store authentication tokens using secure device storage.

### SEC-SES-05

The system shall invalidate the active session when the customer logs out.

### SEC-SES-06

The system shall reject expired, invalid, or modified authentication tokens.

### SEC-SES-07

Admin sessions should use shorter inactivity periods or stronger session controls than normal customer sessions.

### SEC-SES-08

The system shall not expose authentication tokens in URLs, public logs, or error messages.

---

## 7. Admin Account Security Requirements

### SEC-ADM-01

Admin accounts shall be created or approved only through an authorized internal process.

### SEC-ADM-02

Customers shall not be able to register themselves as Admin users.

### SEC-ADM-03

Admin accounts shall use strong passwords.

### SEC-ADM-04

The system shall record important Admin actions for accountability.

### SEC-ADM-05

Recorded Admin actions shall include:

* Admin identity
* Action type
* Related record
* Action date and time

### SEC-ADM-06

The system shall restrict access to Admin records and logs to authorized personnel.

### SEC-ADM-07

An Admin shall not be able to view:

* Customer passwords
* OTP codes
* Complete payment card information

### SEC-ADM-08

The company shall disable an Admin account when the account holder is no longer authorized to manage the system.

---

## 8. Backend API Security Requirements

### SEC-API-01

The mobile application, Admin Management Interface, and portfolio website shall communicate with the shared database through the Backend API.

### SEC-API-02

The mobile application and portfolio website shall not connect directly to the production database.

### SEC-API-03

Protected API endpoints shall require authentication and authorization.

### SEC-API-04

The Backend API shall validate all incoming data before processing or storing it.

### SEC-API-05

The system shall reject requests containing:

* Invalid identifiers
* Unsupported values
* Incorrectly formatted data

### SEC-API-06

The system shall use parameterized database queries or an equivalent safe data-access method.

### SEC-API-07

The Backend API shall limit excessive requests that may indicate abuse or automated attacks.

### SEC-API-08

The system shall not return internal server details, database queries, passwords, or private configuration values in error responses.

### SEC-API-09

Public and protected API functions shall be clearly separated.

---

## 9. Input Validation Requirements

### SEC-INP-01

The system shall validate all Customer and Admin input on the backend.

### SEC-INP-02

Client-side validation shall improve usability but shall not replace backend validation.

### SEC-INP-03

The system shall validate:

* Phone numbers
* Email addresses when entered
* Passwords
* Service quantities
* Property information
* Booking dates and times
* Payment references
* Ratings
* Support messages

### SEC-INP-04

The system shall safely handle text input to reduce the risk of malicious scripts or commands.

### SEC-INP-05

The system shall enforce reasonable maximum lengths for text fields.

### SEC-INP-06

The system shall reject:

* Negative quantities
* Invalid prices
* Invalid ratings
* Unsupported Booking status values

---

## 10. Data Transmission Security Requirements

### SEC-TRN-01

All production communication between system interfaces and the Backend API shall use encrypted HTTPS connections.

### SEC-TRN-02

The system shall not send passwords, OTP codes, authentication tokens, or personal information through unencrypted connections.

### SEC-TRN-03

Communication with the Payment Gateway and OTP / SMS Gateway shall use secure connection methods supported by the provider.

### SEC-TRN-04

The production system shall use valid and maintained security certificates.

### SEC-TRN-05

The system shall reject insecure communication for protected production operations.

---

## 11. Stored Data Security Requirements

### SEC-DAT-01

The database shall be accessible only through authorized system services and authorized technical personnel.

### SEC-DAT-02

Database credentials and external service keys shall not be stored directly inside public source-code files.

### SEC-DAT-03

Secrets and environment-specific configuration values shall be stored using protected configuration or secret-management methods.

### SEC-DAT-04

Production secrets shall not be committed to the public GitHub repository.

### SEC-DAT-05

Sensitive personal data shall be protected using appropriate storage security controls.

### SEC-DAT-06

Database access shall follow the principle of granting only the permissions required for each system component.

### SEC-DAT-07

Production data shall not be copied into development or testing environments without appropriate protection.

### SEC-DAT-08

Backup files containing production data shall receive protection equivalent to the main database.

---

## 12. Payment Security Requirements

### SEC-PAY-01

Payments shall be processed through an approved external Payment Gateway.

### SEC-PAY-02

The Smart Property Services Platform shall not store:

* Complete payment card numbers
* Card security codes
* Payment account passwords

### SEC-PAY-03

The system shall store only payment information required for booking management, including:

* Payment reference number
* Payment status
* Paid amount
* Payment date and time
* Related Booking ID

### SEC-PAY-04

The system shall verify payment results using trusted information received from the Payment Gateway.

### SEC-PAY-05

The system shall not trust a payment-success message received only from the customer application.

### SEC-PAY-06

The system shall ensure that one successful payment creates only one paid Booking.

### SEC-PAY-07

The system shall prevent duplicate payment processing caused by repeated customer actions or delayed responses.

### SEC-PAY-08

Payment failure messages shall not reveal private payment details or internal technical information.

### SEC-PAY-09

Only authorized Admin users shall view payment references and payment statuses.

---

## 13. Booking Security Requirements

### SEC-BKG-01

The Backend API shall recalculate and verify service prices before starting payment.

### SEC-BKG-02

The system shall not trust total amounts calculated only by the mobile application.

### SEC-BKG-03

The system shall verify that every service in the cart is active before payment.

### SEC-BKG-04

The system shall verify that the selected appointment remains available before payment.

### SEC-BKG-05

The system shall ensure that the Booking is connected to the authenticated customer who completed the payment.

### SEC-BKG-06

Customers shall not directly modify:

* Payment status
* Booking status
* Technician assignment
* Booking Item status

### SEC-BKG-07

Only authorized Admin users shall update technician assignments and operational Booking statuses.

### SEC-BKG-08

The system shall preserve an audit record of important Booking status changes.

---

## 14. Privacy Requirements

### SEC-PRV-01

The system shall collect only customer data needed for:

* Account management
* Booking
* Payment
* Support
* Service delivery
* Approved analysis

### SEC-PRV-02

The system shall provide customers with a clear privacy notice describing how their personal data is collected and used.

### SEC-PRV-03

The customer shall be informed about required and optional registration fields.

### SEC-PRV-04

The email address shall remain optional for Customer accounts in Version 1.

### SEC-PRV-05

The system shall not request complete property information during account registration.

### SEC-PRV-06

Property information shall be collected during the booking process only.

### SEC-PRV-07

Customer personal information shall not be displayed publicly.

### SEC-PRV-08

Customer data shall be accessible only to:

* The customer
* Authorized Admin users
* Authorized technical services when required

### SEC-PRV-09

Technicians shall receive only the customer and property information needed to perform the assigned service.

### SEC-PRV-10

Customer information shall not be sold or shared for unrelated purposes without an appropriate legal and business basis.

### SEC-PRV-11

Customer data used for reports and analysis should be aggregated or anonymized when individual identification is not required.

### SEC-PRV-12

The company shall define how long customer, booking, support, payment, and technical log data must be retained.

---

## 15. Support and Communication Privacy

### SEC-SUP-01

Support messages shall be accessible only to the customer and authorized support or Admin users.

### SEC-SUP-02

The system shall not ask customers to submit passwords, OTP codes, or complete payment card details through support messages.

### SEC-SUP-03

Support messages shall not expose another customer’s booking or personal information.

### SEC-SUP-04

Phone and WhatsApp support links shall use contact details approved by the company.

### SEC-SUP-05

Admin users shall verify the customer’s identity before discussing sensitive booking or account details through support.

---

## 16. Logging and Audit Security Requirements

### SEC-LOG-01

The system shall record important security and operational events.

### SEC-LOG-02

Logged events should include:

* Repeated failed login attempts
* OTP sending failures
* Payment integration failures
* Booking creation failures
* Service price changes
* Service activation or deactivation
* Technician assignment
* Booking status changes

### SEC-LOG-03

Logs shall include the date and time of the event.

### SEC-LOG-04

Admin audit records shall include the identity of the Admin who performed the action.

### SEC-LOG-05

Logs shall not contain:

* Readable passwords
* OTP codes
* Authentication tokens
* Complete card data
* Unnecessary personal information

### SEC-LOG-06

Technical and security logs shall be available only to authorized personnel.

### SEC-LOG-07

Log records shall be protected from unauthorized editing or deletion.

---

## 17. Mobile Application Security Requirements

### SEC-MOB-01

The mobile application shall not contain:

* Production database credentials
* Payment Gateway secret keys
* OTP Gateway secret keys

### SEC-MOB-02

Sensitive operations shall be performed and verified by the Backend API.

### SEC-MOB-03

Authentication tokens shall be stored using secure mobile-device storage.

### SEC-MOB-04

Sensitive information shall not remain in application logs or debugging output in the production release.

### SEC-MOB-05

The production application shall disable unnecessary debugging features.

### SEC-MOB-06

The application shall validate the identity of the production Backend API before exchanging protected information.

### SEC-MOB-07

The application shall clear protected local session data when the customer logs out.

### SEC-MOB-08

The application shall not allow a customer to access protected account screens after the session has expired.

---

## 18. Portfolio Website Security Requirements

### SEC-WEB-01

The portfolio website shall not expose database credentials, private API keys, or internal system configuration.

### SEC-WEB-02

Any dynamic website form shall validate and safely process submitted information.

### SEC-WEB-03

The website shall communicate with the shared Backend API rather than directly accessing the production database.

### SEC-WEB-04

Public website pages shall not expose private customer, booking, technician, or payment data.

### SEC-WEB-05

The website shall use HTTPS in the production environment.

---

## 19. Backup and Recovery Security Requirements

### SEC-BCK-01

Production database backups shall be created regularly.

### SEC-BCK-02

Backup files shall be stored separately from the main production database.

### SEC-BCK-03

Access to backup files shall be limited to authorized technical personnel.

### SEC-BCK-04

Backup files containing personal or operational data shall be protected against unauthorized access.

### SEC-BCK-05

The team shall periodically verify that valid backups can be restored.

### SEC-BCK-06

Backup and recovery actions shall be recorded when practical.

---

## 20. External Integration Security Requirements

### SEC-EXT-01

The system shall use official integration methods provided by the Payment Gateway and OTP / SMS Gateway.

### SEC-EXT-02

External integration secret keys shall be stored securely on the backend.

### SEC-EXT-03

External service credentials shall not be included in the mobile application or public website source code.

### SEC-EXT-04

The system shall verify incoming payment notifications before changing a payment status.

### SEC-EXT-05

The system shall safely handle delayed, failed, repeated, or unexpected external service responses.

### SEC-EXT-06

Test credentials shall be separated from production credentials.

### SEC-EXT-07

Production operations shall not use external service testing credentials.

---

## 21. Security Incident Requirements

### SEC-INC-01

The company shall define a process for reporting and handling suspected security incidents.

### SEC-INC-02

The system should allow authorized personnel to identify affected:

* Accounts
* Bookings
* Payments
* System components

### SEC-INC-03

Compromised credentials or secret keys shall be replaced as soon as reasonably possible.

### SEC-INC-04

An affected Admin account shall be disabled when unauthorized access is suspected.

### SEC-INC-05

Security-related evidence and logs shall be preserved for authorized investigation.

### SEC-INC-06

The development team shall address critical security weaknesses before the Version 1 production release.

---

## 22. Version 1 Security Testing Requirements

### SEC-TST-01

Authentication, OTP verification, logout, and access-control functions shall be tested before release.

### SEC-TST-02

The system shall be tested to confirm that Customers cannot access Admin functions.

### SEC-TST-03

The system shall be tested to confirm that one Customer cannot access another Customer’s bookings or receipts.

### SEC-TST-04

Payment integration shall be tested using the Payment Gateway testing environment before production use.

### SEC-TST-05

OTP integration shall be tested using the approved provider testing process before production use.

### SEC-TST-06

The system shall be tested against invalid, missing, duplicated, and manipulated booking information.

### SEC-TST-07

The system shall be tested to confirm that service prices and total amounts are verified by the backend.

### SEC-TST-08

Critical security issues shall be resolved before Version 1 is released to production.
