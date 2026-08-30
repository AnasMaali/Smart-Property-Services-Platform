-- =====================================================================
-- BLUE V1 Phase B23 - Categories + Services Full Admin Management
-- ONE additive column, made partial-run-safe via information_schema
-- checks + dynamic SQL (same idiom as phase17/phase18/phase21).
-- =====================================================================
--
-- WHAT THIS ADDS (never DROPs, DELETEs, or destructively rewrites any
-- existing row):
--   `services.original_price` - a nullable decimal(19,6) "before discount /
--   list price" the Admin catalog workspace can now set alongside the
--   Service's real selling price. This is deliberately ADDITIVE CATALOG
--   METADATA ONLY - it is never read by App\Support\Pricing\PricingEngine,
--   never referenced by any pricing_rules/pricing_scheme_versions row, and
--   never copied onto a booking_items snapshot. The ONE actual
--   checkout-pricing authority remains exactly what it already was: the
--   currently-PUBLISHED `pricing_scheme_versions`/`pricing_rules` for a
--   Service (see App\Actions\Admin\Pricing\AdminSetServiceCurrentPriceAction,
--   which is the only writer of the actual selling price and does so
--   entirely through the existing canonical draft -> rule -> publish flow -
--   never a new pricing authority).
--
-- WHY NULLABLE, WHY NO CHECK AGAINST THE CURRENT SELLING PRICE HERE:
-- the "original >= current" relationship is a cross-table invariant
-- (original_price lives on `services`, current price lives in
-- `pricing_rules.effect_amount` on a *different* table) that MySQL cannot
-- express as a single-table CHECK constraint - it is enforced at the
-- application layer by App\Actions\Admin\Pricing\
-- AdminSetServiceOriginalPriceAction / AdminSetServiceCurrentPriceAction
-- instead, exactly like every other cross-row pricing invariant in this
-- codebase (see App\Support\Pricing\SchemePublishValidator, which enforces
-- its own cross-row checks in PHP rather than SQL for the same reason).
-- `NULL` means "no list price configured yet" - a legitimate, common state
-- for a Service that has never had a two-price discount configured.
--
-- Apply with (DDL-capable credentials required):
--   mysql -h <host> -u <ddl_capable_user> -p blue_db < database/phase22_catalog_admin_management_migration.sql
-- =====================================================================

SET @col_needs_update := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'services'
      AND COLUMN_NAME = 'original_price'
);

SET @ddl := IF(
    @col_needs_update = 0,
    'ALTER TABLE `services` ADD COLUMN `original_price` decimal(19,6) DEFAULT NULL AFTER `is_active`',
    'SELECT ''services.original_price already exists - skipped'' AS status'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @chk_needs_update := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS tc
    WHERE tc.TABLE_SCHEMA = DATABASE()
      AND tc.TABLE_NAME = 'services'
      AND tc.CONSTRAINT_TYPE = 'CHECK'
      AND tc.CONSTRAINT_NAME = 'chk_services_original_price'
);

SET @ddl := IF(
    @chk_needs_update = 0,
    'ALTER TABLE `services` ADD CONSTRAINT `chk_services_original_price` CHECK ((`original_price` is null) or (`original_price` >= 0))',
    'SELECT ''chk_services_original_price already exists - skipped'' AS status'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
