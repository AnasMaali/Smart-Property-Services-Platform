<?php

namespace App\Actions\Notifications;

use App\Mail\CustomerTechnicianAssignedMail;
use App\Mail\CustomerTechnicianChangedMail;
use App\Mail\TechnicianAssignmentRemovedMail;
use App\Mail\TechnicianNewAssignmentMail;
use App\Support\Notifications\Gateway\EmailDispatchData;
use App\Support\Notifications\Gateway\EmailDispatchOutcome;
use App\Support\Notifications\Gateway\EmailNotificationGateway;
use App\Support\Notifications\OutboundNotificationStatuses;
use App\Support\Uuid\UuidBinary;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * BLUE V1 Phase B22 - sends exactly one email for one EMAIL-channel
 * `outbound_notifications` obligation, mirroring App\Actions\Notifications\
 * SendTechnicianNotificationAction's role/placement for WhatsApp exactly:
 * never runs inside the DB transaction that creates the obligation (see
 * App\Actions\Notifications\CreateEmailNotificationAction, called from the
 * Admin assign/reassign Actions' $afterMutation hook) - called once,
 * best-effort, immediately AFTER that transaction commits, and again by the
 * shared recovery command (App\Console\Commands\
 * SendPendingTechnicianNotifications) for whatever that best-effort attempt
 * could not resolve.
 *
 * Idempotency: (a) a safe no-op the instant the row is no longer PENDING,
 * and (b) the persisted `idempotency_key` UNIQUE constraint at creation
 * time - the SAME two-part guarantee WhatsApp's equivalent Action
 * documents.
 *
 * Deliberately simpler than the WhatsApp Action: no AMBIGUOUS/
 * RECONCILIATION_REQUIRED branch exists here (see EmailDispatchOutcome's
 * docblock for why) - every send failure is bounded-retried
 * (config('email_notifications.max_attempts')) the same way a WhatsApp
 * 429 is, then becomes terminal FAILED.
 */
final class SendEmailNotificationAction
{
    public function __construct(
        private readonly EmailNotificationGateway $gateway,
    ) {}

    public function handle(string $notificationUuid): void
    {
        $idBinary = UuidBinary::toBinary($notificationUuid);

        $row = DB::table('outbound_notifications')->where('id', $idBinary)->first();

        if ($row === null || (int) $row->status_id !== OutboundNotificationStatuses::id('PENDING')) {
            return;
        }

        // BLUE V1 email spec section 9 - a NEW_ASSIGNMENT obligation must
        // never send a stale "you've been assigned" email if the
        // assignment it describes was released (reassigned away) before
        // this attempt ran - checked fresh on every attempt, mirroring
        // SendTechnicianNotificationAction's identical WhatsApp guard.
        if (
            $row->notification_type === 'TECHNICIAN_NEW_ASSIGNMENT_EMAIL'
            && $row->technician_assignment_id !== null
            && $this->assignmentIsNoLongerActive($row->technician_assignment_id)
        ) {
            $this->markSkipped($row->id);

            return;
        }

        if (! filter_var($row->recipient_address_snapshot, FILTER_VALIDATE_EMAIL)) {
            $this->persistFailure($row->id, $row->attempt_count, 'INVALID_EMAIL_FORMAT', 'The recipient email address is not valid.');

            return;
        }

        $fields = json_decode((string) $row->payload_snapshot, true);

        $data = new EmailDispatchData(
            notificationUuid: UuidBinary::toString($row->id),
            recipientAddress: (string) $row->recipient_address_snapshot,
            mailable: $this->buildMailable((string) $row->notification_type, $fields),
            providerIdempotencyKey: (string) $row->idempotency_key,
        );

        $result = $this->gateway->send($data);

        match ($result->outcome) {
            EmailDispatchOutcome::SUBMITTED => $this->persistSubmitted($row->id, $row->attempt_count, $result->providerMessageReference),
            EmailDispatchOutcome::FAILED => $this->persistTransientFailure($row->id, $row->attempt_count, $result->failureMessage),
        };
    }

    /**
     * @param  array<string, string|null>  $fields
     */
    private function buildMailable(string $notificationType, array $fields): Mailable
    {
        return match ($notificationType) {
            'TECHNICIAN_NEW_ASSIGNMENT_EMAIL' => new TechnicianNewAssignmentMail($fields),
            'TECHNICIAN_ASSIGNMENT_REMOVED_EMAIL' => new TechnicianAssignmentRemovedMail($fields),
            'CUSTOMER_TECHNICIAN_ASSIGNED_EMAIL' => new CustomerTechnicianAssignedMail($fields),
            'CUSTOMER_TECHNICIAN_CHANGED_EMAIL' => new CustomerTechnicianChangedMail($fields),
            default => throw new RuntimeException("Unsupported EMAIL notification_type: {$notificationType}"),
        };
    }

    private function assignmentIsNoLongerActive(string $assignmentIdBinary): bool
    {
        $assignment = DB::table('technician_assignments')->where('id', $assignmentIdBinary)->first(['released_at']);

        return $assignment === null || $assignment->released_at !== null;
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

    private function persistFailure(string $idBinary, int $attemptCountBefore, string $failureCode, string $failureMessage): void
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
                'last_error_code' => $failureCode,
                'last_error_message' => $failureMessage,
                'next_attempt_at' => null,
                'updated_at' => $timestamp,
            ]);
        });
    }

    /**
     * A send failure (SMTP connection error, provider rejection, etc.)
     * stays PENDING and retryable up to
     * config('email_notifications.max_attempts') - beyond that, it
     * converts to a terminal FAILED, mirroring
     * SendTechnicianNotificationAction's identical bounded linear-backoff
     * for WhatsApp's UNKNOWN outcome.
     */
    private function persistTransientFailure(string $idBinary, int $attemptCountBefore, ?string $failureMessage): void
    {
        $maxAttempts = (int) config('email_notifications.max_attempts', 5);
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
                    'last_error_message' => $failureMessage ?? 'The email notification could not be delivered after the maximum number of attempts.',
                    'next_attempt_at' => null,
                    'updated_at' => $timestamp,
                ]);

                return;
            }

            DB::table('outbound_notifications')->where('id', $locked->id)->update([
                'attempt_count' => $newAttemptCount,
                'next_attempt_at' => now()->addMinutes($newAttemptCount * 5)->format('Y-m-d H:i:s.u'),
                'updated_at' => $timestamp,
            ]);
        });
    }
}
