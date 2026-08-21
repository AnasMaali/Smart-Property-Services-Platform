<?php

namespace App\Support\Auth;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one place `customer_account_deletion_requests` is read or written -
 * used by App\Actions\Auth\DeleteAccountAction (create-or-resume on
 * initiation/retry, mark-completed on immediate deletion),
 * App\Actions\Auth\GetAccountDeletionStatusAction (read-only status), and
 * App\Console\Commands\ProcessPendingAccountDeletions (candidate
 * discovery, mark-completed, touch last_checked_at). Never stores
 * current_password, a password hash copy, phone number, email, a raw OTP,
 * provider credentials, or arbitrary request JSON - `user_id` is the only
 * personal reference this table ever holds.
 */
final class AccountDeletionRequestStore
{
    /**
     * The still-open (completed_at IS NULL) request for this user, if any.
     * Callers that are about to mutate it must already hold `users` FOR
     * UPDATE for this same user (see class docblocks on DeleteAccountAction
     * / ProcessPendingAccountDeletions for why that ordering is safe) and
     * should pass $forUpdate: true.
     */
    public function findPending(string $userIdBinary, bool $forUpdate = false): ?object
    {
        $query = DB::table('customer_account_deletion_requests')
            ->where('user_id', $userIdBinary)
            ->whereNull('completed_at');

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Returns the existing PENDING request unchanged (requested_at stays
     * stable across retries) or creates a new one with requested_at =
     * $now. Caller must already hold `users` FOR UPDATE for this user -
     * the UNIQUE(user_id) constraint is the hard backstop, but the lock
     * ordering is what makes this race-free rather than merely
     * constraint-safe.
     */
    public function createOrResumePending(string $userIdBinary, Carbon $now): object
    {
        $existing = $this->findPending($userIdBinary, forUpdate: true);

        if ($existing !== null) {
            return $existing;
        }

        $idBinary = UuidBinary::toBinary(UuidBinary::generate());
        $timestamp = $now->format('Y-m-d H:i:s.u');

        DB::table('customer_account_deletion_requests')->insert([
            'id' => $idBinary,
            'user_id' => $userIdBinary,
            'requested_at' => $timestamp,
            'last_checked_at' => null,
            'completed_at' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $this->findPending($userIdBinary, forUpdate: true);
    }

    /**
     * Idempotent close-out: a no-op if no PENDING request exists for this
     * user (the common case - most deletions never went through the
     * PENDING path at all).
     */
    public function markCompleted(string $userIdBinary, Carbon $now): void
    {
        DB::table('customer_account_deletion_requests')
            ->where('user_id', $userIdBinary)
            ->whereNull('completed_at')
            ->update([
                'completed_at' => $now->format('Y-m-d H:i:s.u'),
                'updated_at' => $now->format('Y-m-d H:i:s.u'),
            ]);
    }

    public function touchLastChecked(string $requestIdBinary, Carbon $now): void
    {
        DB::table('customer_account_deletion_requests')
            ->where('id', $requestIdBinary)
            ->update([
                'last_checked_at' => $now->format('Y-m-d H:i:s.u'),
                'updated_at' => $now->format('Y-m-d H:i:s.u'),
            ]);
    }
}
