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

In Version 1, the Customer / One-Time Customer can add one or more regular services to a cart, review the full cart, and pay once for the total cart amount.

After the payment is completed successfully, the cart becomes one paid booking request and is sent to the admin panel for management and technician assignment.

The Payment Gateway is not a human user. It is a third-party payment service connected to the platform.

---

## Main Purpose

The main purpose of the Payment Gateway is to allow customers to pay securely for the total amount of their selected services before the booking request is managed by the admin.

---

## Version 1 Payment Flow

The Version 1 payment flow is:

1. The customer chooses one or more regular services.
2. The customer adds the selected services to the cart.
3. The customer enters the property information.
4. The customer chooses one available date and time for the whole cart.
5. The customer reviews the cart and booking details.
6. The system shows the total cart price.
7. The customer pays once through the Payment Gateway.
8. The Payment Gateway processes the payment.
9. The Payment Gateway returns the payment result to the system.
10. If payment is successful, the cart becomes one paid booking request.
11. The paid booking request appears in the admin panel.
12. The admin reviews the booking and assigns the suitable technician or employee for each service if needed.

---

## Cart Payment Rule

The customer pays once for the full cart amount.

The cart may contain one service or multiple services.

Example:

* AC Cleaning: 40 USD
* Sofa Cleaning: 30 USD
* Curtain Installation: 20 USD
* Total Cart Amount: 90 USD

The customer pays 90 USD once.

After successful payment, the system creates one paid booking request that contains all selected services.

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

* Calculate the total cart amount.
* Send payment details to the Payment Gateway.
* Process one payment for the full cart total.
* Receive the payment result from the Payment Gateway.
* Mark the booking as paid if the payment is successful.
* Convert the cart into one paid booking request after successful payment.
* Prevent unpaid carts from being sent as active booking requests to the admin.
* Show payment failure messages to the customer if payment fails.
* Store payment status for each booking.
* Support refund handling in future versions.

---

## Important Version 1 Rule

In Version 1, the booking request should be sent to the admin panel only after successful payment.

The customer pays once for the total cart amount.

The admin does not normally accept or reject the request after payment. The admin reviews and manages the paid booking request.

---

## Refund Note

Refunds may be needed in exceptional cases, such as:

* The service cannot be provided.
* The address is outside the service area.
* The booking information is incorrect.
* The selected service does not match what can be provided.
* The request requires a different price or special inspection.
* The customer cancels within an allowed cancellation period.

A clear refund process can be added in future versions.

---

## Not Included in Version 1

The following payment-related features are not included in Version 1:

* Emergency deposit payment
* Subscription payment
* AMC contract payment
* Wallet balance
* Loyalty points payment
* Gift cards
* Promo balance
* Multiple payment splitting

These features can be planned for future versions.

---

## Notes

* The Payment Gateway is an external system.
* It is not a human user.
* It is included in Version 1.
* Payment is made once for the total cart amount.
* Payment must be completed before the cart becomes a paid booking request.
* Failed payments should not create active booking requests.
* Refund handling can be improved in future versions.
