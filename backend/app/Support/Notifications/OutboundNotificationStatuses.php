<?php

namespace App\Support\Notifications;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resolves outbound_notification_statuses.id by code instead of
 * hardcoding numeric lookup ids anywhere - mirrors App\Support\Booking\
 * BookingRefundStatuses exactly. Only the four seeded codes ever exist -
 * see database/phase20_technician_notifications_migration.sql:
 *
 * - PENDING: not yet resolved - safe and required to (re)send.
 * - SUBMITTED: the provider accepted the message. NOT proof of delivery
 *   or that the Technician read it - see NotificationDispatchOutcome's
 *   docblock. This V1 phase implements no delivery/read webhooks, so no
 *   DELIVERED/READ status exists at all - never claim either.
 * - FAILED: a definitive provider rejection, or a transient outcome that
 *   exhausted config('technician_notifications.max_attempts') - terminal,
 *   not retried automatically.
 * - SKIPPED: the obligation was correctly never sent because the business
 *   state it described (an active Technician assignment) had already
 *   changed by the time it was about to be sent (App\Actions\
 *   Notifications\SendTechnicianNotificationAction's stale-assignment
 *   guard) - never FAILED, since nothing went wrong.
 */
final class OutboundNotificationStatuses
{
    public static function id(string $code): int
    {
        $id = DB::table('outbound_notification_statuses')->where('code', $code)->where('is_active', 1)->value('id');

        if ($id === null) {
            throw new RuntimeException("Missing required reference row: outbound_notification_statuses.code = {$code}");
        }

        return (int) $id;
    }

    public static function code(int $id): string
    {
        $code = DB::table('outbound_notification_statuses')->where('id', $id)->value('code');

        if ($code === null) {
            throw new RuntimeException("Missing required reference row: outbound_notification_statuses.id = {$id}");
        }

        return (string) $code;
    }
}
