-- =====================================================================
-- BLUE V1 Phase B20 - Automated Booking Refunds via Stripe
-- TWO additive, idempotent schema changes (never DROPs, DELETEs, or
-- destructively rewrites any existing row):
--
--   1. `booking_refund_statuses` - a small reference/lookup table, same
--      shape and convention as `payment_webhook_event_statuses` /
--      `booking_statuses`. Seeded with PENDING / SUCCEEDED / FAILED /
--      RECONCILIATION_REQUIRED (fix phase 2 - see below).
--   2. `booking_refunds` - one Stripe refund EXECUTION obligation per
--      cancelled, payment-backed (STANDARD) Booking. Distinct from
--      `bookings.cancellation_refund_percentage` /
--      `cancellation_refund_amount` (the existing, unchanged POLICY
--      snapshot written by App\Actions\Booking\CancelBookingAction at the
--      moment of cancellation) - this table tracks the separate, later-
--      resolved lifecycle of actually SENDING that money back through
--      Stripe: idempotency key, provider refund reference, and
--      execution status (PENDING -> SUCCEEDED / FAILED /
--      RECONCILIATION_REQUIRED), confirmed either synchronously by the
--      Stripe API response or asynchronously by the existing Stripe
--      webhook processor (App\Actions\Payment\ProcessPaymentWebhookAction,
--      extended - never a second webhook endpoint - to also finalize
--      `charge.refunded` events).
--
-- RECONCILIATION_REQUIRED (fix phase 2 - BLUE V1 is AED-only, single
-- currency, single minor_unit=2): an authoritative ('succeeded') Stripe
-- refund webhook whose own reported amount/currency does not match the
-- persisted obligation is a genuine financial anomaly, never something to
-- silently accept or silently retry - see App\Actions\Payment\
-- ProcessPaymentWebhookAction::processRefundEvent(). PENDING-only recovery
-- queries (App\Console\Commands\ExecutePendingBookingRefunds) never select
-- this status, so a mismatched obligation can never trigger a second
-- Stripe refund attempt; it is a terminal state requiring a human, exactly
-- like FAILED, just with a distinct machine-readable code an Admin UI can
-- tell apart from an ordinary provider rejection.
--
-- WHY A SEPARATE TABLE (not new columns on `bookings`):
-- `bookings.cancellation_refund_percentage`/`_amount` are a frozen,
-- write-once-at-cancellation POLICY fact and must never be touched again
-- (see CancelBookingAction's docblock: "a later change to
-- config('cancellation.*') can never retroactively change what an
-- already-cancelled Booking is shown to owe"). The Stripe EXECUTION
-- lifecycle is a completely different concern that resolves over time
-- (possibly across retries, possibly confirmed by a webhook that arrives
-- seconds or minutes later) and must be safely retryable without ever
-- risking a second refund - overloading the frozen policy columns for
-- this would conflate "what is owed" with "what has actually been paid
-- back," and would have no natural place to store idempotency_key /
-- provider_refund_reference / execution timestamps.
--
-- UNIQUE(booking_id) is the hard backstop against ever creating a second
-- refund obligation for the same Booking (mirrors `bookings.uq_bookings_
-- payment_attempt`'s one-attempt-per-Booking convention). UNIQUE
-- (idempotency_key) and UNIQUE(provider_code, provider_refund_reference)
-- are the same double backstop `payment_attempts` already uses for its own
-- provider-facing identifiers.
--
-- Apply with (DDL-capable credentials required):
--   mysql -h <host> -u <ddl_capable_user> -p blue_db < database/phase19_booking_refund_automation_migration.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- SECTION 1 - booking_refund_statuses (reference table)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `booking_refund_statuses` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_refund_statuses_code` (`code`),
  KEY `idx_booking_refund_statuses_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_booking_refund_statuses_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_booking_refund_statuses_code` CHECK ((char_length(trim(`code`)) between 2 and 40)),
  CONSTRAINT `chk_booking_refund_statuses_description` CHECK (((`description` is null) or (char_length(trim(`description`)) between 2 and 300))),
  CONSTRAINT `chk_booking_refund_statuses_name` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO booking_refund_statuses (
    code,
    name,
    description,
    display_order,
    is_active
)
VALUES
(
    'PENDING',
    'Pending',
    'The refund obligation exists but Stripe has not yet confirmed it succeeded - safe and required to retry.',
    1,
    TRUE
),
(
    'SUCCEEDED',
    'Succeeded',
    'Stripe confirmed the refund was returned to the original payment method.',
    2,
    TRUE
),
(
    'FAILED',
    'Failed',
    'Stripe definitively rejected the refund request (e.g. already fully refunded, invalid parameters) - not retryable without operator intervention.',
    3,
    TRUE
),
(
    'RECONCILIATION_REQUIRED',
    'Reconciliation required',
    'An authoritative Stripe refund webhook reported an amount or currency that did not match the persisted obligation (BLUE V1 is AED-only) - not automatically retryable, requires operator investigation. Never regressed to PENDING or auto-resolved.',
    4,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    display_order = new.display_order,
    is_active = new.is_active;

-- ---------------------------------------------------------------------
-- SECTION 2 - booking_refunds (execution ledger)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `booking_refunds` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `booking_id` binary(16) NOT NULL,
  `payment_attempt_id` binary(16) NOT NULL,
  `currency_id` smallint unsigned NOT NULL,
  `status_id` tinyint unsigned NOT NULL,
  `policy_percentage` tinyint unsigned NOT NULL,
  `requested_amount` decimal(19,6) NOT NULL,
  `provider_code` varchar(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `provider_refund_reference` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `provider_status_code` varchar(100) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `idempotency_key` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `initiated_by_user_id` binary(16) NOT NULL,
  `initiated_as` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `failure_code` varchar(100) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `failure_message` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `requested_at` datetime(6) NOT NULL,
  `submitted_at` datetime(6) DEFAULT NULL,
  `succeeded_at` datetime(6) DEFAULT NULL,
  `failed_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_refunds_booking` (`booking_id`),
  UNIQUE KEY `uq_booking_refunds_idempotency_key` (`idempotency_key`),
  UNIQUE KEY `uq_booking_refunds_provider_reference` (`provider_code`,`provider_refund_reference`),
  KEY `idx_booking_refunds_status_created` (`status_id`,`created_at`),
  KEY `idx_booking_refunds_payment_attempt` (`payment_attempt_id`),
  CONSTRAINT `fk_booking_refunds_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_booking_refunds_payment_attempt` FOREIGN KEY (`payment_attempt_id`) REFERENCES `payment_attempts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_booking_refunds_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_booking_refunds_status` FOREIGN KEY (`status_id`) REFERENCES `booking_refund_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_booking_refunds_initiated_by` FOREIGN KEY (`initiated_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_booking_refunds_policy_percentage` CHECK ((`policy_percentage` between 0 and 100)),
  CONSTRAINT `chk_booking_refunds_requested_amount` CHECK ((`requested_amount` >= 0)),
  CONSTRAINT `chk_booking_refunds_provider_code` CHECK ((char_length(trim(`provider_code`)) between 2 and 50)),
  CONSTRAINT `chk_booking_refunds_idempotency_key` CHECK ((char_length(trim(`idempotency_key`)) between 8 and 191)),
  CONSTRAINT `chk_booking_refunds_initiated_as` CHECK ((`initiated_as` in (_utf8mb4'CUSTOMER',_utf8mb4'ADMIN'))),
  CONSTRAINT `chk_booking_refunds_reason` CHECK (((`reason` is null) or (char_length(trim(`reason`)) between 2 and 500))),
  CONSTRAINT `chk_booking_refunds_failure_code` CHECK (((`failure_code` is null) or (char_length(trim(`failure_code`)) between 1 and 100))),
  CONSTRAINT `chk_booking_refunds_failure_message` CHECK (((`failure_message` is null) or (char_length(trim(`failure_message`)) between 2 and 500))),
  CONSTRAINT `chk_booking_refunds_submitted_at` CHECK (((`submitted_at` is null) or (`submitted_at` >= `requested_at`))),
  CONSTRAINT `chk_booking_refunds_succeeded_at` CHECK (((`succeeded_at` is null) or ((`succeeded_at` >= `requested_at`) and (`provider_refund_reference` is not null)))),
  CONSTRAINT `chk_booking_refunds_failed_at` CHECK (((`failed_at` is null) or (`failed_at` >= `requested_at`))),
  CONSTRAINT `chk_booking_refunds_single_final_state` CHECK (((`succeeded_at` is null) or (`failed_at` is null))),
  CONSTRAINT `chk_booking_refunds_failure_data` CHECK (((`failed_at` is null) and (`failure_code` is null) and (`failure_message` is null)) or ((`failed_at` is not null) and (`failure_code` is not null)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
