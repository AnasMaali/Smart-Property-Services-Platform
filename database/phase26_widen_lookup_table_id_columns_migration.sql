-- =====================================================================
-- BLUE V1 Phase B26 - Widen appointment_time_windows.id / service_zones.id
-- from SMALLINT UNSIGNED to INT UNSIGNED (and their FK columns) to fix a
-- genuine AUTO_INCREMENT exhaustion bug: SMALLINT UNSIGNED tops out at
-- 65535, and InnoDB's AUTO_INCREMENT counter is NOT transactional - it
-- never rewinds when a transaction that INSERTed a row is rolled back.
-- Every Checkout/Technician/Admin test creates a fresh row via
-- Tests\Feature\Checkout\Concerns\CreatesCheckoutFixtures, then rolls its
-- transaction back at the end of the test, so the counter climbs forever
-- across the life of a database even though real row counts stay tiny -
-- it already hit 65535 on `appointment_time_windows`
-- (`Duplicate entry '65535' for key 'appointment_time_windows.PRIMARY'`)
-- and was well on its way on `service_zones` (same fixture-churn pattern
-- via `createServiceZone()` in the same trait, same SMALLINT UNSIGNED PK,
-- AUTO_INCREMENT already past 1700 with zero real rows). INT UNSIGNED
-- (max ~4.29 billion) is the durable fix, and is already the codebase's
-- own established convention for other lookup tables that see the same
-- per-test insert/rollback churn (`service_categories`, `specializations`
-- are both INT UNSIGNED already) - `appointment_time_windows` and
-- `service_zones` were simply undersized outliers. Both tables remain
-- tiny, low-cardinality lookup tables in real production data - only the
-- id WIDTH changes, nothing about their shape or the rows in them.
--
-- Idempotent AND partial-run-safe: each individual ALTER below is guarded
-- by a check of its OWN current state (not a single shared flag), so a
-- re-run after a failure/interruption between statements never tries to
-- DROP a FOREIGN KEY that a prior partial run already dropped, or ADD one
-- that already exists - same guarded-dynamic-SQL idiom as
-- phase17/18/21/22/23/24, applied per-statement here because this
-- migration's DROP FOREIGN KEY / MODIFY COLUMN / ADD CONSTRAINT sequence
-- has more partial-failure states than a single ADD COLUMN does.
--
-- Additive-only in effect - no row is added, changed, or removed, and no
-- data is lost by widening an unsigned integer column.
-- =====================================================================

-- ---------------------------------------------------------------------
-- appointment_time_windows.id + appointment_slots.time_window_id (FK)
-- ---------------------------------------------------------------------

SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'appointment_slots'
      AND CONSTRAINT_NAME = 'fk_appointment_slots_time_window'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @sql := IF(
    @fk_exists > 0,
    'ALTER TABLE `appointment_slots` DROP FOREIGN KEY `fk_appointment_slots_time_window`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @needs_widen := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'appointment_time_windows'
      AND COLUMN_NAME = 'id'
      AND COLUMN_TYPE = 'smallint unsigned'
);

SET @sql := IF(
    @needs_widen > 0,
    'ALTER TABLE `appointment_time_windows` MODIFY COLUMN `id` int unsigned NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @needs_widen := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'appointment_slots'
      AND COLUMN_NAME = 'time_window_id'
      AND COLUMN_TYPE = 'smallint unsigned'
);

SET @sql := IF(
    @needs_widen > 0,
    'ALTER TABLE `appointment_slots` MODIFY COLUMN `time_window_id` int unsigned NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'appointment_slots'
      AND CONSTRAINT_NAME = 'fk_appointment_slots_time_window'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @sql := IF(
    @fk_exists = 0,
    'ALTER TABLE `appointment_slots` ADD CONSTRAINT `fk_appointment_slots_time_window` FOREIGN KEY (`time_window_id`) REFERENCES `appointment_time_windows` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- service_zones.id + service_zone_areas.service_zone_id (FK)
-- ---------------------------------------------------------------------

SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_zone_areas'
      AND CONSTRAINT_NAME = 'fk_service_zone_areas_zone'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @sql := IF(
    @fk_exists > 0,
    'ALTER TABLE `service_zone_areas` DROP FOREIGN KEY `fk_service_zone_areas_zone`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @needs_widen := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_zones'
      AND COLUMN_NAME = 'id'
      AND COLUMN_TYPE = 'smallint unsigned'
);

SET @sql := IF(
    @needs_widen > 0,
    'ALTER TABLE `service_zones` MODIFY COLUMN `id` int unsigned NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @needs_widen := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_zone_areas'
      AND COLUMN_NAME = 'service_zone_id'
      AND COLUMN_TYPE = 'smallint unsigned'
);

SET @sql := IF(
    @needs_widen > 0,
    'ALTER TABLE `service_zone_areas` MODIFY COLUMN `service_zone_id` int unsigned NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_zone_areas'
      AND CONSTRAINT_NAME = 'fk_service_zone_areas_zone'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @sql := IF(
    @fk_exists = 0,
    'ALTER TABLE `service_zone_areas` ADD CONSTRAINT `fk_service_zone_areas_zone` FOREIGN KEY (`service_zone_id`) REFERENCES `service_zones` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
