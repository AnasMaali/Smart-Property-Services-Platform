# Admin Request Management

## Purpose

This document defines how the Admin / Service Management Team manages paid booking requests after successful payment.

In Version 1, the customer can add one or more regular services to the cart, pay once for the total cart amount, and then the paid booking request appears in the admin panel.

---

## Version 1 Scope

In Version 1, this module is designed to manage paid booking requests created by the **Customer / One-Time Customer**.

This module does not include emergency requests, annual subscriptions, AMC contracts, or advanced technician dashboards.

---

## Main Rule

The admin manages booking requests only after successful payment.

The admin does not normally accept or reject a request after payment.

The admin’s responsibility is to review and manage the paid booking request, assign the suitable technician or employee, update the request status, and follow up until completion.

---

## Admin Panel View

After payment, the admin should be able to view the paid booking request inside the admin panel.

The admin should see:

* Booking ID
* Customer information
* Property information
* Selected visit date and time
* Selected services inside the booking
* Details of each selected service
* Total cart amount
* Payment status
* General booking status

---

## Booking and Booking Items

Because Version 1 supports cart-based booking, one booking may contain one or more services.

### Booking

The booking represents the full customer request.

It includes:

* Customer information
* Property information
* Visit date and time
* Total price
* Payment status
* General booking status

### Booking Items

Booking items represent the services inside the booking.

Each booking item may include:

* Selected service
* Service details
* Item price
* Assigned technician, if needed
* Item status, if needed

---

## Admin Main Functions

The Admin / Service Management Team can:

* View paid booking requests.
* View booking details.
* View customer information.
* View property information.
* View all services inside the booking.
* View details for each service.
* View payment status.
* Assign the suitable technician or employee.
* Assign different technicians for different services if needed.
* Update the booking status.
* Update service item status if needed.
* Contact the customer if more information is needed.
* Follow up until the service is completed.
* Close the booking after completion.

---

## Admin Request Management Flow

The admin request management flow is:

1. The admin logs in to the admin panel.
2. The admin views new paid booking requests.
3. The admin opens a booking request.
4. The admin reviews customer information.
5. The admin reviews property information.
6. The admin reviews the selected services inside the booking.
7. The admin checks the details of each service.
8. The admin checks the selected date, time, and payment status.
9. The admin assigns the suitable technician or employee for each service if needed.
10. The admin updates the request status based on service progress.
11. The admin contacts the customer if information is missing or unclear.
12. The admin follows up until all services are completed.
13. The admin closes the booking request after completion.

---

## Booking Statuses

Possible booking statuses may include:

* Paid
* Assigned to Technician
* In Progress
* Completed
* Cancelled

---

## Service Item Statuses

If needed, each service inside the booking can have its own status.

Possible service item statuses may include:

* Pending Assignment
* Assigned
* In Progress
* Completed
* Cancelled

This is useful when one booking contains multiple services and each service may be handled by a different technician.

---

## Technician Assignment

The admin can assign a technician or employee to the booking.

If the booking contains multiple services, the admin may assign different technicians to different services.

Example:

* AC Cleaning → AC Technician
* Sofa Cleaning → Cleaning Team
* Curtain Installation → Handyman

This allows the admin to manage multi-service bookings more clearly.

---

## Customer Communication

The admin can contact the customer if more information is needed.

Examples:

* Address details are unclear.
* Service details are incomplete.
* The selected service does not match the customer’s request.
* The visit time needs confirmation.
* The admin needs additional notes before assigning a technician.

---

## Cancellation Handling

In Version 1, cancellation after payment should happen only in exceptional cases.

Examples:

* The service cannot be provided.
* The address is outside the service area.
* The booking information is incorrect.
* The selected service cannot be completed.
* The request requires a different price or special inspection.

If cancellation happens after payment, the system should later support a clear cancellation and refund process.

---

## Not Included in Version 1

The following admin features are not included in Version 1:

* Emergency request management
* Dangerous case management
* Emergency image review
* Emergency deposit handling
* Annual subscription management
* AMC contract management
* Live technician tracking
* Technician dashboard
* AI-based request analysis
* Notification management
* Advanced reports
* Refund automation

These features can be planned for future versions.

---

## Notes

* This module is included in Version 1.
* The admin manages paid booking requests only.
* The admin does not normally accept or reject paid requests.
* One booking request may contain one or more services.
* The admin can assign technicians based on the services inside the booking.
* The booking is closed after all required services are completed.
