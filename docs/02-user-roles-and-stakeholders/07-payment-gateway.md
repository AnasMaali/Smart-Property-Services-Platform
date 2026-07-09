# Payment Gateway

## Role Name

**Payment Gateway**

## Arabic Name

**بوابة الدفع**

---

## Role Type

**External System / Non-Human Stakeholder**

---

## Role Status

**Included in Version 1**

---

## Description

The Payment Gateway is an external system used to process customer payments securely.

In Version 1, the customer can book and pay only if the selected service and date/time are available in the system. After the payment is completed successfully, the paid booking request is sent to the admin panel for management and technician assignment.

The Payment Gateway is not a human user. It is a third-party payment service connected to the platform.

---

## Main Purpose

The main purpose of the Payment Gateway is to allow customers to pay for selected services securely before the booking request is managed by the admin.

---

## Version 1 Payment Flow

The Version 1 payment flow is:

1. The customer chooses the required service.
2. The customer chooses an available date and time.
3. The customer enters the property information.
4. The customer reviews and confirms the booking details.
5. The system shows the total service price.
6. The customer pays through the Payment Gateway.
7. The Payment Gateway processes the payment.
8. The Payment Gateway returns the payment result to the system.
9. If payment is successful, the booking becomes a paid request.
10. The paid request appears in the admin panel.
11. The admin reviews the request details and assigns the suitable technician.

---

## Payment Statuses

Possible payment statuses may include:

* Pending
* Successful
* Failed
* Cancelled
* Refunded

---

## System Responsibilities

The system should:

* Send payment details to the Payment Gateway.
* Receive the payment result from the Payment Gateway.
* Mark the booking as paid if the payment is successful.
* Prevent unpaid bookings from being sent as active paid requests to the admin.
* Show payment failure messages to the customer if payment fails.
* Store payment status for each booking.
* Support refund handling in future versions.

---

## Important Version 1 Rule

In Version 1, the booking request should be sent to the admin panel only after successful payment.

The admin does not normally accept or reject the request after payment. The admin reviews and manages the paid booking request.

---

## Refund Note

Refunds may be needed in exceptional cases, such as:

* The service cannot be provided.
* The address is outside the service area.
* The booking information is incorrect.
* The selected service does not match the actual problem.
* The request requires a different price or special inspection.
* The customer cancels within an allowed cancellation period.

A clear refund process can be added in future versions.

---

## Notes

* The Payment Gateway is an external system.
* It is not a human user.
* It is included in Version 1.
* Payment must be completed before the request appears as a paid booking in the admin panel.
* Failed payments should not create active service requests.
* Refund handling can be improved in future versions.
