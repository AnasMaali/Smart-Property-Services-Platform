<?php

namespace App\Console\Commands;

use App\Actions\Booking\CreateBookingFromSuccessfulPaymentAction;
use App\Support\Booking\BookingConversionOutcome;
use App\Support\Payment\PaymentStatuses;
use App\Support\Uuid\UuidBinary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The smallest safe operational recovery path for BLUE V1 Phase 7A: finds
 * every payment_attempts row that is SUCCESSFUL, not reconciliation-
 * flagged, and has no Booking yet, and retries
 * CreateBookingFromSuccessfulPaymentAction::handle() for each one.
 *
 * Why this can exist at all without a queue/outbox: the conversion Action
 * is fully idempotent (application-level existence check + the DB's own
 * UNIQUE(payment_attempt_id)/UNIQUE(cart_id) backstop on `bookings`), so
 * running this command is always safe - on a healthy system it finds
 * nothing and does nothing; after a transient Booking-conversion failure
 * (the payment itself is already safely SUCCESSFUL, per Phase 6A/7A's
 * "financial truth never rolls back" contract) it is the mechanism that
 * makes the missing Booking visible and recoverable without waiting for
 * another provider webhook delivery that may never arrive.
 *
 * The query itself - SUCCESSFUL, requires_reconciliation = 0, no matching
 * `bookings` row - is exactly how an operator (or a future scheduled job)
 * finds "successful payments with no bookings" today; no separate
 * dashboard/table is introduced for it.
 */
class ConvertSuccessfulPaymentsToBookings extends Command
{
    protected $signature = 'bookings:convert-successful-payments {--limit=200 : Maximum payment attempts to process in one run}';

    protected $description = 'Retry Booking conversion for SUCCESSFUL, non-reconciliation-flagged payment attempts that have no Booking yet.';

    public function handle(CreateBookingFromSuccessfulPaymentAction $action): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $candidateIds = DB::table('payment_attempts')
            ->leftJoin('bookings', 'bookings.payment_attempt_id', '=', 'payment_attempts.id')
            ->where('payment_attempts.status_id', PaymentStatuses::id('SUCCESSFUL'))
            ->where('payment_attempts.requires_reconciliation', 0)
            ->whereNull('bookings.id')
            ->orderBy('payment_attempts.successful_at')
            ->limit($limit)
            ->pluck('payment_attempts.id');

        if ($candidateIds->isEmpty()) {
            $this->info('No SUCCESSFUL payment attempts are waiting for Booking conversion.');

            return self::SUCCESS;
        }

        $created = 0;
        $skipped = 0;

        foreach ($candidateIds as $idBinary) {
            $uuid = UuidBinary::toString($idBinary);
            $result = $action->handle($uuid);

            if ($result->outcome === BookingConversionOutcome::CREATED) {
                $created++;
                $this->line("Created Booking {$result->bookingUuid} for payment attempt {$uuid}.");

                continue;
            }

            $skipped++;
            $this->line("Skipped payment attempt {$uuid}: {$result->outcome->value} ({$result->reason}).");
        }

        $this->info("Done. Created: {$created}. Skipped: {$skipped}.");

        return self::SUCCESS;
    }
}
