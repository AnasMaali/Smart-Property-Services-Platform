# Basic Invoice & Receipt

## Purpose

This feature provides the customer with a basic payment receipt after a successful payment.

---

## Receipt Information

The receipt should include:

* Booking ID
* Customer name
* Selected services
* Price of each service
* Total paid amount
* Payment date
* Payment status
* Payment reference number, if available

---

## Receipt Flow

1. The customer completes the payment.
2. The Payment Gateway returns a successful payment result.
3. The system creates the paid booking request.
4. The system generates a basic receipt.
5. The customer can view the receipt inside the booking details.

---

## Important Rules

* A receipt is generated only after successful payment.
* One receipt is generated for the full cart payment.
* The receipt is connected to the related Booking ID.
* The customer can view the receipt from the booking history.
* PDF download and email delivery can be added in a future version.
