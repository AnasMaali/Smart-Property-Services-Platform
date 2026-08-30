-- BLUE V1 Phase 24 - fix for a pre-existing CHECK constraint gap discovered
-- while testing the Pay-on-Site booking-creation path.
--
-- `chk_bookings_source_pairing` (added before Phase 24) only recognised two
-- funding mechanisms for a Booking:
--   1. payment_attempt_id IS NOT NULL, no Service Contract linkage
--   2. payment_attempt_id IS NULL, WITH a Service Contract linkage
-- Every Pay-on-Site Booking has payment_attempt_id NULL and no Service
-- Contract linkage (it is funded by cash/card collected on site, tracked in
-- the new `booking_on_site_settlements` table instead), so every attempt to
-- insert one is rejected by MySQL error 3819 before application code ever
-- runs. This statement adds a third, narrowly-scoped branch that only
-- admits a Booking with none of the other two linkages when it is
-- explicitly tagged payment_method_code = 'PAY_ON_SITE' - no other Booking
-- can bypass the original financial-linkage requirement.
--
-- Idempotent: only drops/recreates the constraint if it does not already
-- have the PAY_ON_SITE branch, so this is safe to re-run.

SET @needs_fix := (
    SELECT COUNT(*)
    FROM information_schema.CHECK_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND CONSTRAINT_NAME = 'chk_bookings_source_pairing'
      AND CHECK_CLAUSE NOT LIKE '%PAY_ON_SITE%'
);

SET @sql := IF(
    @needs_fix > 0,
    'ALTER TABLE bookings DROP CHECK chk_bookings_source_pairing',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @needs_fix > 0,
    'ALTER TABLE bookings ADD CONSTRAINT chk_bookings_source_pairing CHECK (
        ((payment_attempt_id IS NOT NULL) AND (service_contract_id IS NULL) AND (service_contract_item_id IS NULL))
        OR ((payment_attempt_id IS NULL) AND (service_contract_id IS NOT NULL) AND (service_contract_item_id IS NOT NULL))
        OR ((payment_attempt_id IS NULL) AND (service_contract_id IS NULL) AND (service_contract_item_id IS NULL) AND (payment_method_code = ''PAY_ON_SITE''))
    )',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
