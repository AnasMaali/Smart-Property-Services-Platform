-- =====================================================================
-- BLUE V1 Phase A1 - Admin Authorization / Permission Foundation
-- ONE additive migration.
-- =====================================================================
--
-- WHAT THIS ADDS (never DROPs, DELETEs, or destructively rewrites any
-- existing business-data row):
--   - Two brand-new tables (CREATE TABLE IF NOT EXISTS, no pre-existing
--     data to affect): `admin_permissions` (the stable capability-code
--     catalog) and `admin_role_permissions` (the Role -> Capability grant
--     matrix, a junction table over the EXISTING `roles` table - this is
--     NOT a second/competing role system).
--   - Six `admin_permissions` rows (`bookings.view`, `technicians.view`,
--     `technicians.assign`, `contracts.view`, `contracts.manage`,
--     `contracts.cancel`) - exactly the capabilities the already-shipped
--     `/v1/admin/*` Operations routes (BLUE V1 Phase 9B/10E) enforce today,
--     no more.
--   - Six `admin_role_permissions` grant rows, all for the existing ADMIN
--     role: this preserves ADMIN's current operational access exactly as
--     it was before this migration (Phase 9A/9B treated ADMIN and
--     SUPER_ADMIN identically; this migration does not change what ADMIN
--     can already do, only makes that access explicit and auditable via a
--     real grant row instead of an implicit "any ADMIN/SUPER_ADMIN passes"
--     rule).
--   - No row is inserted for SUPER_ADMIN. SUPER_ADMIN needs none: it is
--     authorized for every capability - present and future - through the
--     centralized, explicit override in
--     App\Support\Admin\AdminAuthorizationService, never through a row in
--     this table. This is intentional, not an omission.
--
-- WHY NO CHANGE TO `roles` / `user_roles`:
-- The existing two-role system (ADMIN, SUPER_ADMIN in `roles`, membership
-- in `user_roles`) is reused as-is. This migration adds a permission LAYER
-- on top of it (which roles hold which capabilities), never a second role
-- table or a parallel identity concept.
--
-- APPLICATION-CODE DEPENDENCY:
-- App\Http\Middleware\EnsureAdminHasCapability (aliased `admin.capability`,
-- BLUE V1 Phase A1) queries `admin_role_permissions` joined to `roles` and
-- `admin_permissions` on every capability-gated Admin request. Deploy this
-- migration BEFORE deploying the application code that adds the
-- `admin.capability:<code>` middleware to any route - if the application
-- code ships first, every capability-gated request 404s/500s against a
-- missing table; if this migration ships first (recommended, and safe by
-- itself), nothing yet queries the new tables until the application code
-- follows, and legacy behavior (no capability gate) is completely
-- unaffected in between.
--
-- MYSQL DDL TRANSACTION SEMANTICS (read this before running):
-- CREATE TABLE causes an implicit COMMIT in MySQL/InnoDB - there is no such
-- thing as a transactional, all-or-nothing DDL script, in this file or any
-- other. This file therefore does NOT wrap its statements in START
-- TRANSACTION/COMMIT. Safety instead comes from:
--   1. Every statement being purely additive (CREATE TABLE IF NOT EXISTS /
--      INSERT ... ON DUPLICATE KEY UPDATE) - nothing here can destroy or
--      corrupt existing data even if a later statement fails.
--   2. Idempotent guards on every statement - re-running this exact file
--      after any partial failure is always safe and converges to the same
--      end state.
--   3. Correct dependency order (admin_permissions before
--      admin_role_permissions, which references it).
--
-- PRIVILEGE REQUIREMENTS:
-- SECTIONS 1-2 are DDL (CREATE TABLE) and require a DDL-capable/admin
-- MySQL user, exactly like `blue_v1_schema.sql` itself - the application's
-- runtime least-privilege user (`blue_app`: SELECT, INSERT, UPDATE, DELETE
-- only) is NOT sufficient for those two statements. SECTIONS 3-4 (the seed
-- INSERTs) are DML only and work fine with `blue_app`.
--
-- Apply with (DDL-capable credentials required for the CREATE TABLE
-- statements):
--   mysql -h <host> -u <ddl_capable_user> -p blue_db < database/phase15_admin_permission_foundation_migration.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- SECTION 1 - admin_permissions (the capability-code catalog)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `admin_permissions` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_permissions_code` (`code`),
  KEY `idx_admin_permissions_active` (`is_active`),
  CONSTRAINT `chk_admin_permissions_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_admin_permissions_code` CHECK (((char_length(trim(`code`)) between 3 and 80) and regexp_like(`code`,_utf8mb4'^[a-z][a-z0-9_]*([.][a-z][a-z0-9_]*)+$'))),
  CONSTRAINT `chk_admin_permissions_description` CHECK (((`description` is null) or (char_length(trim(`description`)) between 2 and 300))),
  CONSTRAINT `chk_admin_permissions_name` CHECK ((char_length(trim(`name`)) between 2 and 150))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- SECTION 2 - admin_role_permissions (Role -> Capability grant matrix)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `admin_role_permissions` (
  `role_id` smallint unsigned NOT NULL,
  `permission_id` smallint unsigned NOT NULL,
  `granted_by_user_id` binary(16) DEFAULT NULL,
  `granted_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `idx_admin_role_permissions_permission_id` (`permission_id`),
  KEY `idx_admin_role_permissions_granted_by` (`granted_by_user_id`),
  CONSTRAINT `fk_admin_role_permissions_granted_by` FOREIGN KEY (`granted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
  CONSTRAINT `fk_admin_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `admin_permissions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_admin_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- SECTION 3 - seed the capability catalog
-- ---------------------------------------------------------------------

INSERT INTO admin_permissions (
    code,
    name,
    description,
    is_active
)
VALUES
(
    'bookings.view',
    'View Bookings',
    'View paid Bookings and Booking Items across every customer, including payment summary, technician assignment history, and cancellation/refund snapshot.',
    TRUE
),
(
    'technicians.view',
    'View Technicians',
    'View Technician records, availability, specializations, and the server-computed eligible-candidate list for a Booking Item.',
    TRUE
),
(
    'technicians.assign',
    'Assign Technicians',
    'Assign, reassign, start, and complete Technician work on a Booking Item.',
    TRUE
),
(
    'contracts.view',
    'View Service Contracts',
    'View Service Contracts and their items, status history, and acceptance/billing summary.',
    TRUE
),
(
    'contracts.manage',
    'Manage Service Contracts',
    'Approve, send for customer acceptance, and suspend a Service Contract.',
    TRUE
),
(
    'contracts.cancel',
    'Cancel Service Contracts',
    'Cancel a Service Contract, permanently stopping it from authorizing further Contract Bookings.',
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    is_active = new.is_active;

-- ---------------------------------------------------------------------
-- SECTION 4 - grant every seeded capability to ADMIN (preserves current
-- behavior exactly). SUPER_ADMIN intentionally receives no rows - see
-- header comment above.
-- ---------------------------------------------------------------------

INSERT INTO admin_role_permissions (
    role_id,
    permission_id,
    granted_by_user_id,
    granted_at
)
SELECT
    r.id,
    p.id,
    NULL,
    CURRENT_TIMESTAMP(6)
FROM roles r
CROSS JOIN admin_permissions p
WHERE r.code = 'ADMIN'
  AND p.code IN (
    'bookings.view',
    'technicians.view',
    'technicians.assign',
    'contracts.view',
    'contracts.manage',
    'contracts.cancel'
  )
ON DUPLICATE KEY UPDATE
    granted_at = granted_at;
