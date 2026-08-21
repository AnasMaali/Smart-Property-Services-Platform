-- =====================================================================
-- BLUE V1 Phase 12 - Customer Account Deletion
-- ONE additive migration.
-- =====================================================================
--
-- WHAT THIS ADDS (never DROPs, DELETEs, or destructively rewrites any
-- existing business-data row):
--   - `users.deleted_at` - a new NULLable column (existing rows get NULL,
--     unaffected) plus its supporting index and CHECK constraint.
--
-- WHY A NEW COLUMN, NOT A NEW `user_account_statuses` ROW:
-- Account deletion (App\Actions\Auth\DeleteAccountAction) already reuses
-- the existing `DEACTIVATED` account_status_id to block all future login/
-- authenticated-request paths - see App\Http\Middleware\AuthenticateCustomer
-- and App\Actions\Auth\LoginAction, both of which already require
-- account_status_id = ACTIVE. That alone is sufficient to make a deleted
-- account unusable, so no new status row is needed for that purpose. But
-- `DEACTIVATED` is a general-purpose status this schema already reserves
-- for other reasons (see user_account_statuses.description, "temporarily
-- prevented" language) - reusing it for real Apple-style account deletion
-- would make "temporarily deactivated" and "permanently, irreversibly
-- deleted with anonymized PII" indistinguishable in the database the
-- moment any other feature ever sets DEACTIVATED for a different reason.
-- `deleted_at` is the one unambiguous, permanent marker of real deletion,
-- independent of whatever account_status_id ends up meaning over time.
--
-- MYSQL DDL TRANSACTION SEMANTICS (read this before running):
-- ALTER TABLE causes an implicit COMMIT in MySQL/InnoDB - there is no such
-- thing as a transactional, all-or-nothing DDL script. This file is safe
-- to run (or re-run) at any time regardless, because:
--   1. The one ALTER TABLE below is purely additive (a nullable column,
--      its index, and a CHECK that is trivially satisfied by every
--      existing NULL value) - nothing after it can destroy or corrupt
--      existing data.
--   2. It is idempotently guarded (an information_schema existence check
--      before ADD COLUMN, exactly like every guarded ADD COLUMN in
--      database/phase11_contract_billing_migration.sql) - re-running this
--      file after a partial failure, or against a database that already
--      has the column, is always a safe no-op.
--
-- HOW TO RUN:
-- One-time schema change requires DDL-capable credentials (the
-- application's runtime `blue_app` user is intentionally least-privilege
-- and cannot ALTER TABLE) - see backend/README.md "BLUE Test Database".
--
--   mysql -h <host> -u <ddl-capable-user> -p <database> < database/phase12_account_deletion_migration.sql
--
-- =====================================================================

SET @phase12_col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'deleted_at'
);

SET @phase12_sql = IF(
    @phase12_col_exists = 0,
    CONCAT(
        'ALTER TABLE `users` ',
        'ADD COLUMN `deleted_at` datetime(6) DEFAULT NULL AFTER `last_login_at`, ',
        'ADD KEY `idx_users_deleted_at` (`deleted_at`), ',
        'ADD CONSTRAINT `chk_users_deleted_at` CHECK ',
        '(((`deleted_at` is null) or (`deleted_at` >= `created_at`)))'
    ),
    'SELECT 1'
);
PREPARE phase12_stmt FROM @phase12_sql;
EXECUTE phase12_stmt;
DEALLOCATE PREPARE phase12_stmt;
