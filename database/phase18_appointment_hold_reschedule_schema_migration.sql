-- =====================================================================
-- BLUE V1 Phase B19 - Admin Reschedule Booking Schema
-- ONE additive ALTER TABLE change, made partial-run-safe via
-- information_schema checks + dynamic SQL (same idiom as
-- phase17_admin_step_up_schema_migration.sql - see that file for the full
-- "why not `ADD COLUMN IF NOT EXISTS`" explanation, summarized below).
-- =====================================================================
--
-- WHAT THIS ADDS (never DROPs, DELETEs, or destructively rewrites any
-- existing row):
--   `appointment_holds.superseded_at` - a nullable DATETIME(6) marking a
--   previously-CONVERTED hold as no longer occupying its slot's capacity,
--   because the Booking it represents was moved to a different slot by
--   App\Actions\Admin\Booking\AdminRescheduleBookingAction.
--
-- WHY THIS IS NECESSARY (not a smaller/no-schema-change design):
-- `appointment_holds.converted_at` marks a hold that became a real
-- Booking, and `chk_appointment_holds_final_state` (existing constraint)
-- forbids ever setting `released_at` once `converted_at` is non-null -
-- once converted, a hold's capacity claim is permanent BY DESIGN (the
-- same reason App\Actions\Booking\CancelBookingAction already never
-- touches `appointment_holds` when cancelling a Booking - this is an
-- existing, accepted architectural property, not a gap introduced here).
-- Rescheduling a Booking needs to free its OLD slot's capacity for other
-- customers while still keeping the original hold row as an untouched,
-- permanent historical record (when did this Booking originally occupy
-- that slot, and for how long) - overloading `released_at` for this would
-- either violate the existing CHECK constraint or blur "released before
-- ever becoming a real Booking" with "was a real Booking that later moved
-- elsewhere," two semantically different facts App\Support\Admin\
-- AdminAuditLogger-driven history already keeps separate everywhere else
-- in this Admin module. A new, distinct column is the minimum change that
-- avoids conflating them.
--
-- SCOPE:
-- Schema only. `App\Support\Checkout\AppointmentSlotAvailability`
-- (shared by the customer checkout slot list and the Admin reschedule
-- picker), `App\Actions\Checkout\CreateAppointmentHoldAction`, and
-- `App\Actions\Contract\CreateContractBookingAction` all now also exclude
-- `superseded_at IS NOT NULL` rows from their existing occupancy counts -
-- the same one-line addition to each of their pre-existing
-- `released_at`/`converted_at`/`expires_at` occupancy WHERE clauses, never
-- a new/second capacity calculation. Every existing row's
-- `superseded_at` is NULL (the column's default) - identical in meaning
-- to "never superseded," which is factually correct for every hold that
-- predates this migration.
--
-- CONSTRAINT ADDED, AND WHY:
--   `chk_appointment_holds_superseded_at` CHECK: `superseded_at` may only
--   be set on a hold that was actually converted (a hold that was merely
--   released, or never resolved at all, was never "occupying" anything a
--   reschedule could supersede), and can never predate the `converted_at`
--   moment it is superseding - mirrors the existing
--   `chk_appointment_holds_converted_at`/`chk_appointment_holds_released_at`
--   ordering-CHECK convention on this exact table.
--
-- Apply with (DDL-capable credentials required):
--   mysql -h <host> -u <ddl_capable_user> -p blue_db < database/phase18_appointment_hold_reschedule_schema_migration.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- SECTION 1 - appointment_holds.superseded_at (column)
-- ---------------------------------------------------------------------

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'appointment_holds'
      AND COLUMN_NAME = 'superseded_at'
);

SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE `appointment_holds` ADD COLUMN `superseded_at` datetime(6) DEFAULT NULL AFTER `converted_at`',
    'SELECT ''appointment_holds.superseded_at already exists - skipped'' AS status'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- SECTION 2 - chk_appointment_holds_superseded_at (CHECK constraint)
-- ---------------------------------------------------------------------

SET @chk_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'appointment_holds'
      AND CONSTRAINT_NAME = 'chk_appointment_holds_superseded_at'
      AND CONSTRAINT_TYPE = 'CHECK'
);

SET @ddl := IF(
    @chk_exists = 0,
    'ALTER TABLE `appointment_holds` ADD CONSTRAINT `chk_appointment_holds_superseded_at` CHECK ((`superseded_at` is null) or ((`converted_at` is not null) and (`superseded_at` >= `converted_at`)))',
    'SELECT ''chk_appointment_holds_superseded_at already exists - skipped'' AS status'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
