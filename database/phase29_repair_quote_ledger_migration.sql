-- =====================================================================
-- BLUE V1 Phase 29 - Repair Quote ledger tables (schema/test-db sync)
-- FOUR additive CREATE TABLE + one lookup seed. Idempotent.
-- =====================================================================
--
-- Production (`blue_db`) already has this family. The committed schema dump
-- and `blue_test_db` did not, so every Admin Financial Dashboard / Ledger
-- / home-dashboard request 500s: App\Support\Admin\
-- AdminFinancialSummaryCalculator and AdminListFinancialLedgerAction SELECT
-- `repair_quote_payment_attempts` (joined to `booking_item_repair_quotes`)
-- unconditionally.
--
-- Columns, indexes, CHECKs, and FKs below are copied from the live
-- `blue_db` tables - not invented. `repair_quote_credits` is included
-- because it exists in production and is the credit ledger the calculator's
-- own docblock documents as MUST NOT be counted as revenue.
--
-- This does NOT add a Repair Quote write API. Empty tables make the
-- existing READ surfaces return 0 instead of SQL errors.
--
-- Apply with (DDL-capable credentials required):
--   mysql -h <host> -u <ddl_capable_user> -p blue_db < database/phase29_repair_quote_ledger_migration.sql
--   mysql -h <host> -u <ddl_capable_user> -p blue_test_db < database/phase29_repair_quote_ledger_migration.sql
--
-- =====================================================================

-- ---------------------------------------------------------------------
-- SECTION 1 - booking_item_repair_quote_statuses
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `booking_item_repair_quote_statuses` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `is_terminal` tinyint(1) NOT NULL DEFAULT '0',
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_item_repair_quote_statuses_code` (`code`),
  KEY `idx_booking_item_repair_quote_statuses_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_biqs_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_biqs_code` CHECK ((char_length(trim(`code`)) between 2 and 50)),
  CONSTRAINT `chk_biqs_description` CHECK (((`description` is null) or (char_length(trim(`description`)) between 2 and 300))),
  CONSTRAINT `chk_biqs_name` CHECK ((char_length(trim(`name`)) between 2 and 120)),
  CONSTRAINT `chk_biqs_terminal` CHECK ((`is_terminal` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- SECTION 2 - booking_item_repair_quotes
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `booking_item_repair_quotes` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `booking_id` binary(16) NOT NULL,
  `booking_item_id` binary(16) NOT NULL,
  `status_id` tinyint unsigned NOT NULL,
  `currency_id` smallint unsigned NOT NULL,
  `quoted_amount` decimal(19,6) NOT NULL,
  `credit_amount` decimal(19,6) NOT NULL,
  `balance_due_amount` decimal(19,6) NOT NULL,
  `supersedes_quote_id` binary(16) DEFAULT NULL,
  `created_by_admin_user_id` binary(16) NOT NULL,
  `sent_at` datetime(6) DEFAULT NULL,
  `accepted_at` datetime(6) DEFAULT NULL,
  `declined_at` datetime(6) DEFAULT NULL,
  `expired_at` datetime(6) DEFAULT NULL,
  `cancelled_at` datetime(6) DEFAULT NULL,
  `closed_at` datetime(6) DEFAULT NULL,
  `active_booking_item_marker` binary(16) GENERATED ALWAYS AS ((case when (`closed_at` is null) then `booking_item_id` else NULL end)) STORED,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_biq_supersedes` (`supersedes_quote_id`),
  UNIQUE KEY `uq_biq_active_booking_item` (`active_booking_item_marker`),
  KEY `idx_biq_booking_item` (`booking_item_id`,`created_at`),
  KEY `idx_biq_booking` (`booking_id`),
  KEY `idx_biq_status` (`status_id`),
  KEY `idx_biq_created_by` (`created_by_admin_user_id`),
  KEY `fk_biq_currency` (`currency_id`),
  CONSTRAINT `fk_biq_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_biq_booking_item` FOREIGN KEY (`booking_item_id`) REFERENCES `booking_items` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_biq_created_by` FOREIGN KEY (`created_by_admin_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_biq_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_biq_status` FOREIGN KEY (`status_id`) REFERENCES `booking_item_repair_quote_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_biq_supersedes` FOREIGN KEY (`supersedes_quote_id`) REFERENCES `booking_item_repair_quotes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_biq_accepted_declined_mutually_exclusive` CHECK (((`accepted_at` is null) or (`declined_at` is null))),
  CONSTRAINT `chk_biq_accepted_requires_sent` CHECK (((`accepted_at` is null) or (`sent_at` is not null))),
  CONSTRAINT `chk_biq_balance_due_amount` CHECK ((`balance_due_amount` >= 0)),
  CONSTRAINT `chk_biq_balance_due_equation` CHECK ((`balance_due_amount` = (`quoted_amount` - `credit_amount`))),
  CONSTRAINT `chk_biq_closed_at` CHECK (((`closed_at` is null) or (`closed_at` >= `created_at`))),
  CONSTRAINT `chk_biq_credit_amount` CHECK ((`credit_amount` >= 0)),
  CONSTRAINT `chk_biq_credit_not_above_quoted` CHECK ((`credit_amount` <= `quoted_amount`)),
  CONSTRAINT `chk_biq_declined_requires_sent` CHECK (((`declined_at` is null) or (`sent_at` is not null))),
  CONSTRAINT `chk_biq_quoted_amount` CHECK ((`quoted_amount` >= 0)),
  CONSTRAINT `chk_biq_sent_requires_no_earlier` CHECK (((`sent_at` is null) or (`sent_at` >= `created_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- SECTION 3 - repair_quote_payment_attempts
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `repair_quote_payment_attempts` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `quote_id` binary(16) NOT NULL,
  `status_id` tinyint unsigned NOT NULL,
  `currency_id` smallint unsigned NOT NULL,
  `reference` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `idempotency_key` binary(32) NOT NULL,
  `provider_code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `provider_session_reference` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `provider_transaction_reference` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `requested_amount` decimal(19,6) NOT NULL,
  `confirmed_amount` decimal(19,6) DEFAULT NULL,
  `payment_method_code` varchar(20) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `provider_status_code` varchar(100) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `failure_code` varchar(100) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `failure_message` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `requires_reconciliation` tinyint(1) NOT NULL DEFAULT '0',
  `reconciliation_reason_code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `successful_at` datetime(6) DEFAULT NULL,
  `finalized_at` datetime(6) DEFAULT NULL,
  `open_quote_marker` binary(16) GENERATED ALWAYS AS ((case when (`finalized_at` is null) then `quote_id` else NULL end)) STORED,
  `status_changed_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rqpa_reference` (`reference`),
  UNIQUE KEY `uq_rqpa_idempotency_key` (`idempotency_key`),
  UNIQUE KEY `uq_rqpa_provider_session` (`provider_code`,`provider_session_reference`),
  UNIQUE KEY `uq_rqpa_provider_transaction` (`provider_code`,`provider_transaction_reference`),
  UNIQUE KEY `uq_rqpa_open_quote` (`open_quote_marker`),
  KEY `idx_rqpa_quote` (`quote_id`,`created_at`),
  KEY `idx_rqpa_status` (`status_id`),
  KEY `idx_rqpa_currency` (`currency_id`),
  CONSTRAINT `fk_rqpa_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_rqpa_quote` FOREIGN KEY (`quote_id`) REFERENCES `booking_item_repair_quotes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_rqpa_status` FOREIGN KEY (`status_id`) REFERENCES `payment_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_rqpa_confirmed_amount` CHECK (((`confirmed_amount` is null) or (`confirmed_amount` >= 0))),
  CONSTRAINT `chk_rqpa_failure_code` CHECK (((`failure_code` is null) or (char_length(trim(`failure_code`)) between 1 and 100))),
  CONSTRAINT `chk_rqpa_failure_message` CHECK (((`failure_message` is null) or (char_length(trim(`failure_message`)) between 2 and 500))),
  CONSTRAINT `chk_rqpa_payment_method_code` CHECK (((`payment_method_code` is null) or (`payment_method_code` in (_utf8mb4'CARD',_utf8mb4'APPLE_PAY')))),
  CONSTRAINT `chk_rqpa_provider_code` CHECK ((char_length(trim(`provider_code`)) between 2 and 50)),
  CONSTRAINT `chk_rqpa_reconciliation_reason` CHECK (((`reconciliation_reason_code` is null) or (`reconciliation_reason_code` in (_utf8mb4'AMOUNT_MISMATCH',_utf8mb4'CURRENCY_MISMATCH',_utf8mb4'UNEXPECTED_PROVIDER_STATE')))),
  CONSTRAINT `chk_rqpa_reconciliation_requires_flag` CHECK (((`reconciliation_reason_code` is null) or (`requires_reconciliation` = 1))),
  CONSTRAINT `chk_rqpa_reference` CHECK ((char_length(trim(`reference`)) between 8 and 64)),
  CONSTRAINT `chk_rqpa_requested_amount` CHECK ((`requested_amount` > 0)),
  CONSTRAINT `chk_rqpa_requires_reconciliation` CHECK ((`requires_reconciliation` in (0,1))),
  CONSTRAINT `chk_rqpa_status_changed` CHECK ((`status_changed_at` >= `created_at`)),
  CONSTRAINT `chk_rqpa_successful_at` CHECK (((`successful_at` is null) or ((`successful_at` >= `created_at`) and (`confirmed_amount` is not null) and (`finalized_at` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- SECTION 4 - repair_quote_credits
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `repair_quote_credits` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `quote_id` binary(16) NOT NULL,
  `source_booking_id` binary(16) NOT NULL,
  `source_booking_item_id` binary(16) NOT NULL,
  `source_payment_attempt_id` binary(16) NOT NULL,
  `amount` decimal(19,6) NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_repair_quote_credits_quote` (`quote_id`),
  KEY `idx_repair_quote_credits_source_item` (`source_booking_item_id`),
  KEY `idx_repair_quote_credits_source_payment` (`source_payment_attempt_id`),
  KEY `fk_repair_quote_credits_source_booking` (`source_booking_id`),
  CONSTRAINT `fk_repair_quote_credits_quote` FOREIGN KEY (`quote_id`) REFERENCES `booking_item_repair_quotes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_repair_quote_credits_source_booking` FOREIGN KEY (`source_booking_id`) REFERENCES `bookings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_repair_quote_credits_source_item` FOREIGN KEY (`source_booking_item_id`) REFERENCES `booking_items` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_repair_quote_credits_source_payment` FOREIGN KEY (`source_payment_attempt_id`) REFERENCES `payment_attempts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_repair_quote_credits_amount` CHECK ((`amount` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- SECTION 5 - status lookup seed (DML, safe for blue_app)
-- ---------------------------------------------------------------------

INSERT INTO booking_item_repair_quote_statuses (
    code,
    name,
    description,
    is_terminal,
    display_order,
    is_active
)
VALUES
(
    'DRAFT',
    'Draft',
    'The quote is being prepared by an Admin and is not yet visible to the customer. Amounts may still be edited.',
    FALSE,
    1,
    TRUE
),
(
    'SENT',
    'Sent',
    'The quote has been sent to the customer. Its amounts are now immutable - a correction requires a new revision.',
    FALSE,
    2,
    TRUE
),
(
    'ACCEPTED',
    'Accepted',
    'The customer accepted the quote. The remaining balance (if any) is now payable.',
    FALSE,
    3,
    TRUE
),
(
    'DECLINED',
    'Declined',
    'The customer declined the quote.',
    TRUE,
    4,
    TRUE
),
(
    'EXPIRED',
    'Expired',
    'The quote was not acted on before it expired.',
    TRUE,
    5,
    TRUE
),
(
    'CANCELLED',
    'Cancelled',
    'The quote was cancelled by an Admin (as a draft withdrawal, or because a revision superseded it).',
    TRUE,
    6,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    is_terminal = new.is_terminal,
    display_order = new.display_order,
    is_active = new.is_active;
