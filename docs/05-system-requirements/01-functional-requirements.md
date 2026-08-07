# Functional Requirements — Version 1

## 1. Purpose

This document defines the functional requirements of Version 1 of the Smart Property Services Platform.

Version 1 focuses on regular service booking for the Customer / One-Time Customer through the mobile application.

---

## 2. Platform Requirements

### FR-PL-01

The system shall provide a mobile application for iOS and Android.

### FR-PL-02

The mobile application shall be the main platform used by customers.

### FR-PL-03

The system shall provide an Admin Management Interface connected to the same backend and database used by the mobile application.

### FR-PL-04

The system shall provide a company portfolio website that displays company information, services, previous work, and contact information.

---

## 3. Customer Registration

### FR-AU-01

The system shall allow a new customer to create an account.

### FR-AU-02

The registration form shall collect:

* Full name
* Phone number
* Email address
* Password
* City
* Area
* Customer classification
* Preferred service interests

### FR-AU-03

The email address shall be required, shall be validated for a correct format, and shall be unique across customer accounts.

### FR-AU-04

The system shall automatically store the account registration date.

### FR-AU-05

Customer classification options shall include:

* Property Owner
* Tenant
* Property Manager
* Company / Office Representative
* Other

### FR-AU-06

The customer shall select at least one preferred service interest during registration.

### FR-AU-07

The system shall validate that the submitted area belongs to the submitted city before creating the account.

### FR-AU-08

Property Type Interest shall not be collected during registration. Property type shall be collected only during the booking process.

---

## 4. OTP Verification

### FR-OTP-01

The system shall send an OTP code to the customer’s phone number during account registration.

### FR-OTP-02

The system shall activate the account only after successful OTP verification.

### FR-OTP-03

The system shall detect incorrect and expired OTP codes.

### FR-OTP-04

The customer shall be able to request a new OTP code.

### FR-OTP-05

The system shall prevent unverified accounts from fully accessing the application.

### FR-OTP-06

OTP verification shall not be required during every normal login.

### FR-OTP-07

The OTP code shall consist of 6 numeric digits.

### FR-OTP-08

The OTP code shall expire 5 minutes after it is issued.

### FR-OTP-09

The system shall reject OTP verification after 5 failed attempts for the same OTP code.

### FR-OTP-10

The customer shall wait at least 60 seconds before requesting another OTP code for the same phone number and purpose.

### FR-OTP-11

Issuing a new OTP code shall invalidate the previous active OTP code for the same customer and purpose.

---

## 5. Login and Profile Management

### FR-PF-01

The customer shall be able to log in using a verified phone number and password.

### FR-PF-02

The customer shall be able to log out.

### FR-PF-03

The customer shall be able to view and update:

* Full name
* City
* Area
* Email address
* Preferred service interests

### FR-PF-04

Changing the phone number shall require OTP verification of the new number.

### FR-PF-05

The system shall prevent the customer from using a phone number already connected to another account.

### FR-PF-06

The system shall allow a customer to request a password reset (forgot password) using their registered phone number.

### FR-PF-07

The system shall send an OTP code to the customer's registered phone number as part of the password reset request.

### FR-PF-08

The system shall require successful OTP verification before allowing the customer to set a new password.

### FR-PF-09

The new password set during password reset shall meet the same password policy required at registration.

---

## 6. Services and Categories

### FR-SV-01

The system shall display active property services inside the mobile application.

### FR-SV-02

The system shall organize services into categories.

### FR-SV-03

Each service shall include:

* Service name
* Category
* Price
* Active or inactive status
* Service-specific options

### FR-SV-04

The Admin shall be able to:

* Add services
* Edit services
* Activate services
* Deactivate services
* Manage service prices
* Manage service-specific options

### FR-SV-05

The Admin shall be able to add, edit, activate, and deactivate service categories.

### FR-SV-06

Possible service categories may include:

* AC
* Cleaning
* Plumbing
* Electrical
* Painting
* Handyman
* Pest Control
* Masonry
* Gypsum
* Waterproofing
* Flooring
* Tiling
* Carpentry
* Landscaping
* Swimming Pool
* Smart Home
* CCTV
* Other

---

## 7. Search and Filters

### FR-SF-01

The customer shall be able to search for services by name.

### FR-SF-02

The customer shall be able to browse services by category.

### FR-SF-03

The customer shall be able to filter services by category.

### FR-SF-04

The customer shall be able to clear the search text and selected filters.

### FR-SF-05

Search and filter results shall display active services only.

### FR-SF-06

The customer shall be able to open a service from the search results and add it to the cart.

---

## 8. Service Details

### FR-SD-01

The system shall collect service details through clear UI options.

### FR-SD-02

Service-specific options may include:

* Number of rooms
* Property size
* Number of AC units
* Cleaning type
* Repair type
* Quantity
* Service add-ons

### FR-SD-03

The system shall calculate the service price based on the selected options.

### FR-SD-04

A written problem description shall not be required for regular services in Version 1.

---

## 9. Cart Management

### FR-CT-01

The customer shall be able to add one or more regular services to the cart.

### FR-CT-02

The customer shall be able to:

* View cart services
* Edit service options
* Change quantities
* Remove services

### FR-CT-03

The system shall display:

* Price of each service
* Selected service options
* Total cart amount

### FR-CT-04

All services inside one cart shall be connected to the same property.

### FR-CT-05

All services inside one cart shall use the same visit date and time.

### FR-CT-06

The system shall prevent the customer from continuing with an empty cart.

### FR-CT-07

The system shall detect services that became inactive before checkout.

### FR-CT-08

Inactive services shall be removed or replaced before the customer continues.

---

## 10. Property Information During Booking

### FR-PR-01

The customer shall enter property information during the booking process.

### FR-PR-02

The customer shall not manage saved properties in Version 1.

### FR-PR-03

The property information shall apply to all services inside the cart.

### FR-PR-04

Property information shall include:

* Property type
* City
* Area
* Street
* Full address
* Building name or number
* Floor number when needed
* Apartment or unit number when needed
* Nearby landmark
* Additional location notes
* Contact number for the visit

### FR-PR-05

Property type options shall include:

* Apartment
* House
* Villa
* Building
* Office
* Other

### FR-PR-06

The system shall validate the required property information before continuing.

---

## 11. Appointment Selection

### FR-AP-01

The system shall display available booking dates and times.

### FR-AP-02

The customer shall select one available appointment for the entire cart.

### FR-AP-03

The system shall prevent the selection of unavailable appointments.

### FR-AP-04

The Admin shall be able to add, edit, and remove available booking dates and time slots.

### FR-AP-05

The system shall display another available date when no times are available for the selected date.

---

## 12. Booking Review

### FR-BR-01

Before payment, the system shall display:

* Selected services
* Details of each service
* Property information
* Selected date and time
* Price of each service
* Total cart amount

### FR-BR-02

The customer shall confirm the booking details before payment.

### FR-BR-03

The customer shall be able to return and edit the cart, property information, or appointment before payment.

---

## 13. Payment

### FR-PY-01

The customer shall pay once for the total cart amount.

### FR-PY-02

The system shall process payments through an external Payment Gateway.

### FR-PY-03

The system shall send the payment amount and booking reference to the Payment Gateway.

### FR-PY-04

The system shall receive and store the payment result.

### FR-PY-05

Possible payment statuses shall include:

* Pending
* Successful
* Failed
* Cancelled
* Refunded

### FR-PY-06

The system shall create an active booking only after successful payment.

### FR-PY-07

Failed or cancelled payments shall not create active booking requests.

### FR-PY-08

The customer shall be able to retry a failed payment.

### FR-PY-09

The system shall store the payment reference number when available.

---

## 14. Booking and Booking Items

### FR-BK-01

The system shall create one Booking for the full paid cart.

### FR-BK-02

A Booking shall contain one or more Booking Items.

### FR-BK-03

The Booking shall store:

* Customer information
* Property information
* Visit date and time
* Total amount
* Payment status
* General booking status

### FR-BK-04

Each Booking Item shall store:

* Selected service
* Service options
* Quantity
* Item price
* Assigned technician
* Item status

---

## 15. Basic Receipt

### FR-RC-01

The system shall generate a basic receipt after successful payment.

### FR-RC-02

The receipt shall include:

* Booking ID
* Customer name
* Selected services
* Price of each service
* Total paid amount
* Payment date
* Payment status
* Payment reference number when available

### FR-RC-03

The system shall generate one receipt for the full cart payment.

### FR-RC-04

The customer shall be able to view the receipt inside:

* Booking details
* Booking history

---

## 16. Admin Booking Management

### FR-AD-01

The Admin shall be able to view paid bookings.

### FR-AD-02

The Admin shall be able to view:

* Customer information
* Property information
* Visit date and time
* Booking Items
* Details and price of each service
* Total paid amount
* Payment status
* Booking status

### FR-AD-03

The Admin shall manage the paid booking without normally accepting or rejecting it after payment.

### FR-AD-04

The Admin shall be able to contact the customer when more information is needed.

### FR-AD-05

The Admin shall follow the booking until all services are completed.

### FR-AD-06

The Admin shall close the booking after all required Booking Items are completed.

### FR-AD-07

The Admin may mark a booking as Cancelled only in an exceptional case.

### FR-AD-08

Customers shall not directly cancel paid bookings through the mobile application.

---

## 17. Technician Records and Assignment

### FR-TC-01

Technicians shall not have separate system accounts in Version 1.

### FR-TC-02

The system shall store technician information, including:

* Technician name
* Specialization
* Contact number
* Availability
* Current assignment status

### FR-TC-03

The Admin shall manually assign technicians.

### FR-TC-04

The Admin shall be able to assign one technician to multiple Booking Items when appropriate.

### FR-TC-05

The Admin shall be able to assign different technicians to different Booking Items.

### FR-TC-06

The Admin shall update service progress on behalf of technicians.

---

## 18. Technician Information for Customer

### FR-TI-01

The customer shall see technician information only after assignment.

### FR-TI-02

The customer may view:

* Technician name
* Technician specialization
* Assigned service
* Contact number when the company allows it

### FR-TI-03

A multi-service booking may display different technicians for different Booking Items.

---

## 19. Booking Status Tracking

### FR-ST-01

The customer shall be able to view the current booking status inside the mobile application.

### FR-ST-02

Main booking statuses shall include:

* Paid
* Assigned to Technician
* In Progress
* Completed
* Cancelled

### FR-ST-03

Booking Item statuses shall include:

* Pending Assignment
* Assigned
* In Progress
* Completed
* Cancelled

### FR-ST-04

The Admin shall update Booking and Booking Item statuses.

### FR-ST-05

The customer shall only view statuses and shall not modify them.

### FR-ST-06

The general Booking status shall become Completed only after all required Booking Items are completed.

---

## 20. Booking History

### FR-HS-01

The customer shall be able to view current and previous bookings.

### FR-HS-02

Booking history shall display:

* Booking ID
* Selected services
* Property information
* Visit date and time
* Total price
* Payment status
* Booking status
* Receipt

### FR-HS-03

The customer shall be able to open a booking and view its complete details.

---

## 21. Rebooking

### FR-RB-01

Completed bookings shall display a Book Again option.

### FR-RB-02

Selecting Book Again shall create a new cart.

### FR-RB-03

The system shall copy previous active services and service options when possible.

### FR-RB-04

Inactive services shall not be copied automatically.

### FR-RB-05

The new cart shall use current service prices.

### FR-RB-06

The customer shall review the new cart and confirm the property information.

### FR-RB-07

The customer shall select a new available date and time.

### FR-RB-08

Rebooking shall require a new payment.

### FR-RB-09

The previous booking shall not be modified.

---

## 22. Human Support

### FR-SP-01

The system shall provide access to human support.

### FR-SP-02

Human support methods shall include:

* Phone
* WhatsApp
* Support message

### FR-SP-03

The customer shall be able to write and submit a support message.

### FR-SP-04

The Admin shall be able to view and handle support messages.

### FR-SP-05

The Admin shall be able to contact the customer when more information is needed.

### FR-SP-06

The Admin shall close the support request after resolving the problem.

### FR-SP-07

A customer experiencing login or OTP problems shall be able to access basic support without completing login.

---

## 23. Rating and Feedback

### FR-RT-01

The system shall enable rating only after the Booking status becomes Completed.

### FR-RT-02

The customer shall be able to submit a rating from one to five stars.

### FR-RT-03

The customer shall be able to add an optional written comment.

### FR-RT-04

The system shall allow one overall rating per Booking.

### FR-RT-05

The rating shall be connected to the Booking ID.

### FR-RT-06

The system shall prevent duplicate ratings for the same Booking.

### FR-RT-07

The Admin shall be able to view the rating, comment, customer, and related booking.

---

## 24. Data Collection

### FR-DT-01

The system shall store customer and operational data needed for service management and analysis.

### FR-DT-02

Stored data shall include:

* Customer registration date
* Customer city and area
* Customer classification
* Preferred service interests
* Selected services
* Service categories
* Property type
* Booking date and time
* Booking amount
* Payment status
* Booking status
* Assigned technicians
* Booking Item statuses
* Customer rating
* Customer comment
