-- =====================================================================
-- BLUE V1 Phase B21 - Technician WhatsApp Job Notifications
-- TWO additive, idempotent schema changes (never DROPs, DELETEs, or
-- destructively rewrites any existing row):
--
--   1. `outbound_notification_statuses` - a small reference/lookup table,
--      same shape and convention as `booking_refund_statuses` /
--      `payment_webhook_event_statuses`. Seeded with PENDING / SUBMITTED /
--      FAILED / SKIPPED / RECONCILIATION_REQUIRED - see App\Support\
--      Notifications\OutboundNotificationStatuses for what each means and why no
--      DELIVERED/READ status exists in this phase (no delivery/read
--      webhook is implemented yet - never claim evidence this system does
--      not have).
--   2. `outbound_notifications` - one durable delivery obligation per
--      (Technician assignment, notification type) pair. Deliberately
--      GENERIC (channel/recipient_type columns), not
--      `technician_whatsapp_messages`, so a V2 Technician-app push
--      notification or any future channel can reuse this exact table
--      instead of a parallel one - see
--      App\Support\Notifications\Gateway\TechnicianNotificationGateway's
--      docblock. `technician_assignments` remains the sole canonical
--      record of WHO is assigned; this table only tracks whether/how that
--      fact was communicated - a completely secondary, always-retryable
--      concern.
--
-- WHY A SEPARATE TABLE (not columns on `technician_assignments`):
-- an assignment is written once and is either active or released -
-- notification delivery is a distinct, retryable lifecycle (PENDING ->
-- SUBMITTED / FAILED / SKIPPED, possibly across several attempts) that
-- must never block or be blocked by the assignment transaction itself
-- (BLUE V1 WhatsApp spec section 11: "A committed assignment must not
-- permanently lose its notification obligation merely because the
-- process crashes after commit").
--
-- UNIQUE(idempotency_key) is the hard backstop against ever sending the
-- same logical notification twice - deterministically derived from
-- (technician_assignment_id, notification_type) by App\Actions\
-- Notifications\CreateTechnicianAssignmentNotificationAction, mirroring
-- `booking_refunds.idempotency_key`'s role exactly.
--
-- RECONCILIATION_REQUIRED (fix - "no native idempotency key" safety gap):
-- unlike Stripe, the Meta WhatsApp Cloud API's send-message endpoint has
-- no request-level idempotency key, so an ambiguous provider outcome
-- (a network/timeout failure where BLUE never learns whether Meta already
-- accepted the message) can NEVER be safely auto-retried the way a
-- Stripe-side ambiguous outcome can - a blind retry risks a second real
-- WhatsApp message to the Technician. App\Support\Notifications\Gateway\
-- MetaWhatsAppTechnicianNotificationGateway distinguishes this
-- (NotificationDispatchOutcome::AMBIGUOUS) from an ordinary retryable
-- transient failure (NotificationDispatchOutcome::UNKNOWN, e.g. a
-- provider-returned 429/5xx, which DOES prove no message was created and
-- stays safely retryable) - see App\Actions\Notifications\
-- SendTechnicianNotificationAction. RECONCILIATION_REQUIRED is terminal
-- from the automatic system's perspective: never selected by the
-- recovery command, and never retryable through the ordinary Admin retry
-- endpoint - see docs/handoff/technician-whatsapp-v1.md for the required
-- manual/out-of-band recovery process.
--
-- Apply with (DDL-capable credentials required):
--   mysql -h <host> -u <ddl_capable_user> -p blue_db < database/phase20_technician_notifications_migration.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- SECTION 1 - outbound_notification_statuses (reference table)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `outbound_notification_statuses` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_outbound_notification_statuses_code` (`code`),
  KEY `idx_outbound_notification_statuses_active_order` (`is_active`,`display_order`),
  CONSTRAINT `chk_outbound_notification_statuses_active` CHECK ((`is_active` in (0,1))),
  CONSTRAINT `chk_outbound_notification_statuses_code` CHECK ((char_length(trim(`code`)) between 2 and 40)),
  CONSTRAINT `chk_outbound_notification_statuses_description` CHECK (((`description` is null) or (char_length(trim(`description`)) between 2 and 300))),
  CONSTRAINT `chk_outbound_notification_statuses_name` CHECK ((char_length(trim(`name`)) between 2 and 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO outbound_notification_statuses (
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
    'The notification obligation exists but has not yet been (successfully) sent - safe and required to retry.',
    1,
    TRUE
),
(
    'SUBMITTED',
    'Sent to WhatsApp',
    'The provider accepted the message. Proof of submission only - never proof of delivery or that it was read (no delivery/read webhook exists in this phase).',
    2,
    TRUE
),
(
    'FAILED',
    'Failed',
    'The provider definitively rejected the message, or the retry limit was exhausted - not retried automatically without operator intervention.',
    3,
    TRUE
),
(
    'SKIPPED',
    'Skipped',
    'Never sent because the Technician assignment it described was no longer active by send time (reassigned/released first) - not an error.',
    4,
    TRUE
),
(
    'RECONCILIATION_REQUIRED',
    'Needs review',
    'The provider outcome was ambiguous (e.g. a network/timeout failure with no confirmed response) - whether Meta already sent the message cannot be determined. Never auto-retried, since a blind retry could send a duplicate real WhatsApp message.',
    5,
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    display_order = new.display_order,
    is_active = new.is_active;

-- ---------------------------------------------------------------------
-- SECTION 2 - outbound_notifications (delivery obligation ledger)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `outbound_notifications` (
  `id` binary(16) NOT NULL DEFAULT (uuid_to_bin(uuid(),1)),
  `channel` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `notification_type` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `recipient_type` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `recipient_id` binary(16) NOT NULL,
  `recipient_address_snapshot` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `booking_id` binary(16) NOT NULL,
  `booking_item_id` binary(16) NOT NULL,
  `technician_assignment_id` binary(16) DEFAULT NULL,
  `status_id` tinyint unsigned NOT NULL,
  `idempotency_key` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_snapshot` json NOT NULL,
  `provider_message_reference` varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `attempt_count` smallint unsigned NOT NULL DEFAULT '0',
  `next_attempt_at` datetime(6) DEFAULT NULL,
  `submitted_at` datetime(6) DEFAULT NULL,
  `failed_at` datetime(6) DEFAULT NULL,
  `last_error_code` varchar(100) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `last_error_message` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_outbound_notifications_idempotency_key` (`idempotency_key`),
  KEY `idx_outbound_notifications_status_next_attempt` (`status_id`,`next_attempt_at`),
  KEY `idx_outbound_notifications_technician_assignment` (`technician_assignment_id`),
  KEY `idx_outbound_notifications_booking_item` (`booking_item_id`),
  KEY `idx_outbound_notifications_booking` (`booking_id`),
  KEY `idx_outbound_notifications_recipient` (`recipient_type`,`recipient_id`),
  CONSTRAINT `fk_outbound_notifications_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_outbound_notifications_booking_item` FOREIGN KEY (`booking_item_id`) REFERENCES `booking_items` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_outbound_notifications_technician_assignment` FOREIGN KEY (`technician_assignment_id`) REFERENCES `technician_assignments` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_outbound_notifications_status` FOREIGN KEY (`status_id`) REFERENCES `outbound_notification_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_outbound_notifications_channel` CHECK ((`channel` in (_utf8mb4'WHATSAPP'))),
  CONSTRAINT `chk_outbound_notifications_notification_type` CHECK ((`notification_type` in (_utf8mb4'TECHNICIAN_NEW_ASSIGNMENT',_utf8mb4'TECHNICIAN_ASSIGNMENT_REMOVED'))),
  CONSTRAINT `chk_outbound_notifications_recipient_type` CHECK ((`recipient_type` in (_utf8mb4'TECHNICIAN'))),
  CONSTRAINT `chk_outbound_notifications_recipient_address` CHECK ((char_length(trim(`recipient_address_snapshot`)) between 8 and 32)),
  CONSTRAINT `chk_outbound_notifications_idempotency_key` CHECK ((char_length(trim(`idempotency_key`)) between 8 and 191)),
  CONSTRAINT `chk_outbound_notifications_attempt_count` CHECK ((`attempt_count` >= 0)),
  CONSTRAINT `chk_outbound_notifications_failure_code` CHECK (((`last_error_code` is null) or (char_length(trim(`last_error_code`)) between 1 and 100))),
  CONSTRAINT `chk_outbound_notifications_failure_message` CHECK (((`last_error_message` is null) or (char_length(trim(`last_error_message`)) between 2 and 500))),
  CONSTRAINT `chk_outbound_notifications_submitted_at` CHECK (((`submitted_at` is null) or (`submitted_at` >= `created_at`))),
  CONSTRAINT `chk_outbound_notifications_failed_at` CHECK (((`failed_at` is null) or (`failed_at` >= `created_at`))),
  CONSTRAINT `chk_outbound_notifications_single_final_state` CHECK (((`submitted_at` is null) or (`failed_at` is null))),
  CONSTRAINT `chk_outbound_notifications_failure_data` CHECK ((((`failed_at` is null) and (`last_error_code` is null) and (`last_error_message` is null)) or ((`failed_at` is not null) and (`last_error_code` is not null)))),
  CONSTRAINT `chk_outbound_notifications_submitted_reference` CHECK (((`submitted_at` is null) or (`provider_message_reference` is not null)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
