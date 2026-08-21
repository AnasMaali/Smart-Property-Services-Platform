<?php

namespace App\Support\Auth;

use App\Support\Booking\BookingStatuses;
use App\Support\Contract\ContractStatuses;
use App\Support\Payment\PaymentStatuses;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one authoritative source of "can this customer's account be deleted
 * right now" - used identically by App\Actions\Auth\DeleteAccountAction
 * (the HTTP-facing initiation/retry path) and App\Console\Commands\
 * ProcessPendingAccountDeletions (the scheduled recovery path), so a
 * PENDING deletion request completes under exactly the same rule that
 * decided it had to wait in the first place. Every definition here is
 * unchanged from the original Phase 12 DeleteAccountAction - only the
 * location moved, so it can be reused without being duplicated.
 */
final class AccountDeletionEligibilityChecker
{
    private const ADMIN_ROLE_CODES = ['ADMIN', 'SUPER_ADMIN'];

    /**
     * `users` is never physically deleted, and a `users` row is not
     * exclusively a Customer identity - AuthenticateAdmin itself documents
     * that the same row can independently hold CUSTOMER and an ADMIN/
     * SUPER_ADMIN role (Admin login uses the same phone_number/email/
     * password_hash columns the erasure lifecycle would otherwise
     * tombstone). There is no way to scope that tombstoning to "just the
     * Customer half" of the row, so any account currently holding an
     * active Admin role is never eligible for self-service deletion, and
     * - critically - is never allowed to enter the PENDING queue either
     * (see DeleteAccountAction): we must never queue a process that could
     * later tombstone an active company Admin identity.
     */
    public function hasActiveAdminRole(string $userIdBinary): bool
    {
        return DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $userIdBinary)
            ->whereIn('roles.code', self::ADMIN_ROLE_CODES)
            ->where('roles.is_active', 1)
            ->exists();
    }

    /**
     * @param  Collection<int, string>  $cartIds  Raw binary(16) cart ids.
     */
    public function hasNonTerminalBooking(Collection $cartIds): bool
    {
        if ($cartIds->isEmpty()) {
            return false;
        }

        $terminalStatusIds = [BookingStatuses::id('COMPLETED'), BookingStatuses::id('CANCELLED')];

        return DB::table('bookings')
            ->whereIn('cart_id', $cartIds)
            ->whereNotIn('status_id', $terminalStatusIds)
            ->exists();
    }

    /**
     * An open (not yet finalized) attempt, an unresolved
     * (requires_reconciliation) attempt, or a SUCCESSFUL attempt with no
     * Booking yet (the exact "waiting for bookings:convert-successful-
     * payments recovery" state - see
     * App\Console\Commands\ConvertSuccessfulPaymentsToBookings) all mean
     * this customer's financial state is not yet settled.
     *
     * @param  Collection<int, string>  $cartIds
     */
    public function hasOpenOrUnresolvedPayment(Collection $cartIds): bool
    {
        if ($cartIds->isEmpty()) {
            return false;
        }

        $successfulStatusId = PaymentStatuses::id('SUCCESSFUL');

        return DB::table('payment_attempts')
            ->leftJoin('bookings', 'bookings.payment_attempt_id', '=', 'payment_attempts.id')
            ->whereIn('payment_attempts.cart_id', $cartIds)
            ->where(function ($query) use ($successfulStatusId) {
                $query->whereNull('payment_attempts.finalized_at')
                    ->orWhere('payment_attempts.requires_reconciliation', 1)
                    ->orWhere(function ($query) use ($successfulStatusId) {
                        $query->where('payment_attempts.status_id', $successfulStatusId)
                            ->whereNull('bookings.id');
                    });
            })
            ->exists();
    }

    public function hasNonTerminalContract(string $userIdBinary): bool
    {
        $terminalStatusIds = [ContractStatuses::id('EXPIRED'), ContractStatuses::id('CANCELLED')];

        return DB::table('service_contracts')
            ->where('customer_user_id', $userIdBinary)
            ->whereNotIn('status_id', $terminalStatusIds)
            ->exists();
    }

    /**
     * True if any Booking/Payment/Contract obligation currently blocks
     * final erasure for this customer. Does not consider the Admin-role
     * guard - callers that must never queue an Admin identity check
     * hasActiveAdminRole() separately, before this.
     *
     * @param  Collection<int, string>  $cartIds
     */
    public function hasBlockingObligation(string $userIdBinary, Collection $cartIds): bool
    {
        return $this->hasNonTerminalBooking($cartIds)
            || $this->hasOpenOrUnresolvedPayment($cartIds)
            || $this->hasNonTerminalContract($userIdBinary);
    }
}
