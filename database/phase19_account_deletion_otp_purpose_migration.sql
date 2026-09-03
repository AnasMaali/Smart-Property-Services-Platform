-- =====================================================================
-- BLUE V1 Phase 19 - Customer Account Deletion OTP
-- ONE additive, DML-only reference-data upgrade.
-- =====================================================================
--
-- Adds `ACCOUNT_DELETION` to `otp_verification_purposes` so
-- App\Actions\Auth\IssueAccountDeletionOtpAction and DeleteAccountAction
-- can verify OTP before erasing a customer account (OTP-only mobile flow).
--
-- Safe to re-run: ON DUPLICATE KEY UPDATE is a no-op when the row exists.
--
--   mysql -h <host> -u blue_app -p <database> < database/phase19_account_deletion_otp_purpose_migration.sql
--
-- =====================================================================

INSERT INTO otp_verification_purposes (
    code,
    name,
    description,
    is_active
)
VALUES
(
    'ACCOUNT_DELETION',
    'Account Deletion',
    'Used to confirm account deletion via OTP before erasing customer data.',
    TRUE
) AS new
ON DUPLICATE KEY UPDATE
    name = new.name,
    description = new.description,
    is_active = new.is_active;
