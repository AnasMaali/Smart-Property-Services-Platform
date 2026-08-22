-- =====================================================================
-- BLUE V1 Phase 14 - Customer Passwordless (Phone + OTP) Login
-- ONE additive, DML-only reference-data upgrade.
-- =====================================================================
--
-- WHAT THIS ADDS (never DROPs, DELETEs, or destructively rewrites any
-- existing business-data row):
--   - One reference row in `otp_verification_purposes`: code `LOGIN`.
--
-- WHY THIS FILE EXISTS:
-- App\Actions\Auth\IssueLoginOtpAction / App\Actions\Auth\VerifyLoginOtpAction
-- (the new canonical Customer login flow, see
-- docs/api-contracts/authentication-v1.md §4a-§4c) require a `LOGIN` row to
-- exist in `otp_verification_purposes` before either action can run -
-- both look it up by `code = 'LOGIN'` and throw a RuntimeException if it is
-- missing (App\Actions\Auth\IssueLoginOtpAction::lookupId /
-- App\Actions\Auth\VerifyLoginOtpAction::lookupId). `database/blue_v1_seed.sql`
-- (the authoritative source for a FRESH database) already carries this row,
-- but an already-provisioned/deployed database only re-runs
-- `blue_v1_seed.sql` if an operator chooses to - this file is the
-- standalone, minimal upgrade path for that already-deployed case, mirroring
-- how `phase11`/`phase12`/`phase13` each captured one incremental change
-- rather than requiring a full reference-data resync.
--
-- WHY NO DDL / NO NEW TABLE OR COLUMN:
-- `otp_verifications.purpose_id` already references `otp_verification_purposes.id`
-- generically for any purpose - PHONE_VERIFICATION, PASSWORD_RESET, and
-- PHONE_NUMBER_CHANGE all already work this way. LOGIN needs no schema
-- change at all, only a new reference row using the table's existing
-- structure.
--
-- WHY `id` IS NOT HARD-CODED:
-- `otp_verification_purposes.id` is `tinyint unsigned AUTO_INCREMENT`
-- (see database/blue_v1_schema.sql) - every existing row (PHONE_VERIFICATION,
-- PASSWORD_RESET, PHONE_NUMBER_CHANGE) keeps whatever id it was already
-- assigned; this INSERT lets MySQL assign the next available id to `LOGIN`
-- rather than asserting a specific one, so this file can never collide with
-- or renumber an existing reference row.
--
-- MYSQL TRANSACTION SEMANTICS (read this before running):
-- Unlike phase11/phase12/phase13, this file contains NO DDL (no CREATE
-- TABLE / ALTER TABLE) - only one `INSERT ... ON DUPLICATE KEY UPDATE`
-- against the existing `otp_verification_purposes.code` unique key. It is
-- safe to run (or re-run) at any time: if the `LOGIN` row already exists,
-- this is a no-op update of its own name/description/is_active columns to
-- the values below; if it does not exist, it is inserted once.
--
-- PRIVILEGE REQUIREMENTS (unlike phase11/12/13):
-- This is DML only (INSERT ... ON DUPLICATE KEY UPDATE), not DDL. It does
-- NOT require a DDL-capable/admin MySQL user - the application's runtime
-- least-privilege `blue_app` user (SELECT, INSERT, UPDATE, DELETE only) is
-- sufficient, exactly like syncing `database/blue_v1_seed.sql` reference
-- data is (see backend/README.md "BLUE Test Database").
--
-- HOW TO RUN (against an already-deployed database, with the app's normal
-- least-privilege credentials - no elevated access needed):
--
--   mysql -h <host> -u blue_app -p <database> < database/phase14_login_otp_purpose_migration.sql
--
-- =====================================================================

INSERT INTO otp_verification_purposes (
    code,
    name,
    description,
    is_active
)
VALUES
(
    'LOGIN',
    'Login',
    'Used to authenticate a customer via passwordless phone + OTP login.',
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    is_active = new.is_active;
