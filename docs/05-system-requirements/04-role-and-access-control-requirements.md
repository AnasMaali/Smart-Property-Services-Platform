# Role & Access Control Requirements — Version 1

## 1. Purpose

This document defines the roles, permissions, and access-control rules for Version 1 of the Smart Property Services Platform.

The system shall ensure that every user can access only the functions and data permitted for their role.

---

## 2. Version 1 Roles

Version 1 includes the following roles and system actors:

* Customer / One-Time Customer
* Admin / Service Management Team
* Technician / Service Employee
* Public Website Visitor
* Payment Gateway
* OTP / SMS Gateway

---

## 3. Customer / One-Time Customer

### Role Description

The Customer / One-Time Customer is a registered user who does not have an annual subscription.

The customer uses the mobile application to browse regular services, create bookings, pay, track requests, contact support, and submit ratings.

### Customer Permissions

The Customer shall be able to:

* Create an account.
* Verify the phone number using OTP.
* Log in and log out.
* View and update personal profile information.
* Browse active services.
* Search for services by name.
* Filter services by category.
* View service details and prices.
* Select service-specific options.
* Add one or more services to the cart.
* Edit or remove Cart Items.
* Enter property information during booking.
* Select an available date and time.
* Review booking details before payment.
* Complete payment through the Payment Gateway.
* View the payment receipt.
* View current and previous bookings.
* Track Booking and Booking Item statuses.
* View assigned technician information.
* Use the Book Again option for completed bookings.
* Contact human support.
* Submit one rating and optional comment after Booking completion.

### Customer Restrictions

The Customer shall not be able to:

* Access Admin functions or Admin data.
* Add, edit, activate, or deactivate services.
* Change service prices.
* Manage categories.
* Manage booking availability.
* View another customer’s profile or bookings.
* Change payment status.
* Change Booking or Booking Item status.
* Assign or remove technicians.
* Create Admin accounts.
* Register as an Admin.
* Directly cancel a paid booking.
* Submit more than one rating for the same Booking.
* Access technical logs or system configuration.
* Access secret keys or database credentials.

---

## 4. Admin / Service Management Team

### Role Description

The Admin / Service Management Team is responsible for managing the operational functions of the system.

The Admin manages services, availability, paid bookings, technicians, support requests, booking statuses, and customer feedback.

### Admin Permissions

The Admin shall be able to:

* Log in using an authorized Admin account.
* Access the Admin Management Interface.
* View active and inactive services.
* Add new services.
* Edit existing services.
* Activate or deactivate services.
* Manage service prices.
* Manage service-specific options.
* Add, edit, activate, or deactivate service categories.
* Add, edit, or remove available booking dates and time slots.
* View paid Bookings.
* View Customer information related to Bookings.
* View property information entered during Booking.
* View Booking Items and their details.
* View payment status and payment reference information.
* View the total paid amount.
* View technician records.
* Add or update technician information.
* Review technician specialization and availability.
* Assign one technician to one or more Booking Items.
* Assign different technicians to different Booking Items.
* Update Booking Item statuses.
* Update the general Booking status.
* Contact the Customer when additional information is required.
* Mark a Booking as Cancelled in an exceptional approved case.
* View and handle support messages.
* Close resolved support requests.
* View customer ratings and comments.
* View the Booking related to a rating.
* Close the Booking after all required services are completed.

### Admin Restrictions

The Admin shall not be able to:

* View customer passwords.
* View readable OTP codes.
* View complete payment card information.
* Directly modify payment results without trusted Payment Gateway confirmation.
* Access production secrets unless separately authorized as technical personnel.
* Delete or change audit records without authorization.
* Create unauthorized Admin accounts.
* use customer data for unrelated purposes.

---

## 5. Technician / Service Employee

### Role Description

The Technician / Service Employee performs the service assigned by the Admin.

Technicians do not have separate system accounts in Version 1.

### Technician System Access

The Technician shall not:

* Log in to the mobile application as a Technician.
* Access the Admin Management Interface.
* View all Customer Bookings.
* Assign themselves to a service.
* Update Booking or Booking Item statuses directly.
* Access Customer payment information.
* Access system settings or reports.

### Technician Information Stored by the System

The system may store:

* Technician name
* Specialization
* Contact number
* Availability
* Current assignment status

### Technician Information Access

The Admin may access full technician records required for assignment.

The Customer may view only:

* Technician name
* Technician specialization
* Assigned service
* Contact number when the company allows it

The technician shall receive only the customer and property information required to perform the assigned service.

---

## 6. Public Website Visitor

### Role Description

A Public Website Visitor accesses the company portfolio website without logging in.

### Public Visitor Permissions

The Public Website Visitor may:

* View company information.
* View public service information.
* View previous company work.
* View approved contact information.
* Open official phone, WhatsApp, or email contact links.

### Public Visitor Restrictions

The Public Website Visitor shall not be able to:

* View Customer information.
* View Bookings.
* View payment information.
* View technician private information.
* Access Admin functions.
* Access protected API functions.
* Access the shared database directly.

---

## 7. Payment Gateway

### Actor Description

The Payment Gateway is an external non-human system used to process payments.

### Payment Gateway Functions

The Payment Gateway may:

* Receive a payment request from the Backend.
* Process the Customer payment.
* Return the payment result.
* Provide a payment reference number.
* Send verified payment notifications to the Backend.

### Payment Gateway Restrictions

The Payment Gateway shall not:

* Access unrelated Customer information.
* Manage services or Bookings.
* Assign technicians.
* Change operational Booking statuses.
* Access Admin functions.

The platform shall not create a paid Booking until the payment result is confirmed through trusted Payment Gateway information.

---

## 8. OTP / SMS Gateway

### Actor Description

The OTP / SMS Gateway is an external non-human system used to deliver phone verification codes.

### OTP Gateway Functions

The OTP / SMS Gateway may:

* Receive an OTP delivery request from the Backend.
* Send the OTP to the correct phone number.
* Return the message delivery result.

### OTP Gateway Restrictions

The OTP / SMS Gateway shall not:

* Access Customer passwords.
* Access Bookings or payments.
* Manage services.
* Access Admin functions.
* Activate accounts without Backend verification.

---

## 9. Role-Based Access Control Rules

### RBAC-01

The system shall assign a defined role to every authenticated account.

### RBAC-02

The Backend API shall verify the authenticated user’s identity and role before processing a protected request.

### RBAC-03

Access control shall not depend only on hiding screens, buttons, or menu items.

### RBAC-04

Customer and Admin functions shall be separated through role-based permissions.

### RBAC-05

A Customer attempting to access an Admin function shall receive an access-denied response.

### RBAC-06

An unauthenticated user attempting to access a protected function shall be required to log in.

### RBAC-07

An expired or invalid session shall not provide access to protected functions.

### RBAC-08

The system shall prevent users from increasing their own permissions by modifying application requests.

### RBAC-09

Customers shall access only records connected to their own account.

### RBAC-10

Only authorized Admin users shall access operational management functions.

---

## 10. Customer Data Ownership Rules

### ACR-DAT-01

A Customer shall be able to view and update only their own profile.

### ACR-DAT-02

A Customer shall be able to view only their own Bookings and Booking Items.

### ACR-DAT-03

A Customer shall be able to view only receipts connected to their own Bookings.

### ACR-DAT-04

A Customer shall be able to view and submit support messages connected to their own account.

### ACR-DAT-05

A Customer shall be able to rate only a completed Booking connected to their own account.

### ACR-DAT-06

Changing a record identifier shall not allow a Customer to access another Customer’s information.

---

## 11. Booking Access Rules

### ACR-BKG-01

The Customer may create a Booking only for their authenticated account.

### ACR-BKG-02

The Customer may review and edit Cart information before payment.

### ACR-BKG-03

The Customer shall not directly change the Booking status after payment.

### ACR-BKG-04

The Customer shall not directly change Booking Item statuses.

### ACR-BKG-05

The Customer shall not directly assign or remove technicians.

### ACR-BKG-06

The Admin may view and manage paid Bookings.

### ACR-BKG-07

The Admin may update operational Booking statuses.

### ACR-BKG-08

The Admin may update Booking Item statuses on behalf of the assigned technicians.

### ACR-BKG-09

The Admin may mark a paid Booking as Cancelled only in an exceptional approved case.

---

## 12. Payment Access Rules

### ACR-PAY-01

The Customer may start payment only for their own confirmed Cart.

### ACR-PAY-02

The Customer shall not send or modify the final trusted payment status.

### ACR-PAY-03

The Backend shall verify the payment result using trusted Payment Gateway data.

### ACR-PAY-04

The Admin may view payment status, amount, date, and reference number.

### ACR-PAY-05

The Admin shall not view complete payment card details.

### ACR-PAY-06

Only authorized system processes shall create a paid Booking after payment confirmation.

---

## 13. Service Management Access Rules

### ACR-SV-01

Only authorized Admin users shall add or edit services.

### ACR-SV-02

Only authorized Admin users shall change service prices.

### ACR-SV-03

Only authorized Admin users shall activate or deactivate services.

### ACR-SV-04

Only authorized Admin users shall manage service categories.

### ACR-SV-05

Only authorized Admin users shall manage available booking dates and time slots.

### ACR-SV-06

Customers and Public Website Visitors may view active services only.

---

## 14. Technician Access Rules

### ACR-TC-01

Only the Admin shall assign technicians in Version 1.

### ACR-TC-02

Only the Admin shall update technician availability and assignment records.

### ACR-TC-03

Technicians shall not update the system directly in Version 1.

### ACR-TC-04

The Customer shall see technician information only after assignment.

### ACR-TC-05

The technician contact number shall appear to the Customer only when the company allows it.

---

## 15. Support Access Rules

### ACR-SP-01

Customers shall be able to access basic support for login and OTP problems without completing login.

### ACR-SP-02

Authenticated Customers shall be able to submit support messages.

### ACR-SP-03

Only authorized Admin or support personnel shall view and handle Customer support messages.

### ACR-SP-04

Support personnel shall not request passwords, OTP codes, or complete card information.

### ACR-SP-05

Support messages shall not expose information belonging to another Customer.

---

## 16. Rating Access Rules

### ACR-RT-01

The Customer shall be able to rate only a Booking with Completed status.

### ACR-RT-02

The Customer shall submit only one rating for each Booking.

### ACR-RT-03

The Customer shall not rate another Customer’s Booking.

### ACR-RT-04

The Admin shall be able to view submitted ratings and comments.

### ACR-RT-05

The Admin shall not modify a Customer’s submitted rating as if it were submitted by the Customer.

---

## 17. Unauthorized Access Handling

### ACR-ERR-01

The system shall deny unauthorized access attempts.

### ACR-ERR-02

The system shall return a clear access-denied message without revealing sensitive technical details.

### ACR-ERR-03

The system shall record important unauthorized access attempts.

### ACR-ERR-04

Repeated suspicious access attempts may result in temporary restriction.

### ACR-ERR-05

Unauthorized access attempts shall not expose private records or confirm sensitive information.

---

## 18. Important Version 1 Rules

* Customer and Admin access shall be separated.
* Customers shall access only their own data.
* Admin accounts shall be created through an authorized process.
* Technicians shall not have system accounts.
* The Backend shall enforce all protected permissions.
* Paid Booking status shall not be controlled by the Customer.
* Technician assignment shall be controlled by the Admin.
* Payment success shall be confirmed through the Payment Gateway.
* Public website visitors shall access public information only.
