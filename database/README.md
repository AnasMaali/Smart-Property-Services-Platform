# BLUE Database — Version 1

This directory contains the MySQL database schema for the BLUE property-services platform.

## Database Information

- Database name: `blue_db`
- Database engine: MySQL
- Recommended version: MySQL 8.0+
- Total tables: 104 (includes BLUE V1 Phase 11 Service Contract Stripe Billing: `service_contract_billing_statuses`, `service_contract_billings`, `service_contract_billing_webhook_events` - see `phase11_contract_billing_migration.sql`; BLUE V1 Phase A1 Admin Authorization Foundation: `admin_permissions`, `admin_role_permissions` - see `phase15_admin_permission_foundation_migration.sql`; BLUE V1 Phase A2.1 Admin WebAuthn/MFA Schema Foundation: `admin_webauthn_challenge_purposes`, `admin_webauthn_challenges`, `admin_webauthn_credentials` - see `phase16_admin_webauthn_mfa_schema_migration.sql`; BLUE V1 Phase B20 Automated Booking Refunds via Stripe: `booking_refund_statuses`, `booking_refunds` - see `phase19_booking_refund_automation_migration.sql`; BLUE V1 Phase B23 Categories + Services Full Admin Management - no new table, `services` gains an additive `original_price` column - see `phase22_catalog_admin_management_migration.sql`; BLUE V1 Phase B23-ext Catalog Model Extension - `services` gains `is_featured`/`estimated_duration_minutes`/`min_quantity`/`max_quantity`, plus 7 new tables (`service_option_choice_attribute_types`, `service_option_choice_attributes`, `service_content_section_types`, `service_content_sections`, `service_checkpoint_action_types`, `service_checkpoint_groups`, `service_checkpoints`) - see `phase23_catalog_model_extension_migration.sql`; BLUE V1 Phase B24 Service Payment Policy + Card / Apple Pay / Pay on Site - `bookings` gains additive `payment_method_code`/`idempotency_key` columns (plus a `PAY_ON_SITE` branch on `chk_bookings_source_pairing`), `booking_sources` gains `PAY_ON_SITE` and `booking_statuses` gains `CONFIRMED`, plus 3 new tables (`payment_method_types`, `service_payment_methods`, `booking_on_site_settlements`) - see `phase24_payment_policy_migration.sql` and, for environments that already had the Phase B24 base migration applied before the source-pairing gap was found, `phase24_payment_policy_fix_chk_bookings_source_pairing.sql`; and BLUE V1 Phase B25 Inspection → Repair Quote → Historical Credit → Remaining Balance - `services` gains an additive `inspection_quote_credit_enabled` column, plus 4 new tables (`booking_item_repair_quote_statuses`, `booking_item_repair_quotes`, `repair_quote_credits`, `repair_quote_payment_attempts`) - see `phase25_inspection_quote_credit_migration.sql`)
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
- Admin WebAuthn/MFA credentials and challenge state (schema foundation only — see `docs/api-contracts/admin-webauthn-mfa-v1.md`)

## Import Instructions

### Fresh environment

Create the database, then import the structure and the reference/seed data, in that order:

```sql
CREATE DATABASE blue_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_0900_ai_ci;
```

```bash
mysql -u <ddl_capable_user> -p blue_db < blue_v1_schema.sql
mysql -u <ddl_capable_user> -p blue_db < blue_v1_seed.sql
```

`blue_v1_schema.sql` already contains the final, current structure - including every phase's changes, up to and including BLUE V1 Phase B25 - so a fresh install never needs to run any of the numbered `phaseNN_*.sql` migration files individually.

### Existing environment (applying a new phase)

An environment that already has an earlier phase's structure applies only the new phase's migration file(s) directly against `blue_db`, in phase-number order. Every `phaseNN_*.sql` file in this directory is written to be additive and idempotent (safe to re-run), following the same information_schema + `PREPARE`/`EXECUTE` guarded-DDL convention for `ALTER TABLE` and `CREATE TABLE IF NOT EXISTS` for new tables - see the comment header of any `phaseNN_*.sql` file for the specifics of what it changes.

**Phase B24 is two migration files, applied in order, both idempotent:**

1. `phase24_payment_policy_migration.sql` - adds `payment_method_types`, `service_payment_methods`, `booking_on_site_settlements`, the `PAY_ON_SITE` value of `booking_sources` and `CONFIRMED` value of `booking_statuses`, and `bookings.payment_method_code`/`bookings.idempotency_key`.
2. `phase24_payment_policy_fix_chk_bookings_source_pairing.sql` - a small corrective follow-up, needed because `chk_bookings_source_pairing` (added by an earlier phase, before Phase B24 existed) only recognized the STANDARD/CONTRACT funding shapes and rejected every Pay-on-Site Booking with MySQL error 3819. It adds the missing third branch to that CHECK constraint.

Both files are safe to run against any environment regardless of history: each checks `information_schema` first and is a no-op if its change is already present. An environment built from the current `blue_v1_schema.sql` (which already has the corrected 3-branch `chk_bookings_source_pairing`) can run both files with no effect; an environment that already applied file 1 before file 2 existed only needs file 2.

**Phase B25** is one migration file: `phase25_inspection_quote_credit_migration.sql` - adds `services.inspection_quote_credit_enabled`, `booking_item_repair_quote_statuses`, `booking_item_repair_quotes`, `repair_quote_credits`, and `repair_quote_payment_attempts`. Idempotent like every other `phaseNN_*.sql` file; safe to re-run.
