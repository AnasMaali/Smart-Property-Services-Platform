<?php

namespace App\Console\Commands;

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
 * Only selects rows whose `next_attempt_at` backoff window has elapsed
 * (or was never set - i.e. this is the very first retry) - see
 * SendTechnicianNotificationAction's bounded linear-backoff docblock.
 */
class SendPendingTechnicianNotifications extends Command
{
    protected $signature = 'notifications:send-pending {--limit=200 : Maximum notification obligations to process in one run}';

    protected $description = 'Retry WhatsApp delivery for PENDING outbound_notifications obligations whose backoff window has elapsed.';

    public function handle(SendTechnicianNotificationAction $action): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $now = now()->format('Y-m-d H:i:s.u');

        $candidateIds = DB::table('outbound_notifications')
            ->where('status_id', OutboundNotificationStatuses::id('PENDING'))
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id');

        if ($candidateIds->isEmpty()) {
            $this->info('No PENDING technician notifications are due for a delivery attempt.');

            return self::SUCCESS;
        }

        foreach ($candidateIds as $idBinary) {
            $uuid = UuidBinary::toString($idBinary);
            $action->handle($uuid);

            $statusCode = OutboundNotificationStatuses::code(
                (int) DB::table('outbound_notifications')->where('id', $idBinary)->value('status_id')
            );

            $this->line("Notification {$uuid}: {$statusCode}.");
        }

        $this->info('Done processing '.$candidateIds->count().' pending notification(s).');

        return self::SUCCESS;
    }
}
