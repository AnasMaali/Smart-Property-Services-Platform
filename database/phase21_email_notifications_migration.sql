-- =====================================================================
-- BLUE V1 Phase B22 - Technician + Customer Email Notifications
-- Additive, idempotent schema changes on the EXISTING `outbound_notifications`
-- / `outbound_notification_statuses` tables (database/
-- phase20_technician_notifications_migration.sql) - never a second,
-- parallel `email_notifications` table. Reuses the exact same durable
-- delivery-obligation ledger the WhatsApp Technician notifications already
-- use (channel/recipient_type/notification_type are generic by design -
-- see that migration file's own docblock), so a V2 PUSH/IN_APP channel can
-- reuse this same table again later.
--
-- WHAT THIS CHANGES (never DROPs, DELETEs, or destructively rewrites any
-- existing row):
--
--   1. `outbound_notifications.recipient_address_snapshot` widened from
--      varchar(32) ascii (sized only for a WhatsApp E.164 phone number) to
--      varchar(254) utf8mb4 - matching `users.email` / `technicians.email`
--      exactly, since an email address can legitimately exceed 32
--      characters. Every existing (phone-number) value already satisfies
--      the wider column/constraint unchanged.
--
--   2. Four CHECK constraints widened to admit the new EMAIL channel,
--      CUSTOMER recipient type, and four new EMAIL notification types -
--      never a new column, never a new table:
--        - chk_outbound_notifications_channel: + 'EMAIL'
--        - chk_outbound_notifications_recipient_type: + 'CUSTOMER'
--        - chk_outbound_notifications_notification_type: +
--          'TECHNICIAN_NEW_ASSIGNMENT_EMAIL', 'TECHNICIAN_ASSIGNMENT_REMOVED_EMAIL',
--          'CUSTOMER_TECHNICIAN_ASSIGNED_EMAIL', 'CUSTOMER_TECHNICIAN_CHANGED_EMAIL'
--          (kept distinct from the existing WHATSAPP-only
--          'TECHNICIAN_NEW_ASSIGNMENT'/'TECHNICIAN_ASSIGNMENT_REMOVED' codes -
--          `channel` already discriminates provider, so the `_EMAIL` suffix
--          exists only for the two notification_types that would otherwise
--          collide with a WhatsApp row of the same conceptual event)
--        - chk_outbound_notifications_recipient_address: length bound
--          relaxed from "8 and 32" (WhatsApp-phone-shaped only) to "5 and
--          254" (a superset that still admits every existing phone-number
--          row unchanged)
--
-- No changes to `outbound_notification_statuses` - PENDING/SUBMITTED/
-- FAILED/SKIPPED/RECONCILIATION_REQUIRED already cover email's lifecycle
-- too (BLUE V1 email never produces a genuinely provider-ambiguous outcome
-- the way Meta's idempotency-key-less WhatsApp API can - see
-- App\Actions\Notifications\SendEmailNotificationAction's docblock - so
-- RECONCILIATION_REQUIRED is simply never written for an EMAIL row).
--
-- Partial-run-safe via information_schema checks + dynamic SQL (same idiom
-- as phase17_admin_step_up_schema_migration.sql / phase18_appointment_hold_
-- reschedule_schema_migration.sql): every block first checks whether the
-- target state already exists (information_schema.COLUMNS for the column
-- width, or information_schema.TABLE_CONSTRAINTS joined to CHECK_CONSTRAINTS
-- for each CHECK clause - CHECK_CONSTRAINTS alone carries no TABLE_NAME
-- column, so it can only ever be filtered by table via that join) and is a
-- safe no-op if it does.
--
-- Apply with (DDL-capable credentials required):
--   mysql -h <host> -u <ddl_capable_user> -p blue_db < database/phase21_email_notifications_migration.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- SECTION 1 - widen `recipient_address_snapshot` (column)
-- ---------------------------------------------------------------------

SET @col_needs_update := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'outbound_notifications'
      AND COLUMN_NAME = 'recipient_address_snapshot'
      AND (CHARACTER_MAXIMUM_LENGTH <> 254 OR CHARACTER_SET_NAME <> 'utf8mb4')
);

SET @ddl := IF(
    @col_needs_update > 0,
    'ALTER TABLE `outbound_notifications` MODIFY COLUMN `recipient_address_snapshot` varchar(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL',
    'SELECT ''outbound_notifications.recipient_address_snapshot already widened - skipped'' AS status'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- SECTION 2 - chk_outbound_notifications_channel (+ 'EMAIL')
-- ---------------------------------------------------------------------

SET @needs_update := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS tc
    JOIN information_schema.CHECK_CONSTRAINTS cc
      ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
     AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
    WHERE tc.TABLE_SCHEMA = DATABASE()
      AND tc.TABLE_NAME = 'outbound_notifications'
      AND tc.CONSTRAINT_TYPE = 'CHECK'
      AND tc.CONSTRAINT_NAME = 'chk_outbound_notifications_channel'
      AND cc.CHECK_CLAUSE LIKE '%EMAIL%'
);

SET @ddl := IF(
    @needs_update = 0,
    'ALTER TABLE `outbound_notifications` DROP CHECK `chk_outbound_notifications_channel`, ADD CONSTRAINT `chk_outbound_notifications_channel` CHECK ((`channel` in (_utf8mb4''WHATSAPP'',_utf8mb4''EMAIL'')))',
    'SELECT ''chk_outbound_notifications_channel already includes EMAIL - skipped'' AS status'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- SECTION 3 - chk_outbound_notifications_recipient_type (+ 'CUSTOMER')
-- ---------------------------------------------------------------------

SET @needs_update := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS tc
    JOIN information_schema.CHECK_CONSTRAINTS cc
      ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
     AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
    WHERE tc.TABLE_SCHEMA = DATABASE()
      AND tc.TABLE_NAME = 'outbound_notifications'
      AND tc.CONSTRAINT_TYPE = 'CHECK'
      AND tc.CONSTRAINT_NAME = 'chk_outbound_notifications_recipient_type'
      AND cc.CHECK_CLAUSE LIKE '%CUSTOMER%'
);

SET @ddl := IF(
    @needs_update = 0,
    'ALTER TABLE `outbound_notifications` DROP CHECK `chk_outbound_notifications_recipient_type`, ADD CONSTRAINT `chk_outbound_notifications_recipient_type` CHECK ((`recipient_type` in (_utf8mb4''TECHNICIAN'',_utf8mb4''CUSTOMER'')))',
    'SELECT ''chk_outbound_notifications_recipient_type already includes CUSTOMER - skipped'' AS status'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- SECTION 4 - chk_outbound_notifications_notification_type (+ 4 EMAIL types)
-- ---------------------------------------------------------------------

SET @needs_update := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS tc
    JOIN information_schema.CHECK_CONSTRAINTS cc
      ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
     AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
    WHERE tc.TABLE_SCHEMA = DATABASE()
      AND tc.TABLE_NAME = 'outbound_notifications'
      AND tc.CONSTRAINT_TYPE = 'CHECK'
      AND tc.CONSTRAINT_NAME = 'chk_outbound_notifications_notification_type'
      AND cc.CHECK_CLAUSE LIKE '%CUSTOMER_TECHNICIAN_CHANGED_EMAIL%'
);

SET @ddl := IF(
    @needs_update = 0,
    'ALTER TABLE `outbound_notifications` DROP CHECK `chk_outbound_notifications_notification_type`, ADD CONSTRAINT `chk_outbound_notifications_notification_type` CHECK ((`notification_type` in (_utf8mb4''TECHNICIAN_NEW_ASSIGNMENT'',_utf8mb4''TECHNICIAN_ASSIGNMENT_REMOVED'',_utf8mb4''TECHNICIAN_NEW_ASSIGNMENT_EMAIL'',_utf8mb4''TECHNICIAN_ASSIGNMENT_REMOVED_EMAIL'',_utf8mb4''CUSTOMER_TECHNICIAN_ASSIGNED_EMAIL'',_utf8mb4''CUSTOMER_TECHNICIAN_CHANGED_EMAIL'')))',
    'SELECT ''chk_outbound_notifications_notification_type already includes the EMAIL types - skipped'' AS status'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- SECTION 5 - chk_outbound_notifications_recipient_address (length 5-254)
-- ---------------------------------------------------------------------

SET @needs_update := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS tc
    JOIN information_schema.CHECK_CONSTRAINTS cc
      ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
     AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
    WHERE tc.TABLE_SCHEMA = DATABASE()
      AND tc.TABLE_NAME = 'outbound_notifications'
      AND tc.CONSTRAINT_TYPE = 'CHECK'
      AND tc.CONSTRAINT_NAME = 'chk_outbound_notifications_recipient_address'
      AND cc.CHECK_CLAUSE LIKE '%254%'
);

SET @ddl := IF(
    @needs_update = 0,
    'ALTER TABLE `outbound_notifications` DROP CHECK `chk_outbound_notifications_recipient_address`, ADD CONSTRAINT `chk_outbound_notifications_recipient_address` CHECK ((char_length(trim(`recipient_address_snapshot`)) between 5 and 254))',
    'SELECT ''chk_outbound_notifications_recipient_address already widened - skipped'' AS status'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
