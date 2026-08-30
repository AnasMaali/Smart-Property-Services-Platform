-- =====================================================================
-- BLUE V1 Phase A2.5 - Admin WebAuthn Step-Up Authentication Schema
-- TWO additive ALTER TABLE changes, made partial-run-safe via
-- information_schema checks + dynamic SQL (see "RE-RUN SAFETY" below).
-- =====================================================================
--
-- WHAT THIS ADDS (never DROPs, DELETEs, or destructively rewrites any
-- existing row):
--   1. `auth_sessions.step_up_verified_at` - a nullable DATETIME(6)
--      recording when the CURRENT session last completed a fresh WebAuthn
--      STEP_UP ceremony. NULL means "no step-up on record" - the correct,
--      fail-closed state for every existing session once this migration
--      runs (no backfill is possible or desired: step-up freshness cannot
--      be retroactively established for a session that never performed
--      the new ceremony).
--   2. `admin_webauthn_challenges.auth_session_id` - a nullable BINARY(16)
--      FK to `auth_sessions.id`, binding a challenge to the specific
--      session it was issued for. NULL for REGISTRATION and
--      LOGIN_ASSERTION challenges (issued before any session exists, via
--      the pre-login ticket flow - see AdminWebAuthnChallengeService /
--      AdminMfaVerifyAction). Only ever populated for STEP_UP challenges.
--
-- WHY THIS IS NECESSARY (not a smaller/no-schema-change design):
-- Phase A2.5 requires that a STEP_UP WebAuthn challenge issued while an
-- Admin is using session A can never be used to mark session B's
-- `step_up_verified_at`, even for the exact same user (e.g. two browser
-- tabs, or two devices, both logged in as the same Admin). The current
-- `admin_webauthn_challenges` table (Phase A2.1) binds a challenge only to
-- (user_id, purpose_id) - App\Support\Admin\WebAuthn\
-- AdminWebAuthnChallengeService::consume() matches on
-- (challenge_hash, user_id, purpose_id) alone. Nothing in that lookup
-- distinguishes which of the user's possibly-several concurrent
-- auth_sessions rows requested the challenge, so a same-user
-- cross-session replay is not just theoretically possible but is exactly
-- what the existing schema allows today. Storing the requesting session's
-- id on the challenge row, and requiring an exact match against the
-- CURRENT request's authenticated session at verify time, is the minimum
-- change that closes this gap without inventing a second session/ticket
-- table (which the phase spec explicitly forbids).
--
-- Similarly, `auth_sessions` currently has no column capable of recording
-- "this specific session recently proved a fresh WebAuthn step-up" -
-- `last_used_at` already has a distinct, different meaning (general
-- activity, throttled and governed by App\Support\Admin\AdminSessionPolicy
-- for the unrelated idle-timeout feature, Phase A2.4) and must keep
-- meaning only that - overloading it for step-up freshness would couple
-- two features that the phase spec requires to stay independent (idle
-- timeout must not be affected by step-up state, and refresh must leave
-- step_up_verified_at completely untouched while it already leaves
-- last_used_at untouched for a different reason).
--
-- SCOPE:
-- This migration is SCHEMA ONLY (BLUE V1 Phase A2.5). It adds no
-- application behavior by itself - no route, controller, Action, or
-- middleware yet reads or writes either new column. Every existing
-- Admin/Customer request path is unaffected until later application code
-- (already scoped for this same phase, held pending this migration's
-- review/approval) is added.
--
-- CONSTRAINTS/FKS/INDEXES ADDED, AND WHY:
--   - `chk_auth_sessions_step_up_verified` CHECK: mirrors the exact
--     pattern already used for `chk_auth_sessions_last_used` and
--     `chk_auth_sessions_revoked` on this same table - NULL is always
--     valid, otherwise the timestamp can never predate the session's own
--     `created_at`.
--   - `fk_admin_webauthn_challenges_session` FK (ON DELETE CASCADE, ON
--     UPDATE RESTRICT): mirrors `fk_admin_webauthn_challenges_user`'s own
--     ON DELETE CASCADE on this same table - a challenge row bound to a
--     session that no longer exists is meaningless and should not block
--     or outlive the session's own deletion. In practice `auth_sessions`
--     rows are only ever revoked, never deleted, in existing application
--     code, so this CASCADE is a safety property, not an expected runtime
--     path.
--   - `idx_admin_webauthn_challenges_session` KEY: supports the new
--     verify-time lookup ("does this challenge belong to the current
--     session") efficiently, mirroring the existing
--     `idx_admin_webauthn_challenges_user_purpose` index's purpose for
--     the (user_id, purpose_id) lookup it already supports.
--   - No new index on `auth_sessions.step_up_verified_at`: it is only
--     ever read by primary-key (session id) lookup, already covered by
--     the table's existing PRIMARY KEY - no query scans or filters this
--     column independently.
--
-- IMPACT ON EXISTING SESSIONS:
-- Every existing `auth_sessions` row gains `step_up_verified_at = NULL`
-- (the column's default) - functionally identical to "no step-up has ever
-- been performed for this session", which is factually correct and is
-- also the exact same state a brand-new session is required to start in
-- per this phase's login semantics. No currently-active Admin session is
-- logged out, revoked, or otherwise disrupted by this migration; the very
-- next request to a Step-Up-protected route (contracts.cancel) after this
-- migration is applied will correctly require a fresh WebAuthn Step-Up
-- ceremony, exactly as a session created after this migration would.
-- Every existing `admin_webauthn_challenges` row (if any - this table has
-- no data as of Phase A2.1-A2.4, since only ceremony *foundation* has
-- shipped so far) gains `auth_session_id = NULL`, correctly reflecting
-- that no historical challenge was ever session-bound (none of them were
-- STEP_UP challenges issued under this new binding requirement).
--
-- RE-RUN SAFETY (corrected - read this before running):
-- An earlier version of this file used `ALTER TABLE ... ADD COLUMN IF NOT
-- EXISTS ...`. That syntax is a MariaDB extension and is NOT valid MySQL
-- syntax in any 8.0 release, including this deployment's 8.0.46 - running
-- it fails immediately with ERROR 1064 (syntax error) before any DDL
-- executes, exactly as observed. Oracle MySQL has no `IF NOT EXISTS`
-- clause for `ADD COLUMN`, `ADD CONSTRAINT`, or `ADD FOREIGN KEY` on
-- ALTER TABLE at all (unlike `DROP ... IF EXISTS`, which MySQL does
-- support but which is irrelevant here since this migration never drops
-- anything).
--
-- This corrected version instead makes each of the five objects below
-- conditionally added via `information_schema` lookups feeding
-- PREPARE/EXECUTE/DEALLOCATE PREPARE dynamic SQL - the standard plain-SQL
-- (no stored routine) idiom for conditional DDL in MySQL:
--   1. auth_sessions.step_up_verified_at            (column)
--   2. chk_auth_sessions_step_up_verified            (CHECK constraint)
--   3. admin_webauthn_challenges.auth_session_id     (column)
--   4. idx_admin_webauthn_challenges_session         (index)
--   5. fk_admin_webauthn_challenges_session          (FOREIGN KEY)
-- For each: if the object already exists (checked against
-- `information_schema.COLUMNS` / `.TABLE_CONSTRAINTS` / `.STATISTICS`,
-- filtered by `TABLE_SCHEMA = DATABASE()` so this file operates on
-- whichever database it is actually run against), the executed statement
-- is a harmless no-op `SELECT` instead of the real DDL; otherwise the real
-- `ALTER TABLE` runs. Every `PREPARE`d statement is `DEALLOCATE`d
-- immediately after `EXECUTE`, in the same block, so nothing session-level
-- or persistent is left behind - and no stored procedure/function is
-- created at any point, so there is nothing to clean up afterward. The
-- five checks are ordered so each object's prerequisites (its own table's
-- new column, for the CHECK/index/FK below it) are already in place by
-- the time it runs, whether this is the first run or a re-run after a
-- prior partial failure.
--
-- MySQL DDL TRANSACTION SEMANTICS: ALTER TABLE causes an implicit COMMIT
-- in MySQL/InnoDB, same as CREATE TABLE - there is no transactional,
-- all-or-nothing DDL script, in this file or any other in this directory.
-- Safety instead comes entirely from the re-run idempotency described
-- above: this file can be run, re-run after any partial failure, or run
-- against an environment where some subset of the five objects already
-- exists, and always converges to the same end state without ever
-- DROPping/recreating anything or touching existing data.
--
-- PRIVILEGE REQUIREMENTS:
-- Requires a DDL-capable MySQL user (the application's runtime
-- least-privilege user `blue_app` - SELECT, INSERT, UPDATE, DELETE only -
-- is NOT sufficient for ALTER TABLE, nor for reading
-- `information_schema.TABLE_CONSTRAINTS`/`.STATISTICS` in the way this
-- file relies on for its own table).
--
-- Apply with (DDL-capable credentials required):
--   mysql -h <host> -u <ddl_capable_user> -p blue_db < database/phase17_admin_step_up_schema_migration.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- SECTION 1 - auth_sessions.step_up_verified_at (column)
-- ---------------------------------------------------------------------

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'auth_sessions'
      AND COLUMN_NAME = 'step_up_verified_at'
);

SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE `auth_sessions` ADD COLUMN `step_up_verified_at` datetime(6) DEFAULT NULL AFTER `last_used_at`',
    'SELECT ''auth_sessions.step_up_verified_at already exists - skipped'' AS status'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- SECTION 2 - chk_auth_sessions_step_up_verified (CHECK constraint)
-- ---------------------------------------------------------------------

SET @chk_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'auth_sessions'
      AND CONSTRAINT_NAME = 'chk_auth_sessions_step_up_verified'
      AND CONSTRAINT_TYPE = 'CHECK'
);

SET @ddl := IF(
    @chk_exists = 0,
    'ALTER TABLE `auth_sessions` ADD CONSTRAINT `chk_auth_sessions_step_up_verified` CHECK ((`step_up_verified_at` is null) or (`step_up_verified_at` >= `created_at`))',
    'SELECT ''chk_auth_sessions_step_up_verified already exists - skipped'' AS status'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- SECTION 3 - admin_webauthn_challenges.auth_session_id (column)
-- ---------------------------------------------------------------------

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'admin_webauthn_challenges'
      AND COLUMN_NAME = 'auth_session_id'
);

SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE `admin_webauthn_challenges` ADD COLUMN `auth_session_id` binary(16) DEFAULT NULL AFTER `user_id`',
    'SELECT ''admin_webauthn_challenges.auth_session_id already exists - skipped'' AS status'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- SECTION 4 - idx_admin_webauthn_challenges_session (index)
-- ---------------------------------------------------------------------

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'admin_webauthn_challenges'
      AND INDEX_NAME = 'idx_admin_webauthn_challenges_session'
);

SET @ddl := IF(
    @idx_exists = 0,
    'ALTER TABLE `admin_webauthn_challenges` ADD KEY `idx_admin_webauthn_challenges_session` (`auth_session_id`)',
    'SELECT ''idx_admin_webauthn_challenges_session already exists - skipped'' AS status'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- SECTION 5 - fk_admin_webauthn_challenges_session (FOREIGN KEY)
-- ---------------------------------------------------------------------

SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'admin_webauthn_challenges'
      AND CONSTRAINT_NAME = 'fk_admin_webauthn_challenges_session'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @ddl := IF(
    @fk_exists = 0,
    'ALTER TABLE `admin_webauthn_challenges` ADD CONSTRAINT `fk_admin_webauthn_challenges_session` FOREIGN KEY (`auth_session_id`) REFERENCES `auth_sessions` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT',
    'SELECT ''fk_admin_webauthn_challenges_session already exists - skipped'' AS status'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
