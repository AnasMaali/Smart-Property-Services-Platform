-- =====================================================================
-- BLUE V1 Phase 20 - Grant SUBSCRIPTION capability for Service Contracts
-- ONE additive, DML-only data upgrade.
-- =====================================================================
--
-- Contract request/approve gates on service_capabilities +
-- service_capability_types.code = 'SUBSCRIPTION'. The capability TYPE is
-- already seeded (blue_v1_seed.sql); this migration attaches it to every
-- currently active service that does not already have it, so customer
-- contract requests and Admin approve pickers work against a real catalog.
--
-- Safe to re-run: NOT EXISTS skips rows that already carry SUBSCRIPTION.
-- Operators may DELETE individual service_capabilities rows afterward for
-- one-off / cart-only services that must not appear on contracts.
--
--   mysql -h <host> -u blue_app -p <database> < database/phase20_subscription_capabilities_migration.sql
--
-- =====================================================================

INSERT INTO service_capabilities (service_id, capability_type_id, created_at)
SELECT
    s.id,
    t.id,
    CURRENT_TIMESTAMP(6)
FROM services AS s
INNER JOIN service_capability_types AS t
    ON t.code = 'SUBSCRIPTION'
   AND t.is_active = 1
WHERE s.is_active = 1
  AND NOT EXISTS (
      SELECT 1
      FROM service_capabilities AS sc
      WHERE sc.service_id = s.id
        AND sc.capability_type_id = t.id
  );
