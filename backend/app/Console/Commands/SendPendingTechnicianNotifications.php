<?php

namespace App\Console\Commands;

use App\Actions\Notifications\SendEmailNotificationAction;
use App\Actions\Notifications\SendTechnicianNotificationAction;
use App\Support\Notifications\OutboundNotificationStatuses;
use App\Support\Uuid\UuidBinary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B21 - the recovery path for every `outbound_notifications`
 * obligation whose best-effort, post-assignment WhatsApp attempt could not
 * be resolved (Meta temporarily unavailable, request timeout, PHP process
 * crash between the assignment commit and the notification attempt, or a
 * transient/ambiguous provider error). Mirrors App\Console\Commands\
 * ExecutePendingBookingRefunds exactly - same reasoning applies for why
 * this can exist at all without a queue/outbox: App\Actions\Notifications\
 * SendTechnicianNotificationAction is fully idempotent (PENDING-only
 * guard + a stable, persisted idempotency key + the stale-assignment
 * SKIPPED guard), so running this command is always safe - on a healthy
 * system every obligation is already SUBMITTED/FAILED/SKIPPED and this
 * finds nothing to do.
 *
 * BLUE V1 Phase B22 - the SAME command now also recovers EMAIL-channel
 * obligations (App\Actions\Notifications\SendEmailNotificationAction),
 * routed by each row's own `channel` column - never a second, parallel
 * `notifications:send-pending-email` command, per the BLUE V1 email spec's
 * "extend the existing generic retry endpoint" instruction.
 *
 * Only selects rows whose `next_attempt_at` backoff window has elapsed
 * (or was never set - i.e. this is the very first retry) - see
 * SendTechnicianNotificationAction's bounded linear-backoff docblock.
 */
class SendPendingTechnicianNotifications extends Command
{
    protected $signature = 'notifications:send-pending {--limit=200 : Maximum notification obligations to process in one run}';

    protected $description = 'Retry WhatsApp/email delivery for PENDING outbound_notifications obligations whose backoff window has elapsed.';

    public function handle(SendTechnicianNotificationAction $whatsappAction, SendEmailNotificationAction $emailAction): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $now = now()->format('Y-m-d H:i:s.u');

        $candidates = DB::table('outbound_notifications')
            ->where('status_id', OutboundNotificationStatuses::id('PENDING'))
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get(['id', 'channel']);

        if ($candidates->isEmpty()) {
            $this->info('No PENDING notifications are due for a delivery attempt.');

            return self::SUCCESS;
        }

        foreach ($candidates as $candidate) {
            $uuid = UuidBinary::toString($candidate->id);

            match ($candidate->channel) {
                'EMAIL' => $emailAction->handle($uuid),
                default => $whatsappAction->handle($uuid),
            };

            $statusCode = OutboundNotificationStatuses::code(
                (int) DB::table('outbound_notifications')->where('id', $candidate->id)->value('status_id')
            );

            $this->line("Notification {$uuid} ({$candidate->channel}): {$statusCode}.");
        }

        $this->info('Done processing '.$candidates->count().' pending notification(s).');

        return self::SUCCESS;
    }
}
