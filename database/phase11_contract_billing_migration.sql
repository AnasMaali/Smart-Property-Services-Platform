-- =====================================================================
-- BLUE V1 Phase 11 - Service Contract Stripe Billing
-- ONE additive migration.
--
-- WHAT THIS ADDS (never DROPs, DELETEs, or destructively rewrites any
-- existing business-data row):
--   - `customer_profiles.stripe_customer_id` - a new NULLable column
--     (existing rows get NULL, unaffected).
--   - `service_contract_statuses` gains one new row (PENDING_PAYMENT) and
--     re-numbers the existing rows' `display_order` only (their `id`/
--     `code` - the only values any FK or application code ever stores -
--     are untouched). The ON DUPLICATE KEY UPDATE clause only ever
--     touches this small, non-business lookup table, exactly like every
--     prior phase's seed inserts in blue_v1_seed.sql.
--   - `service_contract_billing_statuses`, `service_contract_billings`,
--     `service_contract_billing_webhook_events` are brand new tables
--     (CREATE TABLE IF NOT EXISTS) with no pre-existing data to affect.
--   - `service_contract_billings` gains four new NULLable/defaulted columns
--     (`provider_cancellation_requested_at`, `_last_attempt_at`,
--     `_attempt_count` DEFAULT 0, `billing_suspended_at`), one new index,
--     and three new CHECK constraints - existing rows get NULL/0,
--     unaffected. SECTION 3C guards each of these 8 elements
--     INDEPENDENTLY (its own information_schema existence check per
--     column/index/CHECK, not one guard for all of them), so it converges
--     correctly even from partial/manually-drifted schema states, and is a
--     no-op wherever this migration already added a given element.
--
-- LEGACY-CONTRACT COMPATIBILITY (read this before running):
-- The Phase 11 application code requires a `service_contract_billings`
-- row (with an authorizing status) for CONTRACT Booking eligibility. A
-- pre-Phase-11 Contract that is already APPROVED / PENDING_CUSTOMER_
-- ACCEPTANCE / ACTIVE / SUSPENDED has no such row and no recorded
-- billing_interval/recurring_amount/currency - none of which this
-- migration can safely invent. Applying the schema change anyway would
-- silently strand those Contracts (an ACTIVE legacy Contract would lose
-- CONTRACT Booking eligibility with no way to recover it through this
-- API). SECTION 0 below is a PREFLIGHT ASSERTION that runs BEFORE any
-- ALTER TABLE / CREATE TABLE and hard-aborts the entire script if such a
-- Contract exists, leaving the database completely unchanged. A
-- REQUESTED Contract is safe (an Admin naturally supplies the new
-- required billing fields when they approve it, whenever that happens -
-- before or after this migration). A CANCELLED/EXPIRED Contract is safe
-- to leave without a billing row forever - App\Support\Contract\Billing\
-- ContractBillingPresenter and App\Actions\Contract\CreateContractBookingAction
-- both already treat a missing billing row as "no billing" / "cannot
-- book", never as an error, and a terminal Contract can never reach
-- CreateContractBookingAction's ACTIVE-only gate in the first place.
--
-- If SECTION 0 aborts: an engineer must first decide how to backfill
-- billing terms for the reported Contract(s) - either a one-off Admin
-- action re-collects real billing_interval/recurring_amount/currency for
-- each one (the correct fix, since only a human can supply real
-- commercial terms for a pre-Phase-11 Contract), or - if none of the
-- flagged Contracts should exist in this environment (e.g. a stale/test
-- database) - they are resolved out of band before re-attempting this
-- migration. This migration deliberately never guesses on their behalf.
--
-- MYSQL DDL TRANSACTION SEMANTICS (read this before running):
-- CREATE TABLE and ALTER TABLE both cause an implicit COMMIT in MySQL/
-- InnoDB - there is no such thing as a transactional, all-or-nothing DDL
-- script, in this file or any other. This file therefore does NOT wrap
-- its statements in START TRANSACTION/COMMIT (doing so would be
-- misleading - it would silently commit after the first DDL statement
-- regardless). Safety instead comes from, in order:
--   1. SECTION 0's preflight assertion, which runs and can abort BEFORE
--      any DDL statement - a blocked migration provably leaves the
--      database in its exact prior state.
--   2. Every DDL statement after that point is purely additive
--      (CREATE TABLE IF NOT EXISTS / guarded ADD COLUMN) - there is
--      nothing after SECTION 0 that can destroy or corrupt existing
--      data even if a later statement fails.
--   3. Idempotent guards on every statement (IF NOT EXISTS / an
--      information_schema existence check before ADD COLUMN / ON
--      DUPLICATE KEY UPDATE for seed rows) - re-running this exact file
--      after any partial failure (including a failure partway through
--      SECTIONS 2-6) is always safe and converges to the same end state.
--   4. Correct dependency order (lookup tables before the tables that
--      FK-reference them, columns before the seed rows that populate
--      them).
--
-- Apply with (do NOT pass --force - see SECTION 0 above; --force makes
-- the mysql CLI continue past the preflight assertion's error instead of
-- aborting, defeating its entire purpose):
--   mysql -u <user> -p blue_db < database/phase11_contract_billing_migration.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- SECTION 0 - PREFLIGHT ASSERTION (runs before any DDL)
--
-- Aborts the whole script (mysql CLI batch mode stops at the first
-- error, so nothing below this point ever runs) if any Service Contract
-- is in a status that requires billing configuration but - after this
-- migration would otherwise apply - has no service_contract_billings
-- row. Looks up every status by CODE, never a hardcoded id.
--
-- Two cases, because `service_contract_billings` itself may or may not
-- exist yet (this script is safe to re-run after a prior successful or
-- partially-applied run):
--   - First run (table does not exist yet): NO Contract can possibly
--     have a billing row, so ANY Contract in APPROVED /
--     PENDING_CUSTOMER_ACCEPTANCE / ACTIVE / SUSPENDED blocks the
--     migration outright. PENDING_PAYMENT is deliberately not checked
--     here - that status code does not exist pre-Phase-11, so no
--     Contract can be in it yet.
--   - Re-run (table already exists, e.g. after this same script already
--     applied SECTIONS 1-6 once and is merely being re-run): only a
--     Contract that is STILL missing its billing row blocks - this is
--     what actually makes re-running after a partial failure safe,
--     rather than the preflight permanently blocking Phase 11's own
--     ordinary post-migration business activity (real ACTIVE Contracts
--     created after Phase 11 went live always have a billing row by
--     construction - see App\Actions\Admin\Contract\
--     AdminApproveContractAction). PENDING_PAYMENT is included in this
--     branch defensively, since it can genuinely exist once Phase 11 is
--     live.
--
-- REQUESTED is never checked - safe, because an Admin supplies the new
-- required billing fields whenever they approve it. CANCELLED/EXPIRED
-- are never checked - safe to remain without a billing row forever (see
-- header comment above).
-- ---------------------------------------------------------------------

DROP PROCEDURE IF EXISTS phase11_preflight_assert;

DELIMITER $$

CREATE PROCEDURE phase11_preflight_assert()
BEGIN
    DECLARE billing_table_exists INT DEFAULT 0;
    DECLARE blocking_count INT DEFAULT 0;

    SELECT COUNT(*) INTO billing_table_exists
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_contract_billings';

    IF billing_table_exists = 0 THEN
        SELECT COUNT(*) INTO blocking_count
        FROM service_contracts sc
        JOIN service_contract_statuses scs ON scs.id = sc.status_id
        WHERE scs.code IN ('APPROVED', 'PENDING_CUSTOMER_ACCEPTANCE', 'ACTIVE', 'SUSPENDED');
    ELSE
        SELECT COUNT(*) INTO blocking_count
        FROM service_contracts sc
        JOIN service_contract_statuses scs ON scs.id = sc.status_id
        LEFT JOIN service_contract_billings scb ON scb.service_contract_id = sc.id
        WHERE scs.code IN ('APPROVED', 'PENDING_CUSTOMER_ACCEPTANCE', 'ACTIVE', 'SUSPENDED', 'PENDING_PAYMENT')
          AND scb.id IS NULL;
    END IF;

    IF blocking_count > 0 THEN
        -- MESSAGE_TEXT is hard-capped at 128 characters by MySQL (silently
        -- truncated beyond that, not an error) - kept short and pointed at
        -- this file's header comment, which carries the full explanation
        -- and remediation steps.
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Phase 11 preflight FAILED: legacy Contract(s) need billing terms first - see migration header. Aborting.';
    END IF;
END$$

DELIMITER ;

CALL phase11_preflight_assert();

DROP PROCEDURE IF EXISTS phase11_preflight_assert;

-- ---------------------------------------------------------------------
-- SECTION 1 - customer_profiles.stripe_customer_id
-- One Stripe Customer per BLUE customer, reused across every future
-- Service Contract (see App\Actions\Contract\Billing\
-- CreateContractBillingCheckoutAction "STRIPE CUSTOMER"). Guarded so a
-- re-run never fails with "duplicate column". Existing rows get NULL.
-- ---------------------------------------------------------------------

SET @phase11_col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'customer_profiles'
      AND COLUMN_NAME = 'stripe_customer_id'
);

SET @phase11_sql = IF(
    @phase11_col_exists = 0,
    CONCAT(
        'ALTER TABLE `customer_profiles` ',
        'ADD COLUMN `stripe_customer_id` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER `area_id`, ',
        'ADD UNIQUE KEY `uq_customer_profiles_stripe_customer` (`stripe_customer_id`), ',
        'ADD CONSTRAINT `chk_customer_profiles_stripe_customer` CHECK ',
        '(((`stripe_customer_id` is null) or (char_length(trim(`stripe_customer_id`)) between 1 and 191)))'
    ),
    'SELECT 1'
);
PREPARE phase11_stmt FROM @phase11_sql;
EXECUTE phase11_stmt;
DEALLOCATE PREPARE phase11_stmt;

-- ---------------------------------------------------------------------
-- SECTION 2 - service_contract_billing_statuses (new lookup table)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `service_contract_billing_statuses` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_contract_billing_statuses_code` (`code`),
  KEY `idx_service_contract_billing_statuses_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_service_contract_billing_statuses_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_service_contract_billing_statuses_code` CHECK ((char_length(trim(`code`)) between 2 and 40)),
  CONSTRAINT `chk_service_contract_billing_statuses_description` CHECK (((`description` is null) or (char_length(trim(`description`)) between 2 and 300))),
  CONSTRAINT `chk_service_contract_billing_statuses_name` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- SECTION 3 - service_contract_billings (new table)
--
-- CHECK constraint note (migration-safety review): `cancel_at` and
-- `cancelled_at` are validated INDEPENDENTLY - each is only ever checked
-- against `created_at`, never against each other's nullability. An
-- earlier draft's `(cancel_at is null) or (cancelled_at is null) or
-- (cancel_at >= created_at)` was too weak: because `cancelled_at` starts
-- NULL on every row (it is only set once a cancellation webhook
-- arrives), that clause was permanently true regardless of what
-- `cancel_at` held, so it never actually constrained `cancel_at` at all.
-- `past_due_since` gets the same independent `>= created_at` check for
-- consistency with the rest of this table's date columns.
--
-- A second draft's straight `cancel_at >= created_at` / `cancelled_at >=
-- created_at` (no tolerance) was ALSO wrong, discovered via a real,
-- intermittent test failure: Stripe's own `cancel_at`/`canceled_at` are
-- whole-second Unix timestamps (StripeContractBillingGateway::tsToDatetime()
-- has no fractional part), while `created_at` is datetime(6). A
-- cancellation genuinely reported by Stripe as happening in the very same
-- wall-clock second the row was created could then be rejected purely by
-- truncation - not because anything was actually out of order. Both
-- columns below use a 1-second tolerance for exactly this reason. SECTION
-- 3B further down retrofits this onto a `service_contract_billings` table
-- that already exists from an earlier apply of this file (the inline
-- corrected definition here only takes effect via `CREATE TABLE IF NOT
-- EXISTS` on a database that does not have the table yet).
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `service_contract_billings` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `service_contract_id` binary(16) NOT NULL,
  `provider_code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status_id` tinyint unsigned NOT NULL,
  `billing_interval` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `recurring_amount` decimal(19,6) NOT NULL,
  `currency_id` smallint unsigned NOT NULL,
  `stripe_customer_id` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `stripe_subscription_id` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `stripe_checkout_session_id` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `stripe_checkout_url` varchar(500) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `stripe_price_id` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `stripe_product_id` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `current_period_start` datetime(6) DEFAULT NULL,
  `current_period_end` datetime(6) DEFAULT NULL,
  `past_due_since` datetime(6) DEFAULT NULL,
  `cancel_at` datetime(6) DEFAULT NULL,
  `cancelled_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_contract_billings_contract` (`service_contract_id`),
  UNIQUE KEY `uq_service_contract_billings_subscription` (`stripe_subscription_id`),
  UNIQUE KEY `uq_service_contract_billings_checkout_session` (`stripe_checkout_session_id`),
  KEY `idx_service_contract_billings_status` (`status_id`,`updated_at`),
  KEY `idx_service_contract_billings_currency` (`currency_id`),
  KEY `idx_service_contract_billings_past_due` (`status_id`,`past_due_since`),
  KEY `idx_service_contract_billings_customer` (`stripe_customer_id`),
  CONSTRAINT `fk_service_contract_billings_contract` FOREIGN KEY (`service_contract_id`) REFERENCES `service_contracts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_service_contract_billings_status` FOREIGN KEY (`status_id`) REFERENCES `service_contract_billing_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_service_contract_billings_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_service_contract_billings_provider_code` CHECK ((char_length(trim(`provider_code`)) between 2 and 50)),
  CONSTRAINT `chk_service_contract_billings_interval` CHECK ((`billing_interval` in (_utf8mb4'MONTHLY',_utf8mb4'YEARLY'))),
  CONSTRAINT `chk_service_contract_billings_amount` CHECK ((`recurring_amount` > 0)),
  CONSTRAINT `chk_service_contract_billings_customer_id` CHECK (((`stripe_customer_id` is null) or (char_length(trim(`stripe_customer_id`)) between 1 and 191))),
  CONSTRAINT `chk_service_contract_billings_subscription_id` CHECK (((`stripe_subscription_id` is null) or (char_length(trim(`stripe_subscription_id`)) between 1 and 191))),
  CONSTRAINT `chk_service_contract_billings_checkout_session_id` CHECK (((`stripe_checkout_session_id` is null) or (char_length(trim(`stripe_checkout_session_id`)) between 1 and 191))),
  CONSTRAINT `chk_service_contract_billings_price_id` CHECK (((`stripe_price_id` is null) or (char_length(trim(`stripe_price_id`)) between 1 and 191))),
  CONSTRAINT `chk_service_contract_billings_product_id` CHECK (((`stripe_product_id` is null) or (char_length(trim(`stripe_product_id`)) between 1 and 191))),
  CONSTRAINT `chk_service_contract_billings_period` CHECK (((`current_period_start` is null) or (`current_period_end` is null) or (`current_period_end` > `current_period_start`))),
  CONSTRAINT `chk_service_contract_billings_past_due_since` CHECK (((`past_due_since` is null) or (`past_due_since` >= `created_at`))),
  CONSTRAINT `chk_service_contract_billings_cancel_at` CHECK (((`cancel_at` is null) or (`cancel_at` >= (`created_at` - INTERVAL 1 SECOND)))),
  CONSTRAINT `chk_service_contract_billings_cancelled_at` CHECK (((`cancelled_at` is null) or (`cancelled_at` >= (`created_at` - INTERVAL 1 SECOND))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- SECTION 3B - retrofit the tolerant cancel_at/cancelled_at CHECKs onto a
-- `service_contract_billings` table that already exists from an earlier
-- apply of this file (SECTION 3's CREATE TABLE IF NOT EXISTS is a no-op
-- in that case, so its corrected inline definition never takes effect on
-- its own). Guarded by inspecting the CURRENT CHECK_CLAUSE in
-- information_schema - a no-op once already retrofitted, so safe to
-- re-run. Requires ALTER privilege on this database.
-- ---------------------------------------------------------------------

SET @phase11_cancel_at_needs_fix = (
    SELECT COUNT(*)
    FROM information_schema.CHECK_CONSTRAINTS cc
    JOIN information_schema.TABLE_CONSTRAINTS tc
      ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
    WHERE cc.CONSTRAINT_SCHEMA = DATABASE()
      AND tc.TABLE_NAME = 'service_contract_billings'
      AND cc.CONSTRAINT_NAME = 'chk_service_contract_billings_cancel_at'
      AND cc.CHECK_CLAUSE NOT LIKE '%INTERVAL%'
);

SET @phase11_sql = IF(
    @phase11_cancel_at_needs_fix > 0,
    CONCAT(
        'ALTER TABLE `service_contract_billings` ',
        'DROP CHECK `chk_service_contract_billings_cancel_at`, ',
        'ADD CONSTRAINT `chk_service_contract_billings_cancel_at` CHECK ',
        '(((`cancel_at` is null) or (`cancel_at` >= (`created_at` - INTERVAL 1 SECOND))))'
    ),
    'SELECT 1'
);
PREPARE phase11_stmt2 FROM @phase11_sql;
EXECUTE phase11_stmt2;
DEALLOCATE PREPARE phase11_stmt2;

SET @phase11_cancelled_at_needs_fix = (
    SELECT COUNT(*)
    FROM information_schema.CHECK_CONSTRAINTS cc
    JOIN information_schema.TABLE_CONSTRAINTS tc
      ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
    WHERE cc.CONSTRAINT_SCHEMA = DATABASE()
      AND tc.TABLE_NAME = 'service_contract_billings'
      AND cc.CONSTRAINT_NAME = 'chk_service_contract_billings_cancelled_at'
      AND cc.CHECK_CLAUSE NOT LIKE '%INTERVAL%'
);

SET @phase11_sql = IF(
    @phase11_cancelled_at_needs_fix > 0,
    CONCAT(
        'ALTER TABLE `service_contract_billings` ',
        'DROP CHECK `chk_service_contract_billings_cancelled_at`, ',
        'ADD CONSTRAINT `chk_service_contract_billings_cancelled_at` CHECK ',
        '(((`cancelled_at` is null) or (`cancelled_at` >= (`created_at` - INTERVAL 1 SECOND))))'
    ),
    'SELECT 1'
);
PREPARE phase11_stmt3 FROM @phase11_sql;
EXECUTE phase11_stmt3;
DEALLOCATE PREPARE phase11_stmt3;

-- ---------------------------------------------------------------------
-- SECTION 3C - service_contract_billings: durable provider-cancellation
-- retry columns + billing-suspension recovery marker (BLOCKER-level
-- reliability retrofit, applied AFTER Phase 11 was already live once).
--
-- `provider_cancellation_requested_at` / `_last_attempt_at` /
-- `_attempt_count` make the outbound provider-side Subscription
-- cancellation durable across a provider outage - see
-- App\Actions\Contract\Billing\CancelContractBillingSubscriptionAction and
-- App\Actions\Contract\Billing\RetryPendingContractBillingCancellationsAction
-- docblocks. `billing_suspended_at` distinguishes a Contract suspension
-- caused by App\Actions\Contract\Billing\SuspendContractsPastDueBillingAction
-- (eligible for automatic recovery once billing repairs itself) from one an
-- Admin caused manually (never auto-recovered) - see
-- App\Actions\Contract\Billing\ProcessContractBillingWebhookAction::
-- handleInvoicePaid().
--
-- EACH of the 8 elements below (4 columns, 1 index, 3 CHECK constraints)
-- is guarded INDEPENDENTLY by its own information_schema existence check
-- and added by its own ALTER TABLE statement. This is deliberately
-- NOT "check column A, then assume columns B/C/D + the index + the CHECKs
-- either all exist or none do" (that single-guard shape is only safe for
-- SECTION 1, where every element is always added together in the exact
-- same original CREATE-time migration run) - `service_contract_billings`
-- has now been retrofitted by this section at least once in production, so
-- partial/manual schema drift (e.g. one column added by hand, or a CHECK
-- dropped for maintenance) is a real possibility this section must
-- converge from correctly rather than silently leaving incomplete. Order
-- matters and is enforced by simple top-to-bottom statement order (each
-- guard is evaluated, and any resulting ALTER TABLE executed, before the
-- next one is even prepared):
--   1. The four columns, each independently guarded, in dependency order
--      (`provider_cancellation_last_attempt_at` is added AFTER
--      `provider_cancellation_requested_at`, etc.) - by the time any given
--      column's ALTER runs, every column named in its own `AFTER` clause
--      is guaranteed to already exist, whether it pre-existed or was just
--      added by an earlier step in this same run.
--   2. The pending-cancellation index, guarded independently - only
--      attempted once every column it covers is guaranteed to already
--      exist (step 1 always runs first).
--   3. Each CHECK constraint, guarded independently - only attempted once
--      every column it references is guaranteed to already exist.
-- A completely converged database (state C below) makes every guard below
-- evaluate to "already exists" and the whole section is a no-op.
-- ---------------------------------------------------------------------

-- --- 3C.1 - columns (each independently guarded) -----------------------

SET @phase11_col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_contract_billings'
      AND COLUMN_NAME = 'provider_cancellation_requested_at'
);
SET @phase11_sql = IF(
    @phase11_col_exists = 0,
    'ALTER TABLE `service_contract_billings` ADD COLUMN `provider_cancellation_requested_at` datetime(6) DEFAULT NULL AFTER `cancelled_at`',
    'SELECT 1'
);
PREPARE phase11_stmt4a FROM @phase11_sql;
EXECUTE phase11_stmt4a;
DEALLOCATE PREPARE phase11_stmt4a;

SET @phase11_col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_contract_billings'
      AND COLUMN_NAME = 'provider_cancellation_last_attempt_at'
);
SET @phase11_sql = IF(
    @phase11_col_exists = 0,
    'ALTER TABLE `service_contract_billings` ADD COLUMN `provider_cancellation_last_attempt_at` datetime(6) DEFAULT NULL AFTER `provider_cancellation_requested_at`',
    'SELECT 1'
);
PREPARE phase11_stmt4b FROM @phase11_sql;
EXECUTE phase11_stmt4b;
DEALLOCATE PREPARE phase11_stmt4b;

SET @phase11_col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_contract_billings'
      AND COLUMN_NAME = 'provider_cancellation_attempt_count'
);
SET @phase11_sql = IF(
    @phase11_col_exists = 0,
    'ALTER TABLE `service_contract_billings` ADD COLUMN `provider_cancellation_attempt_count` int unsigned NOT NULL DEFAULT ''0'' AFTER `provider_cancellation_last_attempt_at`',
    'SELECT 1'
);
PREPARE phase11_stmt4c FROM @phase11_sql;
EXECUTE phase11_stmt4c;
DEALLOCATE PREPARE phase11_stmt4c;

SET @phase11_col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_contract_billings'
      AND COLUMN_NAME = 'billing_suspended_at'
);
SET @phase11_sql = IF(
    @phase11_col_exists = 0,
    'ALTER TABLE `service_contract_billings` ADD COLUMN `billing_suspended_at` datetime(6) DEFAULT NULL AFTER `provider_cancellation_attempt_count`',
    'SELECT 1'
);
PREPARE phase11_stmt4d FROM @phase11_sql;
EXECUTE phase11_stmt4d;
DEALLOCATE PREPARE phase11_stmt4d;

-- --- 3C.2 - pending-cancellation index (independently guarded, after ---
-- --- every column it covers is guaranteed to exist) --------------------

SET @phase11_idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'service_contract_billings'
      AND INDEX_NAME = 'idx_service_contract_billings_provider_cancel_pending'
);
SET @phase11_sql = IF(
    @phase11_idx_exists = 0,
    'ALTER TABLE `service_contract_billings` ADD KEY `idx_service_contract_billings_provider_cancel_pending` (`provider_cancellation_requested_at`,`cancelled_at`)',
    'SELECT 1'
);
PREPARE phase11_stmt4e FROM @phase11_sql;
EXECUTE phase11_stmt4e;
DEALLOCATE PREPARE phase11_stmt4e;

-- --- 3C.3 - CHECK constraints (each independently guarded, after -------
-- --- every column it references is guaranteed to exist) ----------------

SET @phase11_chk_exists = (
    SELECT COUNT(*)
    FROM information_schema.CHECK_CONSTRAINTS cc
    JOIN information_schema.TABLE_CONSTRAINTS tc
      ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
    WHERE cc.CONSTRAINT_SCHEMA = DATABASE()
      AND tc.TABLE_NAME = 'service_contract_billings'
      AND cc.CONSTRAINT_NAME = 'chk_service_contract_billings_provider_cancel_requested_at'
);
SET @phase11_sql = IF(
    @phase11_chk_exists = 0,
    CONCAT(
        'ALTER TABLE `service_contract_billings` ',
        'ADD CONSTRAINT `chk_service_contract_billings_provider_cancel_requested_at` CHECK ',
        '(((`provider_cancellation_requested_at` is null) or (`provider_cancellation_requested_at` >= `created_at`)))'
    ),
    'SELECT 1'
);
PREPARE phase11_stmt4f FROM @phase11_sql;
EXECUTE phase11_stmt4f;
DEALLOCATE PREPARE phase11_stmt4f;

SET @phase11_chk_exists = (
    SELECT COUNT(*)
    FROM information_schema.CHECK_CONSTRAINTS cc
    JOIN information_schema.TABLE_CONSTRAINTS tc
      ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
    WHERE cc.CONSTRAINT_SCHEMA = DATABASE()
      AND tc.TABLE_NAME = 'service_contract_billings'
      AND cc.CONSTRAINT_NAME = 'chk_service_contract_billings_provider_cancel_last_attempt_at'
);
SET @phase11_sql = IF(
    @phase11_chk_exists = 0,
    CONCAT(
        'ALTER TABLE `service_contract_billings` ',
        'ADD CONSTRAINT `chk_service_contract_billings_provider_cancel_last_attempt_at` CHECK ',
        '(((`provider_cancellation_last_attempt_at` is null) or (`provider_cancellation_requested_at` is not null and `provider_cancellation_last_attempt_at` >= `provider_cancellation_requested_at`)))'
    ),
    'SELECT 1'
);
PREPARE phase11_stmt4g FROM @phase11_sql;
EXECUTE phase11_stmt4g;
DEALLOCATE PREPARE phase11_stmt4g;

SET @phase11_chk_exists = (
    SELECT COUNT(*)
    FROM information_schema.CHECK_CONSTRAINTS cc
    JOIN information_schema.TABLE_CONSTRAINTS tc
      ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
    WHERE cc.CONSTRAINT_SCHEMA = DATABASE()
      AND tc.TABLE_NAME = 'service_contract_billings'
      AND cc.CONSTRAINT_NAME = 'chk_service_contract_billings_billing_suspended_at'
);
SET @phase11_sql = IF(
    @phase11_chk_exists = 0,
    CONCAT(
        'ALTER TABLE `service_contract_billings` ',
        'ADD CONSTRAINT `chk_service_contract_billings_billing_suspended_at` CHECK ',
        '(((`billing_suspended_at` is null) or (`billing_suspended_at` >= `created_at`)))'
    ),
    'SELECT 1'
);
PREPARE phase11_stmt4h FROM @phase11_sql;
EXECUTE phase11_stmt4h;
DEALLOCATE PREPARE phase11_stmt4h;

-- ---------------------------------------------------------------------
-- SECTION 4 - service_contract_billing_webhook_events (new table)
-- Reuses payment_webhook_event_statuses (RECEIVED/PROCESSED/IGNORED/
-- FAILED) - see blue_v1_schema.sql comment on this table.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `service_contract_billing_webhook_events` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `provider_code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `provider_event_id` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `service_contract_billing_id` binary(16) DEFAULT NULL,
  `event_type` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `provider_object_reference` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `payload_hash` binary(32) NOT NULL,
  `status_id` tinyint unsigned NOT NULL,
  `processing_attempt_count` int unsigned NOT NULL DEFAULT '0',
  `received_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `processed_at` datetime(6) DEFAULT NULL,
  `last_error_code` varchar(100) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `last_error_message` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_contract_billing_webhook_events_provider_event` (`provider_code`,`provider_event_id`),
  KEY `idx_service_contract_billing_webhook_events_billing` (`service_contract_billing_id`),
  KEY `idx_service_contract_billing_webhook_events_object_ref` (`provider_object_reference`),
  KEY `idx_service_contract_billing_webhook_events_status_received` (`status_id`,`received_at`),
  KEY `idx_service_contract_billing_webhook_events_received_at` (`received_at`),
  CONSTRAINT `fk_contract_billing_webhook_events_billing` FOREIGN KEY (`service_contract_billing_id`) REFERENCES `service_contract_billings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contract_billing_webhook_events_status` FOREIGN KEY (`status_id`) REFERENCES `payment_webhook_event_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_contract_billing_webhook_events_provider_code` CHECK ((char_length(trim(`provider_code`)) between 2 and 50)),
  CONSTRAINT `chk_contract_billing_webhook_events_provider_event_id` CHECK ((char_length(trim(`provider_event_id`)) between 1 and 191)),
  CONSTRAINT `chk_contract_billing_webhook_events_event_type` CHECK ((char_length(trim(`event_type`)) between 1 and 100)),
  CONSTRAINT `chk_contract_billing_webhook_events_last_error_code` CHECK (((`last_error_code` is null) or (char_length(trim(`last_error_code`)) between 1 and 100))),
  CONSTRAINT `chk_contract_billing_webhook_events_last_error_message` CHECK (((`last_error_message` is null) or (char_length(trim(`last_error_message`)) between 2 and 500))),
  CONSTRAINT `chk_contract_billing_webhook_events_processed_at` CHECK (((`processed_at` is null) or (`processed_at` >= `received_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- SECTION 5 - service_contract_statuses: add PENDING_PAYMENT
-- Non-destructive UPSERT of the small lookup table only - existing rows'
-- `id`/`code` (the only values ever stored on service_contracts.status_id
-- or referenced by application code) are untouched; only their
-- `display_order` is renumbered to make room for the new row between
-- PENDING_CUSTOMER_ACCEPTANCE and ACTIVE.
-- ---------------------------------------------------------------------

INSERT INTO service_contract_statuses (
    code, name, description, is_terminal, display_order, is_active
)
VALUES
(
    'REQUESTED', 'Requested',
    'The customer has requested a Service Contract; it is awaiting Admin review.',
    FALSE, 1, TRUE
),
(
    'APPROVED', 'Approved',
    'An Admin has finalized the term, covered Services and entitlements for this Contract.',
    FALSE, 2, TRUE
),
(
    'PENDING_CUSTOMER_ACCEPTANCE', 'Pending Customer Acceptance',
    'The finalized Contract has been sent to the customer and is awaiting their acceptance.',
    FALSE, 3, TRUE
),
(
    'PENDING_PAYMENT', 'Pending Payment',
    'The customer has accepted the Contract terms and must complete Stripe subscription checkout before it activates.',
    FALSE, 4, TRUE
),
(
    'ACTIVE', 'Active',
    'The customer has accepted the Contract, its Stripe subscription''s first invoice was paid, and it is currently within its term.',
    FALSE, 5, TRUE
),
(
    'SUSPENDED', 'Suspended',
    'The Contract has been temporarily suspended by an Admin; it does not authorize new Bookings.',
    FALSE, 6, TRUE
),
(
    'EXPIRED', 'Expired',
    'The Contract term has ended.',
    TRUE, 7, TRUE
),
(
    'CANCELLED', 'Cancelled',
    'The Contract was cancelled by an Admin.',
    TRUE, 8, TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    is_terminal = new.is_terminal,
    display_order = new.display_order,
    is_active = new.is_active;

-- ---------------------------------------------------------------------
-- SECTION 6 - service_contract_billing_statuses seed rows
-- ---------------------------------------------------------------------

INSERT INTO service_contract_billing_statuses (
    code, name, description, display_order, is_active
)
VALUES
(
    'PENDING_CHECKOUT', 'Pending Checkout',
    'The billing terms are frozen but the customer has not yet started (or completed) Stripe Checkout.',
    1, TRUE
),
(
    'INCOMPLETE', 'Incomplete',
    'Stripe Checkout completed and a Subscription exists, but no invoice has been confirmed paid yet.',
    2, TRUE
),
(
    'ACTIVE', 'Active',
    'The subscription''s most recent invoice was paid; new Contract Bookings are authorized.',
    3, TRUE
),
(
    'PAST_DUE', 'Past Due',
    'The most recent renewal invoice failed; Stripe is retrying automatically. New Contract Bookings are blocked.',
    4, TRUE
),
(
    'CANCEL_AT_PERIOD_END', 'Cancel At Period End',
    'The subscription is scheduled to stop at the current paid period''s end; still authorizes Bookings until then.',
    5, TRUE
),
(
    'CANCELLED', 'Cancelled',
    'The Stripe subscription was cancelled (by the Admin, the customer, or Stripe itself); terminal.',
    6, TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    display_order = new.display_order,
    is_active = new.is_active;
