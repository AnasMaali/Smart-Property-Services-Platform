# Customer Registration Data

## Purpose

This document defines the customer information required during account registration.

The goal is to collect useful customer data for account creation, communication, service analysis, and future data analysis, without making the registration process too long or complicated.

---

## Required Registration Data

The customer should provide the following information when creating a new account:

* Full Name
* Phone Number
* Email Address
* Password
* City
* Area
* Customer Type
* Preferred Service Interest (at least one)

All fields listed above are required. Version 1 registration has no optional data fields.

---

## OTP Verification

The phone number must be verified using OTP.

The customer receives a verification code on the entered phone number and must enter the correct code to activate the account.

This helps confirm that the phone number is valid and belongs to the customer.

---

## Customer Type Options

The customer can choose one of the following:

* Property Owner
* Tenant
* Property Manager
* Company / Office Representative
* Other

---

## Preferred Service Interest Options

The customer can choose one or more service categories they may be interested in.

The authoritative list of selectable service categories is the active rows of the
`service_categories` reference table (seeded in `database/blue_v1_seed.sql` and exposed to
clients via `GET /api/v1/reference-data/registration`), not a fixed list in this document. This
avoids two lists drifting out of sync as categories are added, renamed, or retired.

---

## Property Details Rule

The customer should not enter full property details during registration.

Property Type Interest is not part of Version 1 registration. Property type is collected only during the booking process, as part of Property Information.

Property details will be entered during the booking process only.

Examples of property details collected during booking:

* Property type
* Full address
* Floor number
* Apartment or unit number
* Additional location notes

---

## Why This Data Is Useful

This data can help the system and company understand:

* Where most customers are located
* What services customers are most interested in
* What type of customers use the platform
* Which areas may need more service coverage
* How the customer base grows over time

---

## Version 1 Decision

In Version 1, registration should stay simple.

The system should collect only the most useful information needed for account creation, communication, and future analysis.
