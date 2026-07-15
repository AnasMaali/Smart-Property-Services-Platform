# Business Rules — Version 1

## Purpose

This document defines the essential business rules that control the main operations of Version 1.

## Core Business Rules

### BR-01

A Customer must verify the phone number using OTP before the account becomes active.

### BR-02

OTP is required for account registration and phone number changes only, not for every normal login.

### BR-03

Only active services and categories may be displayed or added to the Cart.

### BR-04

A Cart may contain one or more services, but all services must use the same property information and appointment.

### BR-05

The Backend must recalculate and verify service prices, Cart total, service availability, and appointment availability before payment.

### BR-06

The Customer pays once for the total Cart amount.

### BR-07

A paid Booking is created only after trusted Payment Gateway confirmation.

### BR-08

One successful payment must create only one Booking and one Receipt.

### BR-09

Failed, cancelled, or pending payments must not create an active paid Booking.

### BR-10

Each service inside the paid Booking becomes a separate Booking Item.

### BR-11

Historical Booking Item prices and service details must not change when current service information is updated.

### BR-12

The Admin manages paid Bookings and assigns the suitable Technician to each Booking Item.

### BR-13

Technicians do not have system accounts in Version 1. The Admin updates service progress on their behalf.

### BR-14

Different Booking Items inside the same Booking may be assigned to different Technicians.

### BR-15

The Customer may view Booking statuses but cannot modify them.

### BR-16

A Booking becomes Completed only after all required Booking Items are completed.

### BR-17

The Customer cannot directly cancel a paid Booking. Exceptional cancellation cases are handled through Human Support and the Admin.

### BR-18

The Book Again option creates a new Cart using currently active services and current prices.

### BR-19

A Customer may submit one overall Rating only after the related Booking is completed.

### BR-20

Customers may access only their own account, Bookings, Receipts, Support messages, and Ratings.

### BR-21

Admin permissions must be enforced by the Backend and not only by hiding Admin screens or buttons.

### BR-22

Passwords, readable OTP codes, and complete payment card information must not be stored.

### BR-23

The mobile application, Admin Management Interface, and portfolio website must access shared data through the Backend API.

### BR-24

Property information is entered during Booking only and is not saved as a managed Customer property in Version 1.
