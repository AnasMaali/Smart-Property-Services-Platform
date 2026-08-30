-- =====================================================================
-- BLUE V1 Phase A2.1 - Admin WebAuthn/MFA Schema Foundation
-- ONE additive migration.
-- =====================================================================
--
-- WHAT THIS ADDS (never DROPs, DELETEs, or destructively rewrites any
-- existing business-data row):
--   - Three brand-new tables (CREATE TABLE IF NOT EXISTS, no pre-existing
--     data to affect): `admin_webauthn_challenge_purposes` (a small
--     reference/lookup table, mirroring `otp_verification_purposes`),
--     `admin_webauthn_challenges` (short-lived, single-use WebAuthn
--     ceremony state), and `admin_webauthn_credentials` (long-lived
--     WebAuthn public-credential material and lifecycle metadata).
--   - Three `admin_webauthn_challenge_purposes` reference rows:
--     `REGISTRATION`, `LOGIN_ASSERTION`, `STEP_UP`.
--
-- SCOPE - READ THIS BEFORE ASSUMING MORE THAN SCHEMA CHANGED:
-- This migration is SCHEMA FOUNDATION ONLY (BLUE V1 Phase A2.1). It adds
-- no application behavior: no route, no controller, no WebAuthn
-- registration/login/step-up ceremony logic exists yet. Admin login,
-- Admin sessions, and Admin authorization (Phase A1) are completely
-- unaffected by this migration - nothing in the existing request path
-- reads or writes any of these three tables until a later phase (A2.2+)
-- adds that code.
--
-- WHY NO `admin_trusted_devices` TABLE:
-- Production device trust is owned by Tailscale (network/device-access
-- layer), outside Laravel entirely - see
-- docs/api-contracts/admin-webauthn-mfa-v1.md. Laravel's WebAuthn
-- credentials below are a pure MFA (identity/possession) factor, not a
-- device-approval workflow, so there is no `PENDING`/`APPROVED` status,
-- no SUPER_ADMIN-approval gate, and no device-identity concept anywhere
-- in this schema. Building one would duplicate Tailscale's own device
-- management with strictly weaker signals.
--
-- WHY `admin_webauthn_credentials.user_id` HAS NO ROLE-MEMBERSHIP FK:
-- A credential belongs to a `users` row only. Whether that user currently
-- holds an active ADMIN/SUPER_ADMIN role is re-checked dynamically at the
-- application layer on every request (exactly like `auth_sessions`
-- already does) - it is never encoded as a foreign key or CHECK
-- constraint here, since role membership is mutable and a stale FK
-- assumption would be actively wrong the moment a role changes.
--
-- WHY NO PRIVATE KEY / SECRET COLUMN ANYWHERE:
-- `admin_webauthn_credentials.public_key` is exactly that - the public
-- half of an asymmetric keypair. The private key never leaves the
-- authenticator and is never transmitted to or stored by this
-- application, in this table or anywhere else. `admin_webauthn_challenges
-- .challenge_hash` stores only SHA-256(raw challenge) - the same
-- store-a-hash-never-the-raw-value convention `auth_sessions
-- .refresh_token_hash` and `password_reset_sessions.reset_token_hash`
-- already use - never the raw challenge bytes themselves.
--
-- MYSQL DDL TRANSACTION SEMANTICS (read this before running):
-- CREATE TABLE causes an implicit COMMIT in MySQL/InnoDB - there is no
-- such thing as a transactional, all-or-nothing DDL script, in this file
-- or any other. This file therefore does NOT wrap its statements in
-- START TRANSACTION/COMMIT. Safety instead comes from:
--   1. Every statement being purely additive (CREATE TABLE IF NOT EXISTS
--      / INSERT ... ON DUPLICATE KEY UPDATE) - nothing here can destroy
--      or corrupt existing data even if a later statement fails.
--   2. Idempotent guards on every statement - re-running this exact file
--      after any partial failure is always safe and converges to the
--      same end state.
--   3. Correct dependency order (admin_webauthn_challenge_purposes before
--      admin_webauthn_challenges, which references it).
--
-- PRIVILEGE REQUIREMENTS:
-- SECTIONS 1-3 are DDL (CREATE TABLE) and require a DDL-capable/admin
-- MySQL user, exactly like `blue_v1_schema.sql` itself - the
-- application's runtime least-privilege user (`blue_app`: SELECT,
-- INSERT, UPDATE, DELETE only) is NOT sufficient for those statements.
-- SECTION 4 (the seed INSERT) is DML only and works fine with `blue_app`.
--
-- Apply with (DDL-capable credentials required for the CREATE TABLE
-- statements):
--   mysql -h <host> -u <ddl_capable_user> -p blue_db < database/phase16_admin_webauthn_mfa_schema_migration.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- SECTION 1 - admin_webauthn_challenge_purposes (reference/lookup table,
-- mirrors otp_verification_purposes)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `admin_webauthn_challenge_purposes` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_webauthn_challenge_purposes_code` (`code`),
  CONSTRAINT `chk_admin_webauthn_challenge_purposes_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_admin_webauthn_challenge_purposes_code` CHECK ((char_length(trim(`code`)) between 2 and 50)),
  CONSTRAINT `chk_admin_webauthn_challenge_purposes_name` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- SECTION 2 - admin_webauthn_challenges (short-lived, single-use
-- ceremony state - registration, login assertion, and step-up all share
-- this one generalized table via purpose_id, mirroring how
-- otp_verifications is shared across PHONE_VERIFICATION/PASSWORD_RESET/
-- PHONE_NUMBER_CHANGE/LOGIN today)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `admin_webauthn_challenges` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `user_id` binary(16) NOT NULL,
  `purpose_id` tinyint unsigned NOT NULL,
  `challenge_hash` binary(32) NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `consumed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_webauthn_challenges_challenge_hash` (`challenge_hash`),
  KEY `idx_admin_webauthn_challenges_user_purpose` (`user_id`,`purpose_id`,`created_at`),
  KEY `idx_admin_webauthn_challenges_purpose` (`purpose_id`),
  KEY `idx_admin_webauthn_challenges_expires_at` (`expires_at`),
  KEY `idx_admin_webauthn_challenges_active` (`consumed_at`,`expires_at`),
  CONSTRAINT `fk_admin_webauthn_challenges_purpose` FOREIGN KEY (`purpose_id`) REFERENCES `admin_webauthn_challenge_purposes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_admin_webauthn_challenges_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `chk_admin_webauthn_challenges_consumed` CHECK (((`consumed_at` is null) or (`consumed_at` >= `created_at`))),
  CONSTRAINT `chk_admin_webauthn_challenges_expiration` CHECK ((`expires_at` > `created_at`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- SECTION 3 - admin_webauthn_credentials (long-lived WebAuthn public
-- credential material + lifecycle metadata - the MFA factor itself)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `admin_webauthn_credentials` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `user_id` binary(16) NOT NULL,
  `label` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `credential_id` varbinary(1024) NOT NULL,
  `public_key` blob NOT NULL,
  `sign_count` int unsigned NOT NULL DEFAULT '0',
  `transports` json DEFAULT NULL,
  `aaguid` binary(16) DEFAULT NULL,
  `backup_eligible` tinyint(1) DEFAULT NULL,
  `backup_state` tinyint(1) DEFAULT NULL,
  `revoked_at` datetime(6) DEFAULT NULL,
  `revoked_by_user_id` binary(16) DEFAULT NULL,
  `revoke_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `last_used_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_webauthn_credentials_credential_id` (`credential_id`),
  KEY `idx_admin_webauthn_credentials_user` (`user_id`),
  KEY `idx_admin_webauthn_credentials_user_active` (`user_id`,`revoked_at`),
  KEY `idx_admin_webauthn_credentials_revoked_by` (`revoked_by_user_id`),
  CONSTRAINT `fk_admin_webauthn_credentials_revoked_by` FOREIGN KEY (`revoked_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_admin_webauthn_credentials_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `chk_admin_webauthn_credentials_backup_eligible` CHECK (((`backup_eligible` is null) or (`backup_eligible` in (0,1)))),
  CONSTRAINT `chk_admin_webauthn_credentials_backup_state` CHECK (((`backup_state` is null) or (`backup_state` in (0,1)))),
  CONSTRAINT `chk_admin_webauthn_credentials_label` CHECK (((`label` is null) or (char_length(trim(`label`)) between 2 and 120))),
  CONSTRAINT `chk_admin_webauthn_credentials_last_used` CHECK (((`last_used_at` is null) or (`last_used_at` >= `created_at`))),
  CONSTRAINT `chk_admin_webauthn_credentials_revoke_consistency` CHECK ((((`revoked_at` is null) and (`revoke_reason` is null)) or ((`revoked_at` is not null) and (`revoke_reason` is not null)))),
  CONSTRAINT `chk_admin_webauthn_credentials_revoke_reason` CHECK (((`revoke_reason` is null) or (char_length(trim(`revoke_reason`)) between 2 and 500))),
  CONSTRAINT `chk_admin_webauthn_credentials_revoked_at` CHECK (((`revoked_at` is null) or (`revoked_at` >= `created_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- SECTION 4 - seed the three challenge-purpose reference rows
-- ---------------------------------------------------------------------

INSERT INTO admin_webauthn_challenge_purposes (
    code,
    name,
    description,
    is_active
)
VALUES
(
    'REGISTRATION',
    'Registration',
    'A challenge issued while an Admin registers a new WebAuthn credential.',
    TRUE
),
(
    'LOGIN_ASSERTION',
    'Login Assertion',
    'A challenge issued during Admin login, to be proven by an existing WebAuthn credential.',
    TRUE
),
(
    'STEP_UP',
    'Step-Up',
    'A challenge issued to re-verify an already-authenticated Admin before a sensitive operation.',
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    is_active = new.is_active;
