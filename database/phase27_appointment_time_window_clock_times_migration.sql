-- =====================================================================
-- BLUE V1 Phase B27 - Appointment Time Window Clock Times
-- ONE additive ALTER TABLE change, made partial-run-safe via
-- information_schema checks + dynamic SQL (same idiom as
-- phase18_appointment_hold_reschedule_schema_migration.sql /
-- phase17_admin_step_up_schema_migration.sql - MySQL has no
-- `ADD COLUMN/CONSTRAINT IF NOT EXISTS`, so every DDL statement here is
-- guarded by an information_schema existence check before it runs).
--
-- Migration number audited across every local and remote branch on this
-- machine (`git branch -a` + `git ls-tree` per branch, plus a filesystem
-- scan of every sibling worktree for an uncommitted phase27+ file) before
-- being chosen - phase26 was the highest number found anywhere; no
-- phase27 exists on any branch or worktree at the time this file was
-- written.
-- =====================================================================
--
-- WHAT THIS ADDS (never DROPs, DELETEs, or destructively rewrites any
-- existing row):
--   `appointment_time_windows.start_time` / `.end_time` - TIME columns
--   giving each reusable time-window template (e.g. code=W_0900_1100,
--   name="09:00-11:00") an actual, authoritative clock-time definition.
--   Today this table has `code`/`name`/`description` only - a human-
--   readable label with no machine-readable time information at all (see
--   database/blue_v1_schema.sql's CREATE TABLE), so nothing can derive
--   "09:00" from "W_0900_1100" except by parsing the code string, which
--   BLUE V1 Appointment Schedule Management (this phase) deliberately
--   never does - see App\Actions\Admin\AppointmentSchedule\
--   AdminGenerateAppointmentScheduleAction, which reads start_time/
--   end_time directly.
--
-- WHY THIS IS NECESSARY:
-- The BLUE V1 Admin Final Closure QA audit (see the read-only audit
-- preceding this phase) proved `appointment_time_windows` cannot act as a
-- real schedule template without a stored clock time, and that zero
-- production rows exist for this table today (confirmed empty in both
-- blue_v1_schema.sql's dump and blue_v1_seed.sql) - so this migration adds
-- the columns needed before the six authoritative daily windows are seeded
-- in blue_v1_seed.sql ("2D. APPOINTMENT TIME WINDOWS" below this file's
-- sibling change).
--
-- CONSTRAINT ADDED, AND WHY:
--   `chk_appointment_time_windows_period` CHECK: `start_time < end_time` -
--   forbids a zero-length or inverted window, mirroring the existing
--   `chk_appointment_slots_period` convention on `appointment_slots`
--   (`ends_at > starts_at`) one layer up.
--
-- DEFAULTS: both columns get a placeholder DEFAULT ('00:00:00' /
-- '01:00:00', already satisfying the CHECK) purely so this ADD COLUMN
-- can never fail against a pre-existing row on some environment - not
-- because a real template is ever expected to keep the default. Every
-- row this phase actually cares about (the six seeded windows) supplies
-- its own explicit start_time/end_time in the same statement that
-- creates or updates it.
--
-- Apply with (DDL-capable credentials required):
--   mysql -h <host> -u <ddl_capable_user> -p blue_db < database/phase27_appointment_time_window_clock_times_migration.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- SECTION 1 - appointment_time_windows.start_time (column)
-- ---------------------------------------------------------------------

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'appointment_time_windows'
      AND COLUMN_NAME = 'start_time'
);

SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE `appointment_time_windows` ADD COLUMN `start_time` time NOT NULL DEFAULT ''00:00:00'' AFTER `description`',
    'SELECT ''appointment_time_windows.start_time already exists - skipped'' AS status'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- SECTION 2 - appointment_time_windows.end_time (column)
-- ---------------------------------------------------------------------

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'appointment_time_windows'
      AND COLUMN_NAME = 'end_time'
);

SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE `appointment_time_windows` ADD COLUMN `end_time` time NOT NULL DEFAULT ''01:00:00'' AFTER `start_time`',
    'SELECT ''appointment_time_windows.end_time already exists - skipped'' AS status'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- SECTION 3 - chk_appointment_time_windows_period (CHECK constraint)
-- ---------------------------------------------------------------------

SET @chk_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'appointment_time_windows'
      AND CONSTRAINT_NAME = 'chk_appointment_time_windows_period'
      AND CONSTRAINT_TYPE = 'CHECK'
);

SET @ddl := IF(
    @chk_exists = 0,
    'ALTER TABLE `appointment_time_windows` ADD CONSTRAINT `chk_appointment_time_windows_period` CHECK ((`start_time` < `end_time`))',
    'SELECT ''chk_appointment_time_windows_period already exists - skipped'' AS status'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
