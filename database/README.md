# BLUE Database — Version 1

This directory contains the MySQL database schema for the BLUE property-services platform.

## Database Information

- Database name: `blue_db`
- Database engine: MySQL
- Recommended version: MySQL 8.0+
- Total tables: 82 (includes BLUE V1 Phase 11 Service Contract Stripe Billing: `service_contract_billing_statuses`, `service_contract_billings`, `service_contract_billing_webhook_events` - see `phase11_contract_billing_migration.sql`)
- Character set: `utf8mb4`
- Version 1 currency: UAE Dirham (`AED`)
- Currency symbol: `د.إ`

## Files

### `blue_v1_schema.sql`

Contains the complete database structure, including:

- Tables
- Primary keys
- Foreign keys
- Unique constraints
- Check constraints
- Indexes
- Generated columns
- Relationships between system modules

The file contains the database structure only and does not include real customer or production data.

## Main Database Modules

- Users, authentication, roles, and permissions
- Customer profiles and locations
- Service categories, services, and pricing
- Service options and dynamic pricing rules
- Cart and Cart Items
- Property and appointment management
- Payment attempts and payment webhook event ledger (Stripe-ready, provider-neutral)
- Bookings and Booking Items (STANDARD payment-backed, or CONTRACT entitlement-backed)
- Customer Properties and long-term Service Contracts (entitlements, acceptance, status history)
- Technician management and assignments
- Support requests and messages
- Ratings and feedback
- Admin audit logs

## Import Instructions

Create the database:

```sql
CREATE DATABASE blue_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_0900_ai_ci;
