# Service Selection & Booking

## Purpose

This document defines how the Customer / One-Time Customer selects one or more regular services, adds them to a cart, books an available date and time, reviews the full booking, and pays once for the total amount.

This module is required in Version 1 because it represents the main action of the platform: allowing customers without annual subscriptions to request and book regular property-related services.

---

## Version 1 Scope

In Version 1, this module is designed for the **Customer / One-Time Customer** only.

This means the customer does not have an annual subscription or maintenance contract.

Each booking is treated as a separate paid regular service request.

The system should support a cart-based booking flow, where the customer can add one or more regular services to the same booking.

Emergency or dangerous service requests are not included for this customer type in Version 1.

---

## Regular Service Selection

The customer can choose services from the available services in the system.

All services selected by the Customer / One-Time Customer are treated as regular services.

The customer does not choose whether the service is normal or emergency.

Possible service categories may include:

* AC
* Electrical
* Plumbing
* Painting
* Handyman
* Cleaning
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

The exact services and categories can be managed by the Admin / Service Management Team.

---

## Cart-Based Booking

In Version 1, the customer can add one or more regular services to a cart.

The cart allows the customer to select multiple services and pay for them once.

All services inside the cart must belong to the same booking request.

This means that the services in the cart should share:

* The same customer
* The same property information
* The same selected visit date and time
* One total payment

Example:

* AC Cleaning
* Sofa Cleaning
* Curtain Installation

These services can be added to one cart, reviewed together, and paid for once.

---

## Cart Rules

The cart should follow these rules in Version 1:

* The cart is for regular services only.
* The cart is for Customer / One-Time Customer only.
* The cart can contain one service or multiple services.
* All services in the cart must be for the same property.
* All services in the cart must use the same visit date and time.
* The customer pays once for the total cart amount.
* The cart becomes one paid booking request after successful payment.
* Emergency services cannot be added to the cart.
* Subscription or AMC services cannot be added to the cart in Version 1.

---

## Regular Service Details

For regular service booking, the customer should not be required to write a long problem description unless the selected service needs it.

Instead, the service details should be collected through clear UI options.

Examples of service details may include:

* Number of rooms
* Apartment size
* Type of cleaning
* Number of AC units
* Type of repair
* Service add-ons
* Quantity
* Preferred date and time
* Property information

Each service inside the cart may have its own details.

Example:

* AC Cleaning: 2 AC units
* Sofa Cleaning: 1 sofa set
* Curtain Installation: 3 curtains

This makes the booking process faster and easier for the customer.

---

## Property Information

The customer does not add or manage properties in advance.

Property information will be entered during the booking process only.

The same property information applies to all services inside the cart.

The property information details will be documented separately in:

**Property Information During Booking**

---

## Date and Time Selection

The customer chooses one available day, date, and time for the whole cart.

The selected date and time apply to all services inside the cart.

The customer should not be able to book unavailable times.

This helps prevent booking conflicts and keeps the service scheduling process organized.

---

## Cart Review

Before payment, the customer must review and confirm the full cart and booking details.

The review should include:

* Selected services
* Details for each selected service
* Selected date and time
* Property information
* Price of each service
* Total cart price

---

## Payment

The customer pays once for the total cart amount.

After successful payment, the cart becomes one paid booking request.

This paid booking request is sent to the Admin / Service Management Team.

Example:

* AC Cleaning: 40 USD
* Sofa Cleaning: 30 USD
* Curtain Installation: 20 USD
* Total: 90 USD

The customer pays 90 USD once.

---

## Cart-Based Regular Booking Flow

The regular cart-based booking flow is:

1. The customer logs in to the system.
2. The customer browses the available services.
3. The customer chooses a regular service.
4. The customer selects the service details from the UI.
5. The customer adds the service to the cart.
6. The customer can add more regular services to the same cart if needed.
7. The customer enters the property information.
8. The customer chooses one available day, date, and time for the whole cart.
9. The customer reviews the cart and booking details.
10. The customer confirms the booking before payment.
11. The customer pays once for the total cart amount.
12. After successful payment, the cart becomes one paid booking request.
13. The paid booking request is submitted to the admin panel.
14. The admin reviews and manages the paid booking request.
15. The admin assigns the suitable technician or employee for each service if needed.

---

## Admin Responsibility

For paid cart-based booking requests, the admin is responsible for:

* Reviewing the paid booking request.
* Reviewing the selected services inside the booking.
* Reviewing the details of each service.
* Reviewing property information.
* Assigning the suitable technician or employee.
* Assigning different technicians for different services if needed.
* Updating the request status.
* Contacting the customer if more information is needed.
* Following up until the service is completed.

---

## Booking and Booking Items Concept

To support cart-based booking, the system should treat the booking as one main request that can contain one or more services.

The structure should be understood as:

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

Each booking item includes:

* Selected service
* Service details
* Item price
* Assigned technician, if needed
* Item status, if needed

This structure allows the system to support both single-service bookings and multi-service cart bookings.

---

## Important Version 1 Rules

* This module is for Customer / One-Time Customer only.
* All services for this customer type are regular services.
* The customer does not choose between normal and emergency service.
* The customer can add one or more regular services to the cart.
* All services in the cart must be for the same property.
* All services in the cart must share the same selected visit date and time.
* Regular service booking should be simple and based mostly on UI options.
* The customer should not be required to write a problem description unless the service needs it.
* The customer must choose an available date and time.
* The customer can pay only after confirming the cart and booking details.
* The customer pays once for the total cart amount.
* The booking becomes active only after successful payment.
* After payment, the request goes to the Admin / Service Management Team.
* The admin does not normally accept or reject the request after payment.
* The admin reviews and manages the paid request and assigns the technician or technicians.

---

## Not Included in Version 1 for One-Time Customers

The following features are not included for Customer / One-Time Customer in Version 1:

* Emergency service button
* Dangerous case requests
* Emergency description form
* Emergency image upload
* Emergency deposit
* Annual subscriptions
* AMC contracts
* Service packages
* Loyalty points
* Wallet
* Live technician tracking
* AI image analysis
* Chatbot support
* Notifications
* Saved property management

Emergency and dangerous service requests will be planned later for the **Subscription Customer / Annual Contract Customer** role.

---

## Notes

* This module is included in Version 1.
* This module is for regular service booking only.
* This module supports cart-based booking.
* The customer can book one service or multiple services in one cart.
* Property information is entered during the booking process.
* The same property information applies to all services inside the cart.
* The selected date and time apply to all services inside the cart.
* Payment happens after the customer confirms the cart and booking details.
* The paid cart becomes one booking request managed by the Admin / Service Management Team.
