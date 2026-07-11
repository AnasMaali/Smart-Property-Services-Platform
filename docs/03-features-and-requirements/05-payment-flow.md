# Payment Flow

## Purpose

This document defines how payment works for the Customer / One-Time Customer during the cart-based booking process.

In Version 1, the customer can add one or more regular services to the cart and pay once for the total cart amount.

---

## Version 1 Scope

In Version 1, the payment flow is designed for the **Customer / One-Time Customer** only.

This means:

* No annual subscription payment.
* No AMC contract payment.
* No emergency deposit.
* No wallet.
* No loyalty points.
* No installment payment.

The customer pays only for the regular services selected inside the cart.

---

## Main Payment Rule

The customer must review and confirm the cart before payment.

The customer pays once for the full cart amount.

After successful payment, the cart becomes one paid booking request and is sent to the Admin / Service Management Team.

---

## Cart Payment

The cart may contain one service or multiple regular services.

Example:

* AC Cleaning: 40 USD
* Sofa Cleaning: 30 USD
* Curtain Installation: 20 USD

Total cart amount:

* 90 USD

The customer pays 90 USD once.

---

## Payment Flow

The Version 1 payment flow is:

1. The customer adds one or more regular services to the cart.
2. The customer enters property information.
3. The customer chooses one available date and time for the whole cart.
4. The customer reviews the cart and booking details.
5. The system displays the total cart amount.
6. The customer confirms the booking before payment.
7. The customer pays once through the Payment Gateway.
8. The Payment Gateway processes the payment.
9. The system receives the payment result.
10. If payment is successful, the cart becomes one paid booking request.
11. The paid booking request appears in the admin panel.
12. The admin reviews and manages the paid booking request.

---

## Payment Gateway

The system should connect with a Payment Gateway to process payments securely.

The Payment Gateway is responsible for processing the customer payment and returning the payment result to the system.

---

## Payment Statuses

Possible payment statuses may include:

* Pending
* Successful
* Failed
* Cancelled
* Refunded

---

## Successful Payment

If the payment is successful:

* The cart becomes one paid booking request.
* The booking status becomes active.
* The booking appears in the admin panel.
* The admin can review the booking and assign technicians.
* The customer can track the request status.

---

## Failed Payment

If the payment fails:

* The booking should not be submitted to the admin panel.
* The cart should not become an active booking request.
* The customer should see a clear payment failure message.
* The customer may try payment again.

---

## Cancelled Payment

If the customer cancels the payment:

* The booking should not be submitted.
* The cart can remain available for the customer to review or edit.
* No paid booking request should be created.

---

## Refunds

Refund handling is not a main feature in Version 1, but the system should be ready to support it later.

Refunds may be needed in exceptional cases, such as:

* The service cannot be provided.
* The address is outside the service area.
* The booking information is incorrect.
* The selected service cannot be completed.
* The customer cancels within an allowed cancellation period.

A clear refund policy can be added in future versions.

---

## System Responsibilities

The system should:

* Calculate the total cart amount.
* Display the full payment summary before payment.
* Send payment details to the Payment Gateway.
* Receive the payment result from the Payment Gateway.
* Mark the payment as successful, failed, cancelled, or refunded.
* Convert the cart into a paid booking only after successful payment.
* Prevent unpaid carts from appearing as active booking requests.
* Store payment information for each booking.
* Allow future refund handling.

---

## Admin View After Payment

After successful payment, the admin should be able to view:

* Customer information
* Property information
* Selected services inside the booking
* Details of each service
* Total cart amount
* Payment status
* Booking date and time
* General request status

---

## Not Included in Version 1

The following payment features are not included in Version 1:

* Emergency deposit
* Subscription payment
* AMC contract payment
* Wallet balance
* Loyalty points
* Gift cards
* Promo balance
* Installment payments
* Split payments
* Cash payment handling

These features can be planned for future versions.

---

## Notes

* Payment is required before the booking appears in the admin panel.
* The customer pays once for the full cart amount.
* The cart becomes one paid booking request only after successful payment.
* Failed payments should not create active booking requests.
* Refund handling can be improved in future versions.
