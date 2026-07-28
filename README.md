# Smart Property Services Platform

## Overview

Smart Property Services Platform is a mobile and web-based management system designed to help customers request, book, pay for, and manage property-related services in a simple and organized digital way.

The platform allows customers to request regular property services such as maintenance, repair, renovation, cleaning, inspection, painting, plumbing, electrical work, air-conditioning services, carpentry, pest control, and other property-related services.

The platform supports different property types, including apartments, houses, villas, buildings, offices, and other real-estate units.

Instead of manually searching for technicians or service providers, customers can use the mobile application to:

- Create and verify an account.
- Browse service categories.
- Search for services.
- Select the required service.
- Configure service-specific options.
- View the calculated price.
- Add one or more services to the cart.
- Enter property information.
- Select an available appointment.
- Complete online payment.
- Track the Booking status.
- View assigned Technician information.
- Contact Human Support.
- View Booking history.
- Book a previous service again.
- View a basic payment receipt.
- Submit a Rating and optional feedback.

The submitted Bookings are received through the Admin Panel, reviewed by the responsible management team, organized, and assigned to the suitable Technician or Service Employee.

Technicians do not have separate accounts, mobile applications, or dashboards in Version 1. Technician records, specializations, assignments, and service progress are managed by the Admin team.

---

## Main Goal

The main goal of this system is to provide a trusted, fast, and organized digital solution for customers who need property-related services.

The system aims to:

- Save time and effort.
- Simplify the service-booking process.
- Provide clear and configurable service pricing.
- Improve appointment organization.
- Improve Booking and payment management.
- Improve Technician assignment and service tracking.
- Improve Customer Support.
- Help the company manage its operations professionally.
- Store reliable operational data for reports and future business analysis.
- Provide a scalable foundation for future system development.

---

## Version 1 Platform Type

Version 1 will include:

- Customer Mobile Application for iOS.
- Customer Mobile Application for Android.
- Web-Based Admin Panel.
- Backend System and API.
- MySQL Relational Database.
- OTP / SMS Gateway Integration.
- Online Payment Gateway Integration.

A public company website is not included in Version 1.

---

## Customer Mobile Application

The Customer Mobile Application will allow customers to:

- Register and verify their phone number using OTP.
- Log in using a verified phone number and password.
- Reset their password.
- Manage their profile information.
- Browse and search for Services.
- Filter Services by category.
- View Service details and images.
- Select Service options.
- View dynamically calculated prices.
- Add Services to the Cart.
- Edit or remove Cart Items.
- Enter property and visit information.
- Select an available appointment.
- Complete online payment.
- View current and previous Bookings.
- Track Booking and Booking Item statuses.
- View Technician information when assigned.
- View the payment receipt.
- Contact Human Support.
- Rebook completed Services.
- Submit a Rating after Booking completion.

---

## Admin Panel

The Web-Based Admin Panel will allow authorized company employees to:

- Manage Customer accounts.
- Manage Admin accounts, roles, and permissions.
- Manage Service Categories.
- Add, edit, activate, and deactivate Services.
- Manage Service descriptions and images.
- Manage Service options.
- Manage dynamic pricing rules.
- Manage appointment dates and time slots.
- Manage appointment capacity.
- View and search Bookings.
- View Payment records.
- View Customer and property information.
- Manage Technician records.
- Manage Technician statuses.
- Manage Technician specializations.
- Assign Technicians to Booking Items.
- Replace or release Technician assignments.
- Update Booking statuses.
- Update Booking Item statuses.
- View status-change history.
- Manage Human Support Requests.
- View Support messages.
- View Customer Ratings and feedback.
- View basic operational statistics.
- Record important Admin actions through Audit Logs.

---

## Backend System

The Backend System will manage the main platform logic, including:

- Authentication and authorization.
- OTP verification.
- Customer account management.
- Roles and permissions.
- Service management.
- Service option validation.
- Dynamic price calculation.
- Cart management.
- Property information.
- Appointment availability.
- Temporary appointment holds.
- Payment Attempt management.
- Payment Gateway verification.
- Booking creation.
- Booking Item creation.
- Booking status management.
- Technician assignment.
- Human Support.
- Ratings.
- Admin operations.
- Audit logging.

The Customer Mobile Application and Admin Panel will communicate with the shared database through the Backend API.

They will not access the production database directly.

---

## Database

The system uses a MySQL relational database designed for Version 1.

The Database Schema currently contains:

```text
61 Tables
The database includes the following main modules:

Users and Authentication.
Roles and Permissions.
Customer Profiles.
Countries, Cities, and Areas.
Service Categories.
Services.
Service Prices and Price History.
Service Options.
Service Option Types.
Service Option Choices.
Dynamic Pricing Rules.
Measurement Units.
Specializations.
Carts.
Cart Items.
Cart Option Selections.
Property Types.
Cart Locations.
Appointment Slots.
Appointment Holds.
Payment Statuses.
Payment Attempts.
Booking Statuses.
Booking Item Statuses.
Bookings.
Booking Locations.
Booking Items.
Booking Option Snapshots.
Booking Status History.
Booking Item Status History.
Technician Statuses.
Technicians.
Technician Specializations.
Technician Assignments.
Support Request Statuses.
Support Requests.
Support Messages.
Ratings.
Admin Audit Logs.

The database includes:

Primary Keys.
Foreign Keys.
Unique Constraints.
Check Constraints.
Indexes.
Generated Columns.
Historical Price Records.
Booking Snapshots.
Appointment Hold History.
Payment Idempotency.
Technician Assignment History.
Status History.
Audit Records.

Version 1 uses one operational currency:

United Arab Emirates Dirham
Code: AED
Symbol: د.إ

The complete Version 1 Database Schema is stored in:

database/blue_v1_schema.sql

Database documentation is stored in:

database/README.md
Future AI Services

Artificial Intelligence services are documented as future platform capabilities and are not part of the core Version 1 implementation.

The planned AI services include:

1. AI Chatbot

A chatbot that provides quick support to customers, answers questions about the company and Services, and guides customers through the Booking process.

2. Data Analysis Model

A data analysis model that analyzes company operational data to help improve:

Company operations.
Service quality.
Customer satisfaction.
Service demand analysis.
Booking analysis.
Technician performance.
Business decisions.
3. Image Analysis Model

An image analysis model that allows customers to upload a picture of a property problem.

The model may analyze the image to:

Identify a possible issue or damage.
Suggest the possible problem type.
Recommend a suitable Service Category.

AI outputs will be treated as recommendations and not as guaranteed professional diagnoses.

Current Project Status

The project is currently in the planning, documentation, technical design, and database-finalization phase.

The following steps have been completed:

Step 1: Product Discovery and System Vision

The project idea, main goals, target users, platform type, expected value, and future direction were defined.

Step 2: User Roles and Stakeholders

The main system users, stakeholders, and external systems were documented, including:

Customer / One-Time Customer.
Subscription Customer.
Admin / Service Management Team.
Technician / Service Employee.
Super Admin / Company Owner.
AI Services.
Payment Gateway.
OTP / SMS Gateway.
Notification Service.
Step 3: Features and Requirements

The main platform features were documented, including:

Authentication and Account Management.
Service Selection and Booking.
Property Information During Booking.
Payment Flow.
Admin Request Management.
Technician Assignment.
Request Status Tracking.
Human Support.
Rating and Feedback.
Search and Filters.
Booking History and Rebooking.
Technician Contact Information.
Basic Invoice and Receipt.
Step 4: User Flows and Use Cases

The main User Flows and Use Cases were documented, including:

Customer Registration and Login Flow.
Service Search and Cart Flow.
Booking and Property Information Flow.
Payment and Receipt Flow.
Admin Booking Management Flow.
Technician Assignment Flow.
Request Status Tracking Flow.
Booking History and Rebooking Flow.
Human Support Flow.
Rating and Feedback Flow.
Customer Profile Management Flow.
Admin Services and Availability Management Flow.
Step 5: System Requirements

The main system requirements were documented, including:

Functional Requirements.
Non-Functional Requirements.
Security and Privacy Requirements.
Role and Access Control Requirements.
Data Requirements.
External Integration Requirements.
Business Rules.
Step 6: Technical System Design

The technical architecture of the platform was documented.

The current technical design includes:

Customer Mobile Application.
Admin Panel.
Backend API.
Shared Database.
Payment Gateway.
OTP / SMS Gateway.
Logging and Monitoring.
Backup and Recovery.
Step 7: Database Design and Review

The complete Version 1 relational database was designed and reviewed.

The completed database work includes:

61 Tables.
Primary Keys.
Foreign Keys.
Unique Constraints.
Check Constraints.
Indexes.
Generated Columns.
Historical Service Prices.
Dynamic Pricing Rules.
Appointment Hold History.
Payment Attempts.
Payment Idempotency.
Booking and Booking Item Snapshots.
Booking Status History.
Booking Item Status History.
Technician Assignment History.
Support Requests and Messages.
Ratings.
Admin Audit Logs.

Required database corrections were completed, including:

Mandatory and unique Customer email.
Appointment Hold history preservation.
Text Service-option support in Cart selections.
Text Service-option support in Booking snapshots.
AED as the Version 1 currency.
Correct relationships between Customers, Services, Carts, Payments, Bookings, Technicians, Support Requests, Ratings, and Audit Logs.
Step 8: Database Schema Upload

The complete Database Schema was exported and added to the GitHub repository.

The Schema is available in:

database/blue_v1_schema.sql

Database documentation is available in:

database/README.md
Current Repository Structure
Smart-Property-Services-Platform/
│
├── README.md
│
├── 01-product-discovery.md
│
├── database/
│   ├── README.md
│   └── blue_v1_schema.sql
│
└── docs/
    │
    ├── 02-user-roles-and-stakeholders/
    │   ├── 01-customer-one-time-customer.md
    │   ├── 02-subscription-customer.md
    │   ├── 03-admin-service-management-team.md
    │   ├── 04-technician-service-employee.md
    │   ├── 05-super-admin-company-owner.md
    │   ├── 06-ai-services.md
    │   ├── 07-payment-gateway.md
    │   ├── 08-otp-sms-gateway.md
    │   └── 09-notification-service.md
    │
    ├── 03-features-and-requirements/
    │   ├── 01-authentication-and-account-management.md
    │   ├── 02-authentication-and-account-management.md
    │   ├── 03-service-selection-and-booking.md
    │   ├── 04-property-information-during-booking.md
    │   ├── 05-payment-flow.md
    │   ├── 06-admin-request-management.md
    │   ├── 07-technician-assignment.md
    │   ├── 08-request-status-tracking.md
    │   ├── 09-human-support.md
    │   ├── 10-rating-and-feedback.md
    │   ├── 11-search-and-filters.md
    │   ├── 12-booking-history-and-rebooking.md
    │   ├── 13-technician-contact-information.md
    │   └── 14-basic-invoice-and-receipt.md
    │
    ├── 04-user-flows-and-use-cases/
    │   ├── 01-customer-registration-and-login-flow.md
    │   ├── 02-service-search-and-cart-flow.md
    │   ├── 03-booking-and-property-information-flow.md
    │   ├── 04-payment-and-receipt-flow.md
    │   ├── 05-admin-booking-management-flow.md
    │   ├── 06-technician-assignment-flow.md
    │   ├── 07-request-status-tracking-flow.md
    │   ├── 08-booking-history-and-rebooking-flow.md
    │   ├── 09-human-support-flow.md
    │   ├── 10-rating-and-feedback-flow.md
    │   ├── 11-customer-profile-management-flow.md
    │   ├── 12-admin-services-and-availability-management-flow.md
    │   └── customer registration and login flow.png
    │
    ├── 05-system-requirements/
    │   ├── 01-functional-requirements.md
    │   ├── 02-non-functional-requirements.md
    │   ├── 03-security-and-privacy-requirements.md
    │   ├── 04-role-and-access-control-requirements.md
    │   ├── 05-data-requirements.md
    │   ├── 06-external-integration-requirements.md
    │   └── 07-business-rules.md
    │
    └── 06-technical-system-design/
        └── 01-system-architecture.md

The current project phase is:

Database Finalization and Seed Data Preparation

The next planned step is:

Create and test the Version 1 Database Seed Data
