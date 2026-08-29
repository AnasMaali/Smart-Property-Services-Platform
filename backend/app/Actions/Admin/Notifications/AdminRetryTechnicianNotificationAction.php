<?php

namespace App\Actions\Admin\Notifications;

use App\Actions\Notifications\SendTechnicianNotificationAction;
use App\Support\Admin\AdminMutationAuthorizationOutcome;
use App\Support\Admin\AdminMutationAuthorizer;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Notifications\OutboundNotificationStatuses;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * BLUE V1 Phase B21 - manual Admin retry for one `outbound_notifications`
 * obligation (`technicians.assign` capability - the same capability the
 * assign/reassign endpoints already require, since this is an
 * operational extension of the same Technician-notification concern, not
 * a new authorization surface).
 *
 * Retries the SAME durable obligation - never creates a second one
 * (`idempotency_key` is never regenerated here). Only PENDING/FAILED
 * (retryable) rows may be retried; SUBMITTED (already sent - retrying
 * would risk a duplicate WhatsApp message) and SKIPPED (the assignment it
 * described is no longer active - retrying it would send a stale
 * notification, exactly the race App\Actions\Notifications\
 * SendTechnicianNotificationAction's own stale-assignment guard exists to
 * prevent) are both rejected.
 */
final class AdminRetryTechnicianNotificationAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly SendTechnicianNotificationAction $sendNotification,
        private readonly AdminMutationAuthorizer $mutationAuthorizer = new AdminMutationAuthorizer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $notificationUuid, string $actorUserUuid): array
    {
        if (! Str::isUuid($notificationUuid)) {
            return $this->notFound('Notification not found.');
        }

        $authorization = $this->mutationAuthorizer->checkBinary(UuidBinary::toBinary($actorUserUuid));

        if ($authorization !== AdminMutationAuthorizationOutcome::AUTHORIZED) {
            return ['success' => false, 'status' => 403, 'message' => 'You are not authorized to perform this action.', 'data' => null];
        }

        $idBinary = UuidBinary::toBinary($notificationUuid);

        $outcome = DB::transaction(function () use ($idBinary): string {
            $locked = DB::table('outbound_notifications')->where('id', $idBinary)->lockForUpdate()->first();

            if ($locked === null) {
                return 'NOT_FOUND';
            }

            $statusCode = OutboundNotificationStatuses::code((int) $locked->status_id);

            if ($statusCode === 'PENDING') {
                // Already retryable as-is - nothing to reset.
                return 'READY';
            }

            // RECONCILIATION_REQUIRED is deliberately NOT retryable here -
            // the provider round-trip that produced it never confirmed
            // whether Meta already sent a real message, and Meta has no
            // idempotency key to make a blind resend safe. Recovering
            // from this state is an explicit, out-of-band Admin decision
            // (see docs/handoff/technician-whatsapp-v1.md), never a
            // one-click retry through this endpoint.
            if ($statusCode === 'RECONCILIATION_REQUIRED') {
                return 'NEEDS_REVIEW';
            }

            if ($statusCode !== 'FAILED') {
                return 'NOT_RETRYABLE';
            }

            DB::table('outbound_notifications')->where('id', $locked->id)->update([
                'status_id' => OutboundNotificationStatuses::id('PENDING'),
                'failed_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'next_attempt_at' => null,
                'updated_at' => now()->format('Y-m-d H:i:s.u'),
            ]);

            return 'READY';
        });

        if ($outcome === 'NOT_FOUND') {
            return $this->notFound('Notification not found.');
        }

        if ($outcome === 'NEEDS_REVIEW') {
            return $this->conflict('This notification\'s previous delivery attempt could not be confirmed and requires manual review before any retry - see the handoff documentation for the recovery process.');
        }

        if ($outcome === 'NOT_RETRYABLE') {
            return $this->conflict('This notification has already been sent (or was correctly skipped) and cannot be retried.');
        }

        try {
            $this->sendNotification->handle($notificationUuid);
        } catch (Throwable $e) {
            report($e);
        }

        $fresh = DB::table('outbound_notifications')->where('id', $idBinary)->first(['status_id']);

        return $this->ok(200, 'Notification retry submitted.', [
            'notification' => [
                'uuid' => $notificationUuid,
                'status' => OutboundNotificationStatuses::code((int) $fresh->status_id),
            ],
        ]);
    }
}
