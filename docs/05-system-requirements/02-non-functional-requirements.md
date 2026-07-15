# Non-Functional Requirements — Version 1

## 1. Purpose

This document defines the non-functional requirements of Version 1 of the Smart Property Services Platform.

These requirements describe how the system should perform in terms of speed, usability, reliability, compatibility, availability, scalability, maintainability, monitoring, and data protection.

---

## 2. Performance Requirements

### NFR-PER-01

The mobile application should open its main screens within three seconds under normal internet conditions.

### NFR-PER-02

The system should display service search and filter results within two seconds under normal operating conditions.

### NFR-PER-03

The system should calculate service prices and the total cart amount immediately after the customer changes service options or quantities.

### NFR-PER-04

The system should load available booking dates and times within three seconds.

### NFR-PER-05

The system should display booking details, payment status, and request status without unnecessary delay.

### NFR-PER-06

The Admin Management Interface should load paid bookings within three seconds under normal operating conditions.

### NFR-PER-07

The system should prevent duplicate booking creation when the customer submits the same request more than once.

### NFR-PER-08

The system should prevent duplicate payment processing caused by repeated button clicks or network delays.

---

## 3. Usability Requirements

### NFR-USA-01

The mobile application should provide a clear and simple customer journey from registration to payment.

### NFR-USA-02

The system should use consistent buttons, icons, labels, and navigation across all application screens.

### NFR-USA-03

The customer should be able to complete regular service booking with the minimum necessary number of steps.

### NFR-USA-04

Service details should be collected mainly through clear UI options instead of long written descriptions.

### NFR-USA-05

Required fields should be clearly marked.

### NFR-USA-06

The system should display clear validation messages when the customer enters incorrect or incomplete information.

### NFR-USA-07

Payment success, payment failure, OTP errors, and booking status messages should be clear and understandable.

### NFR-USA-08

The customer should be able to return to previous booking steps before payment without losing valid entered information.

### NFR-USA-09

The cart should clearly display selected services, quantities, item prices, and the total amount.

### NFR-USA-10

The customer should be able to understand the current booking status without contacting support.

---

## 4. Mobile Compatibility Requirements

### NFR-COM-01

The mobile application shall support iOS devices.

### NFR-COM-02

The mobile application shall support Android devices.

### NFR-COM-03

The mobile application should adapt to common mobile screen sizes.

### NFR-COM-04

Application screens should remain usable in both portrait and supported device layouts.

### NFR-COM-05

The application should provide consistent functionality across supported iOS and Android devices.

### NFR-COM-06

The portfolio website should adapt to desktop, tablet, and mobile screen sizes.

---

## 5. Reliability Requirements

### NFR-REL-01

The system should store customer, booking, payment, and service data accurately.

### NFR-REL-02

A booking should not become active unless the payment result is successful.

### NFR-REL-03

A failed or cancelled payment should not create an active paid booking.

### NFR-REL-04

The system should preserve the customer’s cart if payment fails or is cancelled.

### NFR-REL-05

The system should ensure that one successful cart payment creates only one Booking.

### NFR-REL-06

The system should ensure that all Booking Items remain connected to the correct Booking.

### NFR-REL-07

The general Booking status should not become Completed until all required Booking Items are completed.

### NFR-REL-08

The system should handle temporary network interruptions without corrupting booking or payment data.

### NFR-REL-09

The system should display the latest confirmed data after the customer reconnects to the internet.

---

## 6. Availability Requirements

### NFR-AVL-01

The mobile application, backend, and database should be available during normal company operating hours.

### NFR-AVL-02

Customers should be able to browse services, view bookings, and track request statuses whenever the system is available.

### NFR-AVL-03

Planned maintenance should be performed during low-usage periods when possible.

### NFR-AVL-04

The system should display a clear message when a required external service is temporarily unavailable.

### NFR-AVL-05

Temporary unavailability of the Payment Gateway or OTP Gateway should not damage existing system data.

---

## 7. Scalability Requirements

### NFR-SCL-01

The system architecture should support an increasing number of customers, services, bookings, technicians, and payments.

### NFR-SCL-02

The Backend API and database should be designed so that the mobile application, portfolio website, and Admin Management Interface can use the same system data.

### NFR-SCL-03

The system should support multiple Booking Items inside one Booking without reducing data accuracy.

### NFR-SCL-04

Adding new service categories and services should not require changing the main booking structure.

### NFR-SCL-05

The system should support increasing booking activity without requiring a complete redesign of the database.

---

## 8. Maintainability Requirements

### NFR-MNT-01

The system should use a clear and modular architecture.

### NFR-MNT-02

The mobile application, Backend API, database, and portfolio website should be separated into maintainable components.

### NFR-MNT-03

Business rules should be handled mainly by the backend instead of being duplicated across different interfaces.

### NFR-MNT-04

Service prices, categories, options, and availability should be configurable through the Admin Management Interface.

### NFR-MNT-05

Source code should use clear naming conventions and organized project structures.

### NFR-MNT-06

Important functions, APIs, and business rules should be documented.

### NFR-MNT-07

System changes should be managed using version control.

### NFR-MNT-08

The development team should be able to update one system component without unnecessarily affecting the other components.

---

## 9. Data Consistency Requirements

### NFR-DAT-01

The mobile application, Admin Management Interface, and portfolio website should use the same backend and shared database when accessing dynamic system data.

### NFR-DAT-02

Changes made by the Admin to services, prices, categories, and availability should appear consistently in the customer application.

### NFR-DAT-03

The system should prevent the customer from paying using outdated service prices.

### NFR-DAT-04

The system should verify service activity, price, and appointment availability before starting payment.

### NFR-DAT-05

Booking totals should match the sum of all Booking Item prices.

### NFR-DAT-06

Payment records, receipts, and Bookings should remain connected through reliable identifiers.

### NFR-DAT-07

Technician assignments should remain connected to the correct Booking Items.

---

## 10. Backup and Recovery Requirements

### NFR-BCK-01

The system database should be backed up regularly.

### NFR-BCK-02

Backups should include customer accounts, services, bookings, payments, technicians, ratings, and support data.

### NFR-BCK-03

The development or operations team should be able to restore important data from a valid backup.

### NFR-BCK-04

Backup files should be stored separately from the main production database.

### NFR-BCK-05

The backup process should not interrupt normal customer booking operations.

---

## 11. Logging and Monitoring Requirements

### NFR-LOG-01

The system should record important system errors.

### NFR-LOG-02

The system should record failed payment integration attempts without storing sensitive payment card data.

### NFR-LOG-03

The system should record OTP sending failures and verification errors.

### NFR-LOG-04

The system should record booking creation failures.

### NFR-LOG-05

The system should record important Admin actions, including:

* Service creation
* Service price changes
* Service activation or deactivation
* Appointment availability changes
* Technician assignment
* Booking status changes

### NFR-LOG-06

System logs should include the action date and time.

### NFR-LOG-07

Technical logs should be available to authorized system administrators or developers only.

---

## 12. Accessibility Requirements

### NFR-ACC-01

The application should use readable text sizes.

### NFR-ACC-02

Buttons and interactive elements should be large enough for comfortable mobile use.

### NFR-ACC-03

Important information should not depend only on color.

### NFR-ACC-04

Forms should use clear labels and validation messages.

### NFR-ACC-05

The application should maintain sufficient visual contrast between text and backgrounds.

### NFR-ACC-06

The main customer flow should remain understandable for users with limited technical experience.

---

## 13. External Service Handling Requirements

### NFR-EXT-01

The system should handle delayed responses from the Payment Gateway.

### NFR-EXT-02

The system should handle delayed or failed responses from the OTP / SMS Gateway.

### NFR-EXT-03

The system should not activate an account until OTP verification is confirmed.

### NFR-EXT-04

The system should not create a paid Booking until payment success is confirmed.

### NFR-EXT-05

The system should display a clear error message when an external integration fails.

### NFR-EXT-06

The customer should be able to retry an operation when retrying is safe.

---

## 14. Version 1 Quality Rules

### NFR-QLT-01

The core customer journey should be tested from account registration through booking completion and rating.

### NFR-QLT-02

The Admin booking management journey should be tested from paid booking receipt through technician assignment and completion.

### NFR-QLT-03

The system should be tested on supported iOS and Android devices before release.

### NFR-QLT-04

Payment and OTP integrations should be tested using approved testing environments before production use.

### NFR-QLT-05

Critical defects affecting registration, payment, booking creation, technician assignment, or request tracking should be resolved before Version 1 release.
