-- =====================================================================
-- BLUE V1 Phase B24 - Service Payment Policy + Card / Apple Pay / Pay on
-- Site. Additive-only, idempotent (existing-table ALTERs guarded via
-- information_schema + dynamic SQL, same idiom as phase17/18/21/22/23;
-- new tables use CREATE TABLE IF NOT EXISTS, same idiom as phase19/23).
-- =====================================================================
--
-- WHAT THIS ADDS (never DROPs, DELETEs, or destructively rewrites any
-- existing row):
--
--   1. `payment_method_types` - a small reference/lookup table (same
--      shape/convention as `service_capability_types`): CARD, APPLE_PAY,
--      PAY_ON_SITE. `service_payment_methods` maps which of these each
--      Service allows. Deliberately NO separate `services.
--      requires_prepayment` column: that fact is fully derivable as
--      `! allowed_methods.contains('PAY_ON_SITE')` (see App\Support\
--      Payment\ServicePaymentPolicy) - storing it separately would be
--      duplicated, driftable state for a value with only one honest
--      source of truth. This also means "a prepayment-required Service
--      can never allow PAY_ON_SITE" is a STRUCTURAL truth, not a rule to
--      separately validate: there is nothing to contradict, because
--      requires_prepayment is never independently settable.
--
--   2. Two new lookup rows on EXISTING tables: `booking_sources.
--      PAY_ON_SITE` (a Booking created without a new customer Payment,
--      confirmed for on-site cash collection - mirrors CONTRACT's own
--      "no new Payment" precedent) and `booking_statuses.CONFIRMED` (a
--      Booking that is accepted and scheduled but whose payment is still
--      due on-site - deliberately NEVER called PAID, since no money has
--      been captured yet). `App\Support\Booking\BookingStatusMachine` is
--      extended (2 methods) to treat CONFIRMED identically to PAID for
--      the ASSIGNED/CANCELLED transitions - the only two places in the
--      whole codebase that reference `'PAID'` by name (confirmed by
--      grep before this migration was written).
--
--   3. `bookings.payment_method_code` - an additive historical SNAPSHOT
--      (varchar, CARD/APPLE_PAY/PAY_ON_SITE) of which method actually
--      created this Booking, written once at Booking-creation time and
--      never touched again. This is the "historical payment method
--      snapshot" a later Admin payment-policy change must never be able
--      to alter (BLUE V1 catalog spec Phase B24 section 21) - it is
--      never inferred by re-querying the Service's CURRENT
--      `service_payment_methods`. Nullable because it does not
--      retroactively apply to any Booking that predates this migration
--      (there are none in a real environment yet, but the column must
--      never invent a value for a row it cannot truthfully know).
--
--   4. `bookings.idempotency_key` - the Pay-on-Site booking-creation
--      path's dedup key, binary(32) sha256 hash of a client-supplied
--      Idempotency-Key header, mirroring `payment_attempts.
--      idempotency_key` exactly (same hash convention, same UNIQUE
--      backstop). NULL for every STANDARD/CONTRACT Booking (multiple
--      NULLs are allowed in a UNIQUE index - the same pattern `bookings.
--      payment_attempt_id`/`uq_bookings_payment_attempt` already uses).
--
--   5. `booking_on_site_settlements` - the truthful post-payment record
--      Phase B24 section 17 requires: amount due, amount actually
--      collected (NULL until collected), who/when collected it, and an
--      honest `refund_status` flag (`MANUAL_REFUND_REQUIRED`) set when a
--      Booking that already had cash collected is later cancelled -
--      never an automated Stripe refund for cash, and never silently
--      reported as already refunded. Deliberately a NEW table, not new
--      columns on `payment_attempts` (that table is providerspecific and
--      already schema-validated in tests/Feature/Payment/SecurityTest.php
--      to contain no non-provider financial concepts) and not new
--      columns on `bookings` (this is a distinct, later-resolved
--      lifecycle fact, exactly the same reasoning
--      database/phase19_booking_refund_automation_migration.sql already
--      documents for why `booking_refunds` is its own table rather than
--      new `bookings` columns).
--
-- BACKWARDS COMPATIBILITY (section 6): every EXISTING `services` row
-- that has no `service_payment_methods` row yet is backfilled to CARD +
-- APPLE_PAY allowed, PAY_ON_SITE NOT allowed - preserving exactly
-- today's de-facto behavior (100% online-Stripe-prepaid), never
-- defaulting to PAY_ON_SITE. Apple Pay is included in this default
-- because it already requires zero backend change beyond this phase
-- (see docs/api-contracts/payments-v1.md "Apple Pay readiness" and
-- docs/api-contracts/apple-pay-future-checklist.md, both pre-existing):
-- Apple Pay is a Stripe wallet surfaced through the exact same
-- PaymentIntent BLUE already creates (`automatic_payment_methods.
-- enabled = true`), never a second payment system.
--
-- Apply with (DDL-capable credentials required):
--   mysql -h <host> -u <ddl_capable_user> -p blue_db < database/phase24_payment_policy_migration.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- SECTION 1 - payment_method_types (reference table)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `payment_method_types` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_method_types_code` (`code`),
  KEY `idx_payment_method_types_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_payment_method_types_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_payment_method_types_code` CHECK ((char_length(trim(`code`)) between 2 and 40)),
  CONSTRAINT `chk_payment_method_types_description` CHECK (((`description` is null) or (char_length(trim(`description`)) > 0))),
  CONSTRAINT `chk_payment_method_types_name` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO payment_method_types (code, name, description, display_order, is_active)
VALUES
    ('CARD', 'Credit Card', 'Card payment via Stripe (PaymentIntent).', 1, TRUE),
    ('APPLE_PAY', 'Apple Pay', 'Apple Pay via the same Stripe PaymentIntent as Card - never a second payment system.', 2, TRUE),
    ('PAY_ON_SITE', 'Pay on Site', 'Customer pays in cash on-site once the Service is delivered - no online prepayment.', 3, TRUE)
AS new
ON DUPLICATE KEY UPDATE name = new.name, description = new.description, display_order = new.display_order, is_active = new.is_active;

-- ---------------------------------------------------------------------
-- SECTION 2 - service_payment_methods
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `service_payment_methods` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `service_id` binary(16) NOT NULL,
  `payment_method_type_id` tinyint unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_payment_methods_service_type` (`service_id`,`payment_method_type_id`),
  KEY `idx_service_payment_methods_type` (`payment_method_type_id`),
  CONSTRAINT `fk_service_payment_methods_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_service_payment_methods_type` FOREIGN KEY (`payment_method_type_id`) REFERENCES `payment_method_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_service_payment_methods_active` CHECK ((`is_active` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- SECTION 3 - booking_sources.PAY_ON_SITE / booking_statuses.CONFIRMED
-- ---------------------------------------------------------------------

INSERT INTO booking_sources (code, name, description, display_order, is_active)
VALUES (
    'PAY_ON_SITE',
    'Pay on Site',
    'A Booking created without a new customer Payment because every Service in the Cart allows on-site cash payment; the amount is due on-site.',
    3,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE name = new.name, description = new.description, display_order = new.display_order, is_active = new.is_active;

INSERT INTO booking_statuses (code, name, description, display_order, is_active)
VALUES (
    'CONFIRMED',
    'Confirmed',
    'The Booking is accepted and scheduled; payment is due on-site and has not been collected yet. Never used for a Stripe-paid Booking.',
    0,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE name = new.name, description = new.description, is_active = new.is_active;

-- ---------------------------------------------------------------------
-- SECTION 4 - bookings.payment_method_code / bookings.idempotency_key
-- ---------------------------------------------------------------------

SET @col_needs_update := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'payment_method_code'
);
SET @ddl := IF(
    @col_needs_update = 0,
    'ALTER TABLE `bookings` ADD COLUMN `payment_method_code` varchar(20) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER `booking_source_id`',
    'SELECT ''bookings.payment_method_code already exists - skipped'' AS status'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @chk_needs_update := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'chk_bookings_payment_method_code'
);
SET @ddl := IF(
    @chk_needs_update = 0,
    'ALTER TABLE `bookings` ADD CONSTRAINT `chk_bookings_payment_method_code` CHECK ((`payment_method_code` is null) or (`payment_method_code` in (''CARD'',''APPLE_PAY'',''PAY_ON_SITE'')))',
    'SELECT ''chk_bookings_payment_method_code already exists - skipped'' AS status'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_needs_update := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'idempotency_key'
);
SET @ddl := IF(
    @col_needs_update = 0,
    'ALTER TABLE `bookings` ADD COLUMN `idempotency_key` binary(32) DEFAULT NULL AFTER `payment_method_code`',
    'SELECT ''bookings.idempotency_key already exists - skipped'' AS status'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_needs_update := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND INDEX_NAME = 'uq_bookings_idempotency_key'
);
SET @ddl := IF(
    @idx_needs_update = 0,
    'ALTER TABLE `bookings` ADD UNIQUE KEY `uq_bookings_idempotency_key` (`idempotency_key`)',
    'SELECT ''uq_bookings_idempotency_key already exists - skipped'' AS status'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- SECTION 5 - booking_on_site_settlements
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `booking_on_site_settlements` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `booking_id` binary(16) NOT NULL,
  `amount_due` decimal(19,6) NOT NULL,
  `amount_collected` decimal(19,6) DEFAULT NULL,
  `collected_at` datetime(6) DEFAULT NULL,
  `collected_by_admin_user_id` binary(16) DEFAULT NULL,
  `refund_status` varchar(30) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_on_site_settlements_booking` (`booking_id`),
  KEY `idx_booking_on_site_settlements_collected_by` (`collected_by_admin_user_id`),
  CONSTRAINT `fk_booking_on_site_settlements_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_booking_on_site_settlements_collected_by` FOREIGN KEY (`collected_by_admin_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_booking_on_site_settlements_amount_due` CHECK ((`amount_due` >= 0)),
  CONSTRAINT `chk_booking_on_site_settlements_amount_collected` CHECK (((`amount_collected` is null) or (`amount_collected` >= 0))),
  CONSTRAINT `chk_booking_on_site_settlements_collection_data` CHECK ((((`collected_at` is null) and (`amount_collected` is null) and (`collected_by_admin_user_id` is null)) or ((`collected_at` is not null) and (`amount_collected` is not null) and (`collected_by_admin_user_id` is not null)))),
  CONSTRAINT `chk_booking_on_site_settlements_refund_status` CHECK (((`refund_status` is null) or (`refund_status` = 'MANUAL_REFUND_REQUIRED'))),
  CONSTRAINT `chk_booking_on_site_settlements_refund_requires_collection` CHECK (((`refund_status` is null) or (`collected_at` is not null)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- SECTION 6 - backwards-compatible backfill for EXISTING services
-- (CARD + APPLE_PAY allowed, PAY_ON_SITE not allowed - see section 6 of
-- the phase spec; never applied to a Service that already has a policy,
-- so re-running this migration is a safe no-op for anything an Admin has
-- since configured).
-- ---------------------------------------------------------------------

INSERT INTO service_payment_methods (id, service_id, payment_method_type_id, is_active, created_at, updated_at)
SELECT
    uuid_to_bin(uuid(), 1),
    s.id,
    pmt.id,
    1,
    NOW(6),
    NOW(6)
FROM services s
CROSS JOIN payment_method_types pmt
WHERE pmt.code IN ('CARD', 'APPLE_PAY')
  AND NOT EXISTS (
      SELECT 1 FROM service_payment_methods spm WHERE spm.service_id = s.id
  );
