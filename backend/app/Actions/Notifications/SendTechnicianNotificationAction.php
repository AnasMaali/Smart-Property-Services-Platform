<?php

namespace App\Actions\Notifications;

use App\Support\Notifications\Gateway\NotificationDispatchData;
use App\Support\Notifications\Gateway\NotificationDispatchOutcome;
use App\Support\Notifications\Gateway\TechnicianNotificationGateway;
use App\Support\Notifications\OutboundNotificationStatuses;
use App\Support\Notifications\TechnicianJobNotificationContent;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B21 - sends exactly one WhatsApp message for one
 * `outbound_notifications` obligation. The one place
 * `outbound_notifications.status_id` is ever written after creation,
 * mirroring App\Actions\Payment\ExecuteBookingRefundAction's role for
 * `booking_refunds` exactly.
 *
 * Deliberately never runs inside the DB transaction that creates the
 * obligation (App\Actions\Notifications\
 * CreateTechnicianAssignmentNotificationAction, called from the Admin
 * assign/reassign Actions) - a DB transaction and a WhatsApp HTTP call
 * cannot be one atomic unit. Two independent callers invoke this with the
 * SAME safe, idempotent semantics:
 *
 * 1. App\Actions\Admin\Technician\AdminAssignTechnicianAction /
 *    AdminReassignTechnicianAction, once, best-effort, immediately AFTER
 *    their assignment transaction commits (never inside it).
 * 2. App\Console\Commands\SendPendingTechnicianNotifications, the recovery
 *    path for every obligation that best-effort attempt could not
 *    resolve.
 *
 * Idempotency is guaranteed the same two ways ExecuteBookingRefundAction
 * documents for refunds: (a) this method is a safe no-op the instant the
 * row is no longer PENDING, and (b) `outbound_notifications.
 * idempotency_key`, persisted once at obligation-creation time - the Meta
 * WhatsApp Cloud API has no native request-level idempotency key (unlike
 * Stripe), so BLUE's own PENDING-only guard here, together with the
 * `idempotency_key` UNIQUE constraint at creation time, is the ENTIRE
 * idempotency guarantee for this provider.
 *
 * A NotificationDispatchOutcome::UNKNOWN result (only ever an explicit,
 * definitive HTTP 429 rate-limit response - a genuine round-trip proving
 * no message was created) makes NO status-changing write until
 * config('technician_notifications.max_attempts') is reached - the row
 * stays PENDING and safe to retry, exactly as
 * ExecuteBookingRefundAction's own UNKNOWN branch does for refunds. A 5xx
 * or a connection/timeout failure is never treated this way - see
 * NotificationDispatchOutcome::AMBIGUOUS and persistAmbiguous() below.
 */
final class SendTechnicianNotificationAction
{
    private const PHONE_PATTERN = '/^\+[1-9]\d{7,14}$/';

    public function __construct(
        private readonly TechnicianNotificationGateway $gateway,
    ) {}

    public function handle(string $notificationUuid): void
    {
        $idBinary = UuidBinary::toBinary($notificationUuid);

        $row = DB::table('outbound_notifications')->where('id', $idBinary)->first();

        if ($row === null || (int) $row->status_id !== OutboundNotificationStatuses::id('PENDING')) {
            // Already resolved (SUBMITTED/FAILED/SKIPPED) or does not
            // exist - never send for a non-PENDING/non-existent
            // obligation.
            return;
        }

        // BLUE V1 WhatsApp spec section 10 - a NEW_ASSIGNMENT obligation
        // must never send a stale "you've been assigned" message if the
        // assignment it describes was released (reassigned away) before
        // this attempt ran. Checked fresh on every attempt, not only at
        // creation time, since a reassignment can happen at any point
        // between obligation creation and a later recovery-command retry.
        if (
            $row->notification_type === 'TECHNICIAN_NEW_ASSIGNMENT'
            && $row->technician_assignment_id !== null
            && $this->assignmentIsNoLongerActive($row->technician_assignment_id)
        ) {
            $this->markSkipped($row->id);

            return;
        }

        if (! preg_match(self::PHONE_PATTERN, (string) $row->recipient_address_snapshot)) {
            $this->persistFailure($row->id, $row->attempt_count, 'INVALID_PHONE_FORMAT', 'The technician\'s phone number is not in a valid E.164 format.');

            return;
        }

        $data = $this->buildDispatchData($row);

        $result = $this->gateway->send($data);

        match ($result->outcome) {
            NotificationDispatchOutcome::SUBMITTED => $this->persistSubmitted($row->id, $row->attempt_count, $result->providerMessageReference),
            NotificationDispatchOutcome::DEFINITIVE_FAILURE => $this->persistFailure($row->id, $row->attempt_count, $result->failureCode, $result->failureMessage),
            NotificationDispatchOutcome::UNKNOWN => $this->persistTransientFailure($row->id, $row->attempt_count, $result->failureMessage),
            NotificationDispatchOutcome::AMBIGUOUS => $this->persistAmbiguous($row->id, $row->attempt_count, $result->failureMessage),
        };
    }

    /**
     * True when the assignment has been released (reassigned/removed) OR
     * no longer exists at all - either way, never a reason to send a
     * "you've been newly assigned" message.
     */
    private function assignmentIsNoLongerActive(string $assignmentIdBinary): bool
    {
        $assignment = DB::table('technician_assignments')->where('id', $assignmentIdBinary)->first(['released_at']);

        return $assignment === null || $assignment->released_at !== null;
    }

    private function buildDispatchData(object $row): NotificationDispatchData
    {
        $fields = json_decode((string) $row->payload_snapshot, true);

        $isNewAssignment = $row->notification_type === 'TECHNICIAN_NEW_ASSIGNMENT';

        return new NotificationDispatchData(
            notificationUuid: UuidBinary::toString($row->id),
            recipientPhoneNumber: (string) $row->recipient_address_snapshot,
            templateName: (string) config($isNewAssignment
                ? 'services.whatsapp.assignment_template'
                : 'services.whatsapp.unassignment_template'),
            templateLanguage: (string) config('services.whatsapp.template_language', 'en'),
            templateParameters: $isNewAssignment
                ? TechnicianJobNotificationContent::assignmentTemplateParameters($fields)
                : TechnicianJobNotificationContent::assignmentRemovedTemplateParameters($fields),
            renderedText: $isNewAssignment
                ? TechnicianJobNotificationContent::renderAssignmentText($fields)
                : TechnicianJobNotificationContent::renderAssignmentRemovedText($fields),
            providerIdempotencyKey: (string) $row->idempotency_key,
        );
    }

    private function markSkipped(string $idBinary): void
    {
        DB::transaction(function () use ($idBinary): void {
            $locked = DB::table('outbound_notifications')->where('id', $idBinary)->lockForUpdate()->first();

            if ($locked === null || (int) $locked->status_id !== OutboundNotificationStatuses::id('PENDING')) {
                return;
            }

            DB::table('outbound_notifications')->where('id', $locked->id)->update([
                'status_id' => OutboundNotificationStatuses::id('SKIPPED'),
                'updated_at' => now()->format('Y-m-d H:i:s.u'),
            ]);
        });
    }

    private function persistSubmitted(string $idBinary, int $attemptCountBefore, ?string $providerMessageReference): void
    {
        DB::transaction(function () use ($idBinary, $attemptCountBefore, $providerMessageReference): void {
            $locked = DB::table('outbound_notifications')->where('id', $idBinary)->lockForUpdate()->first();

            if ($locked === null || (int) $locked->status_id !== OutboundNotificationStatuses::id('PENDING')) {
                return;
            }

            $timestamp = now()->format('Y-m-d H:i:s.u');

            DB::table('outbound_notifications')->where('id', $locked->id)->update([
                'status_id' => OutboundNotificationStatuses::id('SUBMITTED'),
                'provider_message_reference' => $providerMessageReference,
                'attempt_count' => $attemptCountBefore + 1,
                'submitted_at' => $timestamp,
                'next_attempt_at' => null,
                'updated_at' => $timestamp,
            ]);
        });
    }

    private function persistFailure(string $idBinary, int $attemptCountBefore, ?string $failureCode, ?string $failureMessage): void
    {
        DB::transaction(function () use ($idBinary, $attemptCountBefore, $failureCode, $failureMessage): void {
            $locked = DB::table('outbound_notifications')->where('id', $idBinary)->lockForUpdate()->first();

            if ($locked === null || (int) $locked->status_id !== OutboundNotificationStatuses::id('PENDING')) {
                return;
            }

            $timestamp = now()->format('Y-m-d H:i:s.u');

            DB::table('outbound_notifications')->where('id', $locked->id)->update([
                'status_id' => OutboundNotificationStatuses::id('FAILED'),
                'attempt_count' => $attemptCountBefore + 1,
                'failed_at' => $timestamp,
                'last_error_code' => $failureCode ?? 'WHATSAPP_NOTIFICATION_REJECTED',
                'last_error_message' => $failureMessage ?? 'WhatsApp rejected the notification request.',
                'next_attempt_at' => null,
                'updated_at' => $timestamp,
            ]);
        });
    }

    /**
     * An UNKNOWN outcome - Meta's own explicit HTTP 429 rate-limit
     * response, the one failure status that proves no message was
     * created - stays PENDING and retryable up to
     * config('technician_notifications.max_attempts') - beyond that, it
     * converts to a terminal FAILED so a persistently rate-limited
     * provider can never be retried forever (BLUE V1 WhatsApp spec
     * section 15). Never used for a 5xx or a genuinely ambiguous
     * (no-response) outcome - see persistAmbiguous() for both of those.
     */
    private function persistTransientFailure(string $idBinary, int $attemptCountBefore, ?string $failureMessage): void
    {
        $maxAttempts = (int) config('technician_notifications.max_attempts', 5);
        $newAttemptCount = $attemptCountBefore + 1;

        DB::transaction(function () use ($idBinary, $newAttemptCount, $maxAttempts, $failureMessage): void {
            $locked = DB::table('outbound_notifications')->where('id', $idBinary)->lockForUpdate()->first();

            if ($locked === null || (int) $locked->status_id !== OutboundNotificationStatuses::id('PENDING')) {
                return;
            }

            $timestamp = now()->format('Y-m-d H:i:s.u');

            if ($newAttemptCount >= $maxAttempts) {
                DB::table('outbound_notifications')->where('id', $locked->id)->update([
                    'status_id' => OutboundNotificationStatuses::id('FAILED'),
                    'attempt_count' => $newAttemptCount,
                    'failed_at' => $timestamp,
                    'last_error_code' => 'MAX_ATTEMPTS_EXCEEDED',
                    'last_error_message' => $failureMessage ?? 'The WhatsApp notification could not be delivered after the maximum number of attempts.',
                    'next_attempt_at' => null,
                    'updated_at' => $timestamp,
                ]);

                return;
            }

            // Simple linear backoff (attempt number x 5 minutes) - bounded
            // by max_attempts above, so this can never grow unbounded.
            DB::table('outbound_notifications')->where('id', $locked->id)->update([
                'attempt_count' => $newAttemptCount,
                'next_attempt_at' => now()->addMinutes($newAttemptCount * 5)->format('Y-m-d H:i:s.u'),
                'updated_at' => $timestamp,
            ]);
        });
    }

    /**
     * An AMBIGUOUS outcome - either the provider round-trip itself failed
     * (connection error/timeout, no response ever received) OR Meta
     * returned an HTTP 5xx (a server-side error response does NOT, by
     * itself, prove the message was never created/accepted before the
     * error was returned) - is NEVER auto-retried, because the Meta
     * WhatsApp Cloud API has no request-level idempotency key: BLUE
     * cannot tell whether the request actually reached Meta and created a
     * real message before the failure occurred, so blindly resending
     * risks a second real WhatsApp message to the Technician. This is
     * immediately terminal
     * (RECONCILIATION_REQUIRED) on the FIRST ambiguous outcome - never
     * given the UNKNOWN branch's retry/backoff treatment - and is
     * excluded from both the recovery command's PENDING-only query and
     * the ordinary Admin retry endpoint (see
     * App\Actions\Admin\Notifications\
     * AdminRetryTechnicianNotificationAction). Recovering from this
     * state requires an explicit, out-of-band human decision - see
     * docs/handoff/technician-whatsapp-v1.md.
     */
    private function persistAmbiguous(string $idBinary, int $attemptCountBefore, ?string $failureMessage): void
    {
        DB::transaction(function () use ($idBinary, $attemptCountBefore, $failureMessage): void {
            $locked = DB::table('outbound_notifications')->where('id', $idBinary)->lockForUpdate()->first();

            if ($locked === null || (int) $locked->status_id !== OutboundNotificationStatuses::id('PENDING')) {
                return;
            }

            $timestamp = now()->format('Y-m-d H:i:s.u');

            DB::table('outbound_notifications')->where('id', $locked->id)->update([
                'status_id' => OutboundNotificationStatuses::id('RECONCILIATION_REQUIRED'),
                'attempt_count' => $attemptCountBefore + 1,
                'failed_at' => $timestamp,
                'last_error_code' => 'PROVIDER_RESPONSE_UNCONFIRMED',
                'last_error_message' => $failureMessage ?? 'The WhatsApp Cloud API request outcome could not be confirmed.',
                'next_attempt_at' => null,
                'updated_at' => $timestamp,
            ]);
        });
    }
}
