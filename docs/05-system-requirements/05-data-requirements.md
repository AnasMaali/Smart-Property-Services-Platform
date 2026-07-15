# Data Requirements — Version 1

## 1. Purpose

This document defines the data requirements for Version 1 of the Smart Property Services Platform.

It identifies the main data that must be collected, stored, updated, connected, and protected to support customer accounts, services, cart-based booking, payments, technician assignment, support, and ratings.

This document does not define the final database tables or database schema.

---

## 2. General Data Requirements

### DR-GEN-01

The mobile application, Admin Management Interface, and portfolio website shall use the same Backend API and shared database for dynamic system data.

### DR-GEN-02

Each main system record shall have a unique identifier.

### DR-GEN-03

Important records shall include creation and update timestamps.

### DR-GEN-04

Relationships between customers, bookings, payments, technicians, services, and ratings shall be stored using reliable identifiers.

### DR-GEN-05

The system shall validate required data before storing it.

### DR-GEN-06

The system shall prevent duplicate records when the same operation is submitted more than once.

### DR-GEN-07

The system shall store only the data required for Version 1 operations and approved business analysis.

---

## 3. Customer Account Data

### DR-CUS-01

The system shall store the following required customer data:

* Customer ID
* Full name
* Phone number
* Password hash
* City
* Area
* Customer relationship to the property
* Account verification status
* Account status
* Registration date

### DR-CUS-02

Customer relationship to the property may include:

* Property Owner
* Tenant
* Property Manager
* Company / Office Representative
* Other

### DR-CUS-03

The system may store the following optional customer data:

* Email address
* Preferred service interests

### DR-CUS-04

The phone number shall be unique for each customer account.

### DR-CUS-05

The system shall not store the customer password as readable plain text.

### DR-CUS-06

The system shall store whether the customer’s phone number has been verified.

### DR-CUS-07

Possible customer account statuses may include:

* Active
* Temporarily Restricted
* Disabled

### DR-CUS-08

The customer shall be able to update permitted profile information without changing historical booking records.

---

## 4. Admin Account Data

### DR-ADM-01

The system shall store the following Admin account data:

* Admin ID
* Full name
* Phone number or login identifier
* Password hash
* Role
* Account status
* Creation date
* Last update date

### DR-ADM-02

Admin account data shall be stored separately or clearly distinguished from normal Customer accounts.

### DR-ADM-03

The system shall store the identity of the Admin who performs important management actions.

### DR-ADM-04

Customers shall not be able to change their account role to Admin.

---

## 5. OTP Verification Data

### DR-OTP-01

The system shall store the temporary information required to verify a phone number.

### DR-OTP-02

OTP verification data may include:

* Verification ID
* Phone number
* Verification purpose
* OTP hash or provider reference
* Expiration date and time
* Verification status
* Number of failed attempts
* Creation date and time

### DR-OTP-03

Possible OTP purposes may include:

* Account registration
* Phone number change

### DR-OTP-04

The system shall not store readable OTP codes longer than necessary.

### DR-OTP-05

Expired or successfully used OTP records shall not be accepted again.

---

## 6. Service Category Data

### DR-CAT-01

The system shall store the following category data:

* Category ID
* Category name
* Category status
* Display order, if needed
* Creation date
* Last update date

### DR-CAT-02

Possible category statuses shall include:

* Active
* Inactive

### DR-CAT-03

Only active categories shall be displayed to Customers.

### DR-CAT-04

A category may contain one or more services.

---

## 7. Service Data

### DR-SRV-01

The system shall store the following service data:

* Service ID
* Service name
* Category ID
* Base price
* Service status
* Service image, if used
* Creation date
* Last update date

### DR-SRV-02

Possible service statuses shall include:

* Active
* Inactive

### DR-SRV-03

Only active services shall be available for search, cart addition, and booking.

### DR-SRV-04

The service shall remain connected to its category.

### DR-SRV-05

Changing a service’s current price shall not change the price stored in previous paid Booking Items.

---

## 8. Service Option Data

### DR-OPT-01

The system shall support configurable options for each service.

### DR-OPT-02

Service option data may include:

* Option ID
* Service ID
* Option name
* Option type
* Required or optional status
* Available values
* Additional price, when applicable
* Active or inactive status

### DR-OPT-03

Possible service option examples include:

* Number of rooms
* Property size
* Number of AC units
* Cleaning type
* Repair type
* Quantity
* Service add-ons

### DR-OPT-04

The system shall store the customer’s selected option values inside the related Cart Item and Booking Item.

---

## 9. Cart Data

### DR-CART-01

The system shall store one active cart for the customer’s current booking process.

### DR-CART-02

Cart data may include:

* Cart ID
* Customer ID
* Cart status
* Total amount
* Creation date
* Last update date

### DR-CART-03

Possible cart statuses may include:

* Active
* Converted to Booking
* Abandoned

### DR-CART-04

The cart shall not become a Booking before successful payment.

### DR-CART-05

The cart total shall equal the sum of all active Cart Item prices.

---

## 10. Cart Item Data

### DR-CI-01

Each service added to the cart shall be stored as a Cart Item.

### DR-CI-02

Cart Item data shall include:

* Cart Item ID
* Cart ID
* Service ID
* Selected service options
* Quantity
* Item price
* Creation date
* Last update date

### DR-CI-03

The customer shall be able to update or remove Cart Items before payment.

### DR-CI-04

The system shall verify that every Cart Item service is active before payment.

### DR-CI-05

Cart Item prices shall be recalculated and verified by the Backend before payment.

---

## 11. Property Information Data

### DR-PRP-01

Property information shall be collected during the booking process only.

### DR-PRP-02

The system shall not provide saved property management in Version 1.

### DR-PRP-03

Property information shall include:

* Property type
* City
* Area
* Street
* Full address
* Building name or number
* Floor number, when applicable
* Apartment or unit number, when applicable
* Nearby landmark
* Additional location notes
* Contact number for the visit

### DR-PRP-04

Possible property types shall include:

* Apartment
* House
* Villa
* Building
* Office
* Other

### DR-PRP-05

The same property information shall apply to all Booking Items inside one Booking.

### DR-PRP-06

Property information stored with a Booking shall remain unchanged unless corrected through an authorized process.

---

## 12. Booking Availability Data

### DR-AVL-01

The system shall store the dates and time slots available for customer booking.

### DR-AVL-02

Availability data may include:

* Availability ID
* Date
* Start time
* End time
* Availability status
* Maximum booking capacity, if used
* Creation date
* Last update date

### DR-AVL-03

Possible availability statuses may include:

* Available
* Unavailable
* Fully Booked

### DR-AVL-04

The system shall prevent booking an unavailable or fully booked time slot.

### DR-AVL-05

The system shall verify appointment availability again before payment.

---

## 13. Booking Data

### DR-BKG-01

After successful payment, the system shall create one Booking for the full cart.

### DR-BKG-02

Booking data shall include:

* Booking ID
* Customer ID
* Property information
* Visit date
* Visit time
* Total amount
* Payment status
* General Booking status
* Creation date
* Last update date
* Completion date, when applicable

### DR-BKG-03

Possible Booking statuses shall include:

* Paid
* Assigned to Technician
* In Progress
* Completed
* Cancelled

### DR-BKG-04

A Booking shall contain one or more Booking Items.

### DR-BKG-05

A Booking shall remain connected to the customer who completed the payment.

### DR-BKG-06

The Booking total shall equal the sum of all Booking Item prices.

### DR-BKG-07

The Booking status shall become Completed only after all required Booking Items are completed.

### DR-BKG-08

A Customer shall not directly modify the Booking status after payment.

---

## 14. Booking Item Data

### DR-BI-01

Each service inside a paid Booking shall be stored as a Booking Item.

### DR-BI-02

Booking Item data shall include:

* Booking Item ID
* Booking ID
* Service ID
* Service name snapshot
* Selected service options
* Quantity
* Item price
* Assigned Technician ID, when assigned
* Booking Item status
* Creation date
* Last update date

### DR-BI-03

Possible Booking Item statuses shall include:

* Pending Assignment
* Assigned
* In Progress
* Completed
* Cancelled

### DR-BI-04

The service name, selected options, and item price shall be stored as they were at the time of payment.

### DR-BI-05

Changes to current service information shall not modify previous paid Booking Items.

### DR-BI-06

Different Booking Items inside the same Booking may be assigned to different technicians.

---

## 15. Payment Data

### DR-PAY-01

The system shall store payment data connected to the related Booking or checkout process.

### DR-PAY-02

Payment data shall include:

* Payment ID
* Customer ID
* Booking ID, when created
* Payment Gateway reference number
* Total amount
* Payment status
* Payment date and time
* Payment method type, when provided
* Creation date
* Last update date

### DR-PAY-03

Possible payment statuses shall include:

* Pending
* Successful
* Failed
* Cancelled
* Refunded

### DR-PAY-04

The system shall not store:

* Complete card numbers
* Card security codes
* Payment account passwords

### DR-PAY-05

One successful cart payment shall create one paid Booking only.

### DR-PAY-06

Repeated Payment Gateway notifications shall not create duplicate Bookings.

---

## 16. Receipt Data

### DR-RCP-01

The system shall generate one basic receipt for each successful cart payment.

### DR-RCP-02

Receipt data shall include:

* Receipt ID
* Booking ID
* Customer name
* Selected services
* Price of each service
* Total paid amount
* Payment date
* Payment status
* Payment reference number

### DR-RCP-03

The receipt shall remain connected to the related Booking.

### DR-RCP-04

The customer shall be able to view the receipt through Booking details and Booking history.

---

## 17. Technician Data

### DR-TEC-01

The system shall store technician records without creating Technician login accounts in Version 1.

### DR-TEC-02

Technician data shall include:

* Technician ID
* Full name
* Specialization
* Contact number
* Availability status
* Current assignment status
* Record status
* Creation date
* Last update date

### DR-TEC-03

Possible Technician availability statuses may include:

* Available
* Assigned
* Unavailable

### DR-TEC-04

The Admin shall manage Technician data and assignments.

### DR-TEC-05

The Customer shall see only the Technician information approved by the company.

---

## 18. Technician Assignment Data

### DR-ASG-01

The system shall store the connection between a Technician and the assigned Booking Item.

### DR-ASG-02

Technician assignment data may include:

* Assignment ID
* Technician ID
* Booking Item ID
* Assigned by Admin ID
* Assignment date and time
* Assignment status
* Internal notes, if needed

### DR-ASG-03

A Technician may be assigned to more than one Booking Item when appropriate.

### DR-ASG-04

A Booking may contain assignments for different Technicians.

### DR-ASG-05

The system shall preserve assignment history when the assigned Technician changes.

---

## 19. Support Request Data

### DR-SUP-01

The system shall store support messages submitted through the application.

### DR-SUP-02

Support Request data shall include:

* Support Request ID
* Customer ID, when logged in
* Customer name or phone number when needed
* Problem description
* Support status
* Creation date
* Last update date
* Closed date, when applicable

### DR-SUP-03

Possible Support Request statuses may include:

* Open
* In Progress
* Resolved
* Closed

### DR-SUP-04

Customers experiencing login or OTP problems may submit basic support information without completing login.

### DR-SUP-05

The system shall not store passwords, readable OTP codes, or complete card information inside support messages.

---

## 20. Rating and Feedback Data

### DR-RT-01

The system shall store one rating for each completed Booking when submitted by the Customer.

### DR-RT-02

Rating data shall include:

* Rating ID
* Booking ID
* Customer ID
* Star rating
* Optional comment
* Submission date
* Last update date, if applicable

### DR-RT-03

The star rating shall be between one and five.

### DR-RT-04

The system shall prevent more than one rating for the same Booking.

### DR-RT-05

The rating shall remain connected to the Customer and completed Booking.

---

## 21. Admin Audit Data

### DR-AUD-01

The system shall store audit data for important Admin actions.

### DR-AUD-02

Audit data shall include:

* Audit Record ID
* Admin ID
* Action type
* Related record type
* Related record ID
* Action date and time
* Additional safe details, when needed

### DR-AUD-03

Important audited actions shall include:

* Service creation or update
* Service price change
* Service activation or deactivation
* Category changes
* Appointment availability changes
* Technician assignment
* Booking status changes
* Booking cancellation
* Support Request closure

### DR-AUD-04

Audit records shall not include readable passwords, OTP codes, authentication tokens, or complete payment information.

---

## 22. Data Consistency Rules

### DR-CON-01

A Cart total shall equal the sum of its Cart Item prices.

### DR-CON-02

A Booking total shall equal the sum of its Booking Item prices.

### DR-CON-03

A paid Booking shall have a confirmed successful Payment record.

### DR-CON-04

A Receipt shall be connected to one successful Payment and one Booking.

### DR-CON-05

A Booking Item shall belong to one Booking only.

### DR-CON-06

A Technician assignment shall belong to the correct Booking Item.

### DR-CON-07

A Rating shall belong to one completed Booking and its Customer.

### DR-CON-08

A Customer shall access only data connected to their own account.

---

## 23. Data Retention and Backup

### DR-RET-01

The company shall define the retention period for:

* Customer accounts
* Bookings
* Payments
* Receipts
* Support Requests
* Ratings
* Audit records
* Technical logs

### DR-RET-02

The system shall create regular backups of important operational data.

### DR-RET-03

Backups shall include:

* Customer data
* Service data
* Booking data
* Payment data
* Technician data
* Support data
* Rating data
* Audit data

### DR-RET-04

Backup data shall be protected from unauthorized access.

### DR-RET-05

The system team shall be able to restore important records from a valid backup.

---

## 24. Data for Business Analysis

### DR-ANA-01

The system shall store operational data that can support approved reports and business analysis.

### DR-ANA-02

Analysis data may include:

* Customer registration date
* Customer city and area
* Customer relationship to the property
* Preferred service interests
* Selected services
* Service categories
* Property type
* Booking date and time
* Booking amount
* Payment status
* Booking status
* Assigned Technicians
* Service completion status
* Customer rating
* Customer comment

### DR-ANA-03

Customer data used for reports should be aggregated or anonymized when individual identification is not required.

---

## 25. Important Version 1 Data Rules

* Property information is collected during Booking only.
* Version 1 does not include saved property management.
* A Cart is converted into one Booking only after successful payment.
* One Booking may contain one or more Booking Items.
* One Booking may contain different Technician assignments.
* Historical Booking Item prices shall not change when current service prices are updated.
* Technicians do not have login accounts in Version 1.
* Customers shall access only their own data.
* Passwords, readable OTP codes, and complete card information shall not be stored.
* The shared database shall be accessed through the Backend API.
