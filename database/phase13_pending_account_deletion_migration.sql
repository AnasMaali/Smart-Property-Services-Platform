-- =====================================================================
-- BLUE V1 Phase 13 - Deferred / Pending Customer Account Deletion
-- ONE additive migration.
-- =====================================================================
--
-- WHAT THIS ADDS (never DROPs, DELETEs, or destructively rewrites any
-- existing business-data row):
--   - `customer_account_deletion_requests` - a brand new table
--     (CREATE TABLE IF NOT EXISTS) with no pre-existing data to affect.
--
-- WHY THIS TABLE EXISTS:
-- App\Actions\Auth\DeleteAccountAction (Phase 12) already performs real
-- Apple-compliant account erasure, but until now it refused deletion
-- outright (409, no recourse) whenever the customer had a non-terminal
-- Booking/Contract or an open Payment. That is a compliance gap relative
-- to Apple App Store guideline 5.1.1(v), which requires a customer be
-- able to *initiate* deletion even when completion must wait. This table
-- is the durable record of that initiation: one row per customer who
-- requested deletion while blocked, `completed_at IS NULL` while the
-- request is still PENDING, resolved automatically once
-- App\Console\Commands\ProcessPendingAccountDeletions confirms the
-- blocking obligation has reached a terminal state.
--
-- WHY A SEPARATE TABLE, NOT NEW `users` COLUMNS:
-- The pending-request state is fundamentally a queue/ledger concern
-- (one row, independently timestamped, independently queryable by the
-- scheduled processor) rather than an intrinsic property of the `users`
-- row itself - keeping it separate means the existing Phase 12
-- `users.deleted_at` / `account_status_id` semantics (see
-- database/phase12_account_deletion_migration.sql) need no changes at
-- all, and the request row can legitimately keep existing (as the
-- historical record of "this customer asked to be deleted, and when")
-- after the `users` row it references has been tombstoned.
--
-- WHAT IS DELIBERATELY NOT STORED HERE (see
-- App\Actions\Auth\DeleteAccountAction / App\Support\Auth\
-- AccountDeletionRequestStore): no `current_password`, no password hash
-- copy, no phone number, no email, no raw OTP, no Stripe/provider
-- credentials, no arbitrary request JSON. `user_id` is the only personal
-- reference this table ever holds, and it stops resolving to any live
-- account PII the moment deletion actually completes.
--
-- MYSQL DDL TRANSACTION SEMANTICS (read this before running):
-- CREATE TABLE causes an implicit COMMIT in MySQL/InnoDB - there is no
-- such thing as a transactional, all-or-nothing DDL script. This file is
-- safe to run (or re-run) at any time regardless, because the one
-- CREATE TABLE below is guarded by IF NOT EXISTS and introduces a brand
-- new table with zero pre-existing rows - nothing after it can destroy
-- or corrupt existing data, and re-running this file after a partial
-- failure, or against a database that already has the table, is always
-- a safe no-op.
--
-- HOW TO RUN:
-- One-time schema change requires DDL-capable credentials (the
-- application's runtime `blue_app` user is intentionally least-privilege
-- and cannot CREATE TABLE) - see backend/README.md "BLUE Test Database".
--
--   mysql -h <host> -u <ddl-capable-user> -p <database> < database/phase13_pending_account_deletion_migration.sql
--
-- =====================================================================

CREATE TABLE IF NOT EXISTS `customer_account_deletion_requests` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `user_id` binary(16) NOT NULL,
  `requested_at` datetime(6) NOT NULL,
  `last_checked_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_account_deletion_requests_user` (`user_id`),
  KEY `idx_customer_account_deletion_requests_completed_at` (`completed_at`),
  CONSTRAINT `fk_customer_account_deletion_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_customer_account_deletion_requests_last_checked_at` CHECK (((`last_checked_at` is null) or (`last_checked_at` >= `requested_at`))),
  CONSTRAINT `chk_customer_account_deletion_requests_completed_at` CHECK (((`completed_at` is null) or (`completed_at` >= `requested_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
