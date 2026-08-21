<?php

namespace App\Console\Commands;

use App\Actions\Auth\AccountDeletionExecutor;
use App\Models\User;
use App\Support\Auth\AccountDeletionEligibilityChecker;
use App\Support\Auth\AccountDeletionRequestStore;
use App\Support\Uuid\UuidBinary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * BLUE V1 Phase 13's deferred-deletion completion path: finds every
 * customer_account_deletion_requests row still PENDING (completed_at IS
 * NULL) and, for each one, re-runs the exact same
 * App\Support\Auth\AccountDeletionEligibilityChecker logic
 * App\Actions\Auth\DeleteAccountAction itself uses, completing the
 * deletion via the same App\Actions\Auth\AccountDeletionExecutor the
 * moment the blocking Booking/Contract/Payment obligation has reached a
 * terminal state. Neither eligibility logic nor erasure logic is
 * duplicated - both are shared with the HTTP-facing initiation path.
 *
 * Lock order per candidate: USER first, then the deletion REQUEST row
 * itself (guards against a concurrent invocation of this same command
 * double-processing it), then CARTS, then (inside the executor)
 * PROPERTIES. This differs in the relative order of REQUEST vs CARTS from
 * DeleteAccountAction's own USER -> CARTS -> (request table) chain, but
 * that ordering difference cannot deadlock: `users` is always the first
 * lock acquired by both code paths for a given customer, so whichever
 * transaction acquires it first fully serializes the other - the other
 * transaction is blocked before it can hold any other lock at all, so no
 * lock-wait cycle across two distinct resources (carts vs. the request
 * row) is ever possible.
 *
 * Never makes a Stripe or Twilio call. Never duplicates erasure logic. A
 * failure processing one customer's request (caught per-candidate) never
 * aborts or corrupts any other candidate's request - each candidate runs
 * in its own transaction.
 */
class ProcessPendingAccountDeletions extends Command
{
    protected $signature = 'accounts:process-pending-deletions {--limit=200 : Maximum pending deletion requests to process in one run}';

    protected $description = 'Complete deferred customer account deletions whose blocking Booking/Contract/Payment obligation has become terminal.';

    public function handle(
        AccountDeletionEligibilityChecker $checker,
        AccountDeletionExecutor $executor,
        AccountDeletionRequestStore $requestStore,
    ): int {
        $limit = max(1, (int) $this->option('limit'));

        // Candidate discovery first, unlocked - mirrors
        // bookings:convert-successful-payments' own "find candidates, then
        // process each independently" shape.
        $candidates = DB::table('customer_account_deletion_requests')
            ->whereNull('completed_at')
            ->orderBy('requested_at')
            ->limit($limit)
            ->get(['id', 'user_id']);

        if ($candidates->isEmpty()) {
            $this->info('No pending account deletion requests to process.');

            return self::SUCCESS;
        }

        $completed = 0;
        $stillPending = 0;
        $skipped = 0;

        foreach ($candidates as $candidate) {
            try {
                $outcome = DB::transaction(fn () => $this->processOne($candidate, $checker, $executor, $requestStore));
            } catch (Throwable $e) {
                $skipped++;
                $this->error('Skipped deletion request '.UuidBinary::toString($candidate->id).': '.$e->getMessage());

                continue;
            }

            match ($outcome) {
                'completed' => $completed++,
                'pending' => $stillPending++,
                default => $skipped++,
            };
        }

        $this->info("Done. Completed: {$completed}. Still pending: {$stillPending}. Skipped: {$skipped}.");

        return self::SUCCESS;
    }

    private function processOne(
        object $candidate,
        AccountDeletionEligibilityChecker $checker,
        AccountDeletionExecutor $executor,
        AccountDeletionRequestStore $requestStore,
    ): string {
        $userIdBinary = $candidate->user_id;

        // USER first.
        $user = User::where('id', $userIdBinary)->lockForUpdate()->first();

        $now = now();

        if ($user === null) {
            // Unreachable under normal operation (users is never
            // physically deleted, and fk_customer_account_deletion_
            // requests_user is RESTRICT), but if it ever happened there is
            // nothing left to process - close out the request rather than
            // retry it forever.
            $requestStore->markCompleted($userIdBinary, $now);

            return 'completed';
        }

        // Then the deletion REQUEST row itself.
        $request = DB::table('customer_account_deletion_requests')
            ->where('id', $candidate->id)
            ->lockForUpdate()
            ->first();

        if ($request === null || $request->completed_at !== null) {
            // Already completed by a concurrent run (or the customer's own
            // retried DELETE call) - nothing left to do.
            return 'completed';
        }

        if ($user->deleted_at !== null) {
            // Already fully erased - close out the request idempotently.
            $requestStore->markCompleted($userIdBinary, $now);

            return 'completed';
        }

        if ($checker->hasActiveAdminRole($userIdBinary)) {
            // An Admin/Super Admin role was granted to this identity after
            // the deletion request was queued - never auto-tombstone an
            // active company Admin. Leave PENDING indefinitely rather than
            // silently discard the request or force it through.
            $requestStore->touchLastChecked($candidate->id, $now);

            return 'pending';
        }

        // Then CARTS.
        $cartIds = DB::table('carts')
            ->where('customer_user_id', $userIdBinary)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        if ($checker->hasBlockingObligation($userIdBinary, $cartIds)) {
            $requestStore->touchLastChecked($candidate->id, $now);

            return 'pending';
        }

        // Then (inside the executor) PROPERTIES.
        $executor->execute($user, $cartIds);
        $requestStore->markCompleted($userIdBinary, $now);

        return 'completed';
    }
}
