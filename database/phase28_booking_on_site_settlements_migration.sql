-- =====================================================================
-- BLUE V1 Phase B24 companion - booking_on_site_settlements
-- ONE additive CREATE TABLE, idempotent via IF NOT EXISTS.
-- =====================================================================
--
-- CancelBookingAction (BLUE V1 Phase B24 comment in that Action) updates
-- this table on every real cancellation: a PAY_ON_SITE booking whose cash
-- was already collected is flagged `refund_status = MANUAL_REFUND_REQUIRED`
-- instead of creating a Stripe refund. Card/Apple-Pay cancellations hit
-- the same UPDATE; it matches zero rows when no settlement exists.
--
-- Admin financial surfaces also read this table:
--   App\Support\Admin\AdminFinancialSummaryCalculator
--   App\Actions\Admin\Financial\AdminListFinancialLedgerAction
--
-- Columns below are exactly those those callers already select/update.
-- UNIQUE(booking_id) is the backstop documented on the calculator:
-- one settlement row per Booking, so a second collection is impossible.
-- There is no currency_id (the calculator's own docblock: an on-site
-- settlement is denominated in its Booking's cart currency; BLUE V1 is
-- AED-only).
--
-- Apply with (DDL-capable credentials required):
--   mysql -h <host> -u <ddl_capable_user> -p blue_db < database/phase28_booking_on_site_settlements_migration.sql
--   mysql -h <host> -u <ddl_capable_user> -p blue_test_db < database/phase28_booking_on_site_settlements_migration.sql
--
-- =====================================================================

CREATE TABLE IF NOT EXISTS `booking_on_site_settlements` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `booking_id` binary(16) NOT NULL,
  `amount_due` decimal(19,6) NOT NULL,
  `amount_collected` decimal(19,6) DEFAULT NULL,
  `collected_at` datetime(6) DEFAULT NULL,
  `refund_status` varchar(40) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_on_site_settlements_booking` (`booking_id`),
  KEY `idx_booking_on_site_settlements_collected_at` (`collected_at`),
  CONSTRAINT `fk_booking_on_site_settlements_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_booking_on_site_settlements_amount_due` CHECK ((`amount_due` >= 0)),
  CONSTRAINT `chk_booking_on_site_settlements_amount_collected` CHECK (((`amount_collected` is null) or (`amount_collected` >= 0))),
  CONSTRAINT `chk_booking_on_site_settlements_collection_pair` CHECK ((((`collected_at` is null) and (`amount_collected` is null)) or ((`collected_at` is not null) and (`amount_collected` is not null)))),
  CONSTRAINT `chk_booking_on_site_settlements_refund_status` CHECK (((`refund_status` is null) or (`refund_status` = _utf8mb4'MANUAL_REFUND_REQUIRED'))),
  CONSTRAINT `chk_booking_on_site_settlements_refund_requires_collection` CHECK (((`refund_status` is null) or (`collected_at` is not null)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
