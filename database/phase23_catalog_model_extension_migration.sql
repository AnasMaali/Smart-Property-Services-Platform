-- =====================================================================
-- BLUE V1 Phase B23-ext - Catalog Model Extension
-- Additive-only, idempotent (existing-table ALTERs guarded via
-- information_schema + dynamic SQL, same idiom as phase17/18/21/22; new
-- tables use CREATE TABLE IF NOT EXISTS, same idiom as phase19).
-- =====================================================================
--
-- WHAT THIS ADDS (never DROPs, DELETEs, or destructively rewrites any
-- existing row):
--
--   1. `services.is_featured` / `services.estimated_duration_minutes` /
--      `services.min_quantity` / `services.max_quantity` - additive
--      catalog-display/policy metadata. None of these are read by
--      App\Support\Pricing\PricingEngine and none compete with it as a
--      pricing authority - `min_quantity`/`max_quantity` only bound what
--      `cart_items.quantity` (already CHECK'd 1..1000) an Admin allows for
--      THIS service; the existing global 1..1000 bound on `cart_items`
--      itself is untouched. Default min=1/max=1000 preserves today's
--      de-facto unlimited-within-global-bound behavior for every existing
--      Service until an Admin explicitly narrows it (e.g. to 1/1 for a
--      single-quantity package) - this migration never retroactively
--      restricts anything.
--
--   2. Three small reference/lookup tables (same shape/convention as
--      `service_option_types` / `service_capability_types` /
--      `booking_refund_statuses`): `service_option_choice_attribute_types`,
--      `service_content_section_types`, `service_checkpoint_action_types`.
--      Seeded with starter vocabulary only - never real BLUE catalog
--      business data. Extensible by a future migration, never by
--      client-supplied codes at request time (mirrors how
--      `service_option_types.code` already works).
--
--   3. `service_option_choice_attributes` - generic typed key/value
--      metadata on one `service_option_choices` row (e.g. a car-service
--      package choice's oil brand/grade, duration, recommended odometer).
--      Deliberately NOT dedicated columns on `service_option_choices` -
--      the attribute vocabulary is business content, not schema, and must
--      stay Admin-extensible without a migration once the *type* exists.
--      Exactly one of `value_string`/`value_number` is populated per row
--      (enforced by CHECK); which one is expected is governed by the
--      referenced type's `data_type` - a cross-table invariant enforced in
--      App\Actions\Admin\Service\AdminServiceOptionChoiceAttributeAction,
--      the same documented pattern already used by
--      AdminSetServiceOriginalPriceAction for original >= current price.
--
--   4. `service_content_sections` - generic ordered, activatable content
--      blocks per Service (Overview / Recommended For / What's Included /
--      free-form custom headings like "Keep Your Car Running Like New").
--      `section_type_id` is a coarse category only; `title`/`body` are
--      always Admin-authored per instance, since a custom marketing
--      headline is never just the type's name.
--
--   5. `service_checkpoint_groups` / `service_checkpoints` - generic
--      ordered, activatable per-Service workshop-checklist structure
--      (e.g. "Engine & Lubrication" -> "Replace engine oil"). Checkpoint
--      counts are always DERIVED (COUNT of active checkpoints) by the
--      presenter layer, never stored - see
--      App\Support\Admin\AdminServiceCheckpointPresenter.
--
-- WHY RESTRICT everywhere: every new child table follows this codebase's
-- standing "never silently disappear a catalog/historical relationship"
-- convention - a Service/Choice/Group with dependent rows cannot be
-- deleted out from under them (and nothing in this schema ever hard-
-- deletes a Service/Choice/Group anyway; Admin mutation is deactivate/
-- reactivate only, per BLUE V1 standing policy already used throughout
-- Phase B23).
--
-- Apply with (DDL-capable credentials required):
--   mysql -h <host> -u <ddl_capable_user> -p blue_db < database/phase23_catalog_model_extension_migration.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- SECTION 1 - `services` additive columns
-- ---------------------------------------------------------------------

SET @col_needs_update := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'is_featured'
);
SET @ddl := IF(
    @col_needs_update = 0,
    'ALTER TABLE `services` ADD COLUMN `is_featured` tinyint(1) NOT NULL DEFAULT 0 AFTER `original_price`',
    'SELECT ''services.is_featured already exists - skipped'' AS status'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @chk_needs_update := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'chk_services_is_featured'
);
SET @ddl := IF(
    @chk_needs_update = 0,
    'ALTER TABLE `services` ADD CONSTRAINT `chk_services_is_featured` CHECK (`is_featured` in (0,1))',
    'SELECT ''chk_services_is_featured already exists - skipped'' AS status'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_needs_update := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'estimated_duration_minutes'
);
SET @ddl := IF(
    @col_needs_update = 0,
    'ALTER TABLE `services` ADD COLUMN `estimated_duration_minutes` smallint unsigned DEFAULT NULL AFTER `is_featured`',
    'SELECT ''services.estimated_duration_minutes already exists - skipped'' AS status'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @chk_needs_update := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'chk_services_estimated_duration_minutes'
);
SET @ddl := IF(
    @chk_needs_update = 0,
    'ALTER TABLE `services` ADD CONSTRAINT `chk_services_estimated_duration_minutes` CHECK ((`estimated_duration_minutes` is null) or (`estimated_duration_minutes` > 0))',
    'SELECT ''chk_services_estimated_duration_minutes already exists - skipped'' AS status'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_needs_update := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'min_quantity'
);
SET @ddl := IF(
    @col_needs_update = 0,
    'ALTER TABLE `services` ADD COLUMN `min_quantity` int unsigned NOT NULL DEFAULT 1 AFTER `estimated_duration_minutes`',
    'SELECT ''services.min_quantity already exists - skipped'' AS status'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_needs_update := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'max_quantity'
);
SET @ddl := IF(
    @col_needs_update = 0,
    'ALTER TABLE `services` ADD COLUMN `max_quantity` int unsigned NOT NULL DEFAULT 1000 AFTER `min_quantity`',
    'SELECT ''services.max_quantity already exists - skipped'' AS status'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @chk_needs_update := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'chk_services_min_quantity'
);
SET @ddl := IF(
    @chk_needs_update = 0,
    'ALTER TABLE `services` ADD CONSTRAINT `chk_services_min_quantity` CHECK (`min_quantity` between 1 and 1000)',
    'SELECT ''chk_services_min_quantity already exists - skipped'' AS status'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @chk_needs_update := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'chk_services_max_quantity'
);
SET @ddl := IF(
    @chk_needs_update = 0,
    'ALTER TABLE `services` ADD CONSTRAINT `chk_services_max_quantity` CHECK (`max_quantity` between `min_quantity` and 1000)',
    'SELECT ''chk_services_max_quantity already exists - skipped'' AS status'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- SECTION 2 - lookup tables
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `service_option_choice_attribute_types` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `data_type` varchar(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_option_choice_attribute_types_code` (`code`),
  KEY `idx_service_option_choice_attribute_types_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_soc_attribute_types_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_soc_attribute_types_code` CHECK ((char_length(trim(`code`)) between 2 and 40)),
  CONSTRAINT `chk_soc_attribute_types_data_type` CHECK ((`data_type` in (_utf8mb4'STRING',_utf8mb4'NUMBER'))),
  CONSTRAINT `chk_soc_attribute_types_description` CHECK (((`description` is null) or (char_length(trim(`description`)) > 0))),
  CONSTRAINT `chk_soc_attribute_types_name` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO service_option_choice_attribute_types (code, name, description, data_type, display_order, is_active)
VALUES
    ('DURATION_MINUTES', 'Duration (minutes)', 'This package choice''s own estimated duration, when it differs from the Service-level default.', 'NUMBER', 1, TRUE),
    ('OIL_BRAND', 'Oil brand', 'The oil brand this package choice supplies, e.g. Castrol.', 'STRING', 2, TRUE),
    ('OIL_GRADE', 'Oil grade', 'The oil grade this package choice supplies, e.g. 5W-40.', 'STRING', 3, TRUE),
    ('RECOMMENDED_ODOMETER_KM', 'Recommended odometer (km)', 'The recommended odometer interval, in kilometres, for this package choice.', 'NUMBER', 4, TRUE)
AS new
ON DUPLICATE KEY UPDATE name = new.name, description = new.description, data_type = new.data_type, display_order = new.display_order, is_active = new.is_active;

CREATE TABLE IF NOT EXISTS `service_content_section_types` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_content_section_types_code` (`code`),
  KEY `idx_service_content_section_types_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_content_section_types_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_content_section_types_code` CHECK ((char_length(trim(`code`)) between 2 and 40)),
  CONSTRAINT `chk_content_section_types_description` CHECK (((`description` is null) or (char_length(trim(`description`)) > 0))),
  CONSTRAINT `chk_content_section_types_name` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO service_content_section_types (code, name, description, display_order, is_active)
VALUES
    ('OVERVIEW', 'Overview', 'A general introductory description of the service.', 1, TRUE),
    ('RECOMMENDED_FOR', 'Recommended for', 'Who/when this service is recommended for.', 2, TRUE),
    ('WHATS_INCLUDED', 'What''s included', 'What the service includes.', 3, TRUE),
    ('OTHER', 'Other', 'A free-form informational section with a custom heading.', 4, TRUE)
AS new
ON DUPLICATE KEY UPDATE name = new.name, description = new.description, display_order = new.display_order, is_active = new.is_active;

CREATE TABLE IF NOT EXISTS `service_checkpoint_action_types` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_checkpoint_action_types_code` (`code`),
  KEY `idx_service_checkpoint_action_types_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_checkpoint_action_types_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_checkpoint_action_types_code` CHECK ((char_length(trim(`code`)) between 2 and 40)),
  CONSTRAINT `chk_checkpoint_action_types_description` CHECK (((`description` is null) or (char_length(trim(`description`)) > 0))),
  CONSTRAINT `chk_checkpoint_action_types_name` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO service_checkpoint_action_types (code, name, description, display_order, is_active)
VALUES
    ('REPLACE', 'Replace', 'The item is replaced.', 1, TRUE),
    ('INSPECT', 'Inspect', 'The item is visually/functionally inspected.', 2, TRUE),
    ('INSPECT_AND_CLEAN', 'Inspect & clean', 'The item is inspected and cleaned.', 3, TRUE),
    ('TOP_UP', 'Top up', 'The item''s level/fluid is topped up.', 4, TRUE),
    ('INSPECT_AND_ADJUST', 'Inspect & adjust', 'The item is inspected and adjusted.', 5, TRUE),
    ('UPDATE', 'Update', 'The item is updated (e.g. firmware/software).', 6, TRUE)
AS new
ON DUPLICATE KEY UPDATE name = new.name, description = new.description, display_order = new.display_order, is_active = new.is_active;

-- ---------------------------------------------------------------------
-- SECTION 3 - service_option_choice_attributes
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `service_option_choice_attributes` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `choice_id` binary(16) NOT NULL,
  `attribute_type_id` tinyint unsigned NOT NULL,
  `value_string` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `value_number` decimal(19,6) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_soc_attributes_choice_type` (`choice_id`,`attribute_type_id`),
  KEY `idx_soc_attributes_type` (`attribute_type_id`),
  CONSTRAINT `fk_soc_attributes_choice` FOREIGN KEY (`choice_id`) REFERENCES `service_option_choices` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_soc_attributes_type` FOREIGN KEY (`attribute_type_id`) REFERENCES `service_option_choice_attribute_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_soc_attributes_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_soc_attributes_value_string` CHECK (((`value_string` is null) or (char_length(trim(`value_string`)) > 0))),
  CONSTRAINT `chk_soc_attributes_exactly_one_value` CHECK (((`value_string` is null) <> (`value_number` is null)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- SECTION 4 - service_content_sections
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `service_content_sections` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `service_id` binary(16) NOT NULL,
  `section_type_id` tinyint unsigned NOT NULL,
  `title` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_content_sections_service_active_order` (`service_id`,`is_active`,`display_order`),
  KEY `idx_content_sections_type` (`section_type_id`),
  CONSTRAINT `fk_content_sections_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_content_sections_type` FOREIGN KEY (`section_type_id`) REFERENCES `service_content_section_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_content_sections_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_content_sections_title` CHECK ((char_length(trim(`title`)) between 2 and 160)),
  CONSTRAINT `chk_content_sections_body` CHECK ((char_length(trim(`body`)) > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- SECTION 5 - service_checkpoint_groups / service_checkpoints
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `service_checkpoint_groups` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `service_id` binary(16) NOT NULL,
  `name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_checkpoint_groups_service_name` (`service_id`,`name`),
  KEY `idx_checkpoint_groups_service_active_order` (`service_id`,`is_active`,`display_order`),
  CONSTRAINT `fk_checkpoint_groups_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_checkpoint_groups_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_checkpoint_groups_name` CHECK ((char_length(trim(`name`)) between 2 and 160)),
  CONSTRAINT `chk_checkpoint_groups_description` CHECK (((`description` is null) or (char_length(trim(`description`)) > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `service_checkpoints` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `group_id` binary(16) NOT NULL,
  `name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `action_type_id` tinyint unsigned NOT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_checkpoints_group_name` (`group_id`,`name`),
  KEY `idx_checkpoints_group_active_order` (`group_id`,`is_active`,`display_order`),
  KEY `idx_checkpoints_action_type` (`action_type_id`),
  CONSTRAINT `fk_checkpoints_group` FOREIGN KEY (`group_id`) REFERENCES `service_checkpoint_groups` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_checkpoints_action_type` FOREIGN KEY (`action_type_id`) REFERENCES `service_checkpoint_action_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_checkpoints_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_checkpoints_name` CHECK ((char_length(trim(`name`)) between 2 and 160)),
  CONSTRAINT `chk_checkpoints_description` CHECK (((`description` is null) or (char_length(trim(`description`)) > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
