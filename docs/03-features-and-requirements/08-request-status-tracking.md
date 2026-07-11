# Request Status Tracking

## Purpose

This document defines how the Customer / One-Time Customer and the Admin / Service Management Team track a booking request from successful payment until all services are completed.

---

## Version 1 Scope

In Version 1, request tracking is available inside the mobile application and the admin management interface.

Notifications are not included in Version 1. The customer can check the latest request status directly inside the application.

---

## Main Booking Statuses

The main booking statuses may include:

* Paid
* Assigned to Technician
* In Progress
* Completed
* Cancelled

---

## Status Meaning

### Paid

The payment was completed successfully, and the booking was sent to the admin management interface.

### Assigned to Technician

The admin assigned the suitable technician or employee to the service or services inside the booking.

### In Progress

One or more services inside the booking are currently being performed.

### Completed

All required services inside the booking have been completed.

### Cancelled

The booking was cancelled due to an exceptional case.

---

## Customer View

The customer should be able to:

* View the current booking status.
* View the selected services.
* View the booking date and time.
* View assigned technician information if available.
* View the status of each service if the booking contains multiple services.

---

## Admin Responsibilities

The admin should be able to:

* View all booking statuses.
* Update the booking status.
* Update the status of each service item if needed.
* Assign technicians before changing the status to Assigned to Technician.
* Mark the booking as completed only after all required services are finished.

---

## Multi-Service Booking Rule

A booking may contain one service or multiple services.

If different services are completed at different times, each service may have its own status.

The general booking status should reflect the overall progress of all services inside the booking.

---

## Important Rules

* The customer can only view request statuses.
* The admin manages and updates request statuses in Version 1.
* The booking becomes Completed only after all required services are completed.
* Notifications can be added in a future version.
* The customer tracks the request directly inside the mobile application.
