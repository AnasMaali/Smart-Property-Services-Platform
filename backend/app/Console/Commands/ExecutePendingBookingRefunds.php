<?php

namespace App\Console\Commands;

use App\Actions\Payment\ExecuteBookingRefundAction;
use App\Support\Booking\BookingRefundStatuses;
use App\Support\Uuid\UuidBinary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B20 - the recovery path for every `booking_refunds`
 * obligation that App\Actions\Booking\CancelBookingAction's best-effort,
 * post-commit Stripe attempt could not resolve (Stripe temporarily
 * unavailable, request timeout, PHP process crash between the
 * cancellation commit and the refund attempt, or a transient/ambiguous
 * Stripe error). Mirrors App\Console\Commands\
 * ConvertSuccessfulPaymentsToBookings exactly - same reasoning applies for
 * why this can exist at all without a queue/outbox: App\Actions\Payment\
 * ExecuteBookingRefundAction is fully idempotent (PENDING-only guard +
 * a stable, persisted Stripe idempotency key), so running this command is
 * always safe - on a healthy system every obligation is already
 * SUCCEEDED/FAILED and this finds nothing to do.
 */
class ExecutePendingBookingRefunds extends Command
{
    protected $signature = 'bookings:execute-pending-refunds {--limit=200 : Maximum refund obligations to process in one run}';

    protected $description = 'Retry Stripe execution for PENDING booking_refunds obligations.';

    public function handle(ExecuteBookingRefundAction $action): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $candidateIds = DB::table('booking_refunds')
            ->where('status_id', BookingRefundStatuses::id('PENDING'))
            ->orderBy('requested_at')
            ->limit($limit)
            ->pluck('id');

        if ($candidateIds->isEmpty()) {
            $this->info('No PENDING booking refunds are waiting for Stripe execution.');

            return self::SUCCESS;
        }

        foreach ($candidateIds as $idBinary) {
            $uuid = UuidBinary::toString($idBinary);
            $action->handle($uuid);

            $statusCode = BookingRefundStatuses::code(
                (int) DB::table('booking_refunds')->where('id', $idBinary)->value('status_id')
            );

            $this->line("Refund {$uuid}: {$statusCode}.");
        }

        $this->info('Done processing '.$candidateIds->count().' pending refund(s).');

        return self::SUCCESS;
    }
}
