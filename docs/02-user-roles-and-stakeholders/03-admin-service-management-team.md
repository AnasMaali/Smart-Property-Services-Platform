# Admin / Service Management Team

## Role Name

**Admin / Service Management Team**

## Arabic Name

**الأدمن / فريق إدارة الخدمات**

---

## Role Status

**Included in Version 1**

---

## Role Description

The Admin / Service Management Team is responsible for managing paid booking requests after they are submitted by customers.

In Version 1, the Customer / One-Time Customer can choose one or more regular services, add them to a cart, enter property information, choose one available date and time for the whole cart, and pay once for the total cart amount.

After successful payment, the cart becomes one paid booking request and appears in the admin panel.

The admin does not normally accept or reject the request after payment. Instead, the admin reviews and manages the paid booking request, checks the services inside the booking, assigns the suitable technician or employee for each service if needed, updates the request status, and follows up until completion.

---

## Main Purpose

The main purpose of this role is to organize service operations and make sure that paid customer bookings are handled correctly from payment until service completion.

---

## Access Point

The Admin accesses the system through:

* Admin Panel / Management Dashboard

---

## Version 1 Booking Scenario

The booking process in Version 1 follows this flow:

1. The customer chooses one or more regular services.
2. The customer adds the selected services to the cart.
3. The customer enters property information.
4. The customer chooses one available date and time for the whole cart.
5. The customer reviews the cart and booking details.
6. The customer pays once for the total cart amount.
7. The paid booking request appears in the admin panel.
8. The admin reviews the booking and its services.
9. The admin assigns the suitable technician or employee for each service if needed.

---

## Admin Permissions

The Admin / Service Management Team can:

* Log in to the admin panel.
* View paid booking requests.
* View booking details.
* View customer information.
* View property information entered during booking.
* View selected services inside each booking.
* View details for each selected service.
* View booking date and time.
* View total cart price.
* View payment status.
* Review and manage paid booking requests.
* Assign the suitable technician or employee.
* Assign different technicians for different services if needed.
* Update request status based on service progress.
* Track active requests.
* Track completed requests.
* Track cancelled requests.
* Contact the customer if more information is needed.
* Manage available services.
* Manage service categories.
* Manage available booking times.
* View customer ratings after service completion.
* Handle customer support requests.

---

## Admin Request Management Flow

1. The admin logs in to the admin panel.
2. The admin views new paid booking requests.
3. The admin reviews the customer information.
4. The admin reviews the property information.
5. The admin reviews the selected services inside the booking.
6. The admin checks the details of each service.
7. The admin checks the selected date, time, and payment status.
8. The admin assigns the suitable technician or employee for each service if needed.
9. The admin updates the request status based on the service progress.
10. The admin contacts the customer if there is missing or unclear information.
11. The admin follows up until all services inside the booking are completed.
12. The admin closes the request after completion.

---

## Booking and Booking Items Concept

To support cart-based booking, the admin should understand that one booking request may contain one or more services.

**Booking**

Represents the full customer request.

It includes:

* Customer information
* Property information
* Visit date and time
* Total price
* Payment status
* General request status

**Booking Items**

Represent the services inside the booking.

Each booking item may include:

* Selected service
* Service details
* Item price
* Assigned technician, if needed
* Item status, if needed

This allows the admin to manage a booking that contains one service or multiple services.

---

## Request Statuses

The admin can update or monitor request statuses such as:

* Paid
* Assigned to Technician
* In Progress
* Completed
* Cancelled

---

## Important Version 1 Rule

In Version 1, the admin does not normally accept or reject a request after payment.

The correct admin responsibility is:

**Review and manage paid booking requests.**

Or:

**Review booking details and assign technicians.**

This means that the customer pays only after confirming the cart, property information, selected date and time, and total price.

---

## Not Included in Version 1

The following features are not included in the admin flow for Version 1:

* Emergency request management
* Dangerous case management
* Emergency image review
* Emergency deposit handling
* Annual subscription management
* AMC contract management
* Service packages
* Loyalty points
* Wallet management
* Live technician tracking
* AI image analysis
* Chatbot management
* Notification management

Emergency and dangerous service requests will be planned later for the **Subscription Customer / Annual Contract Customer** role.

---

## Cancellation Note

Cancellation after payment should happen only in exceptional cases, such as:

* The service cannot be provided.
* The address is outside the service area.
* The booking information is incorrect.
* The selected service does not match what can be provided.
* The request requires a different price or special inspection.

If cancellation happens after payment, the system should later support a clear cancellation and refund process.

---

## Future Notes

The Super Admin / Company Owner role can be added in future versions to manage higher-level permissions, system settings, reports, subscriptions, and business analytics.

Advanced reports and AI-based data analysis can also be added later.
