<?php

namespace App\Support\Auth;

/**
 * Blocks a customer with a PENDING account-deletion request
 * (customer_account_deletion_requests.completed_at IS NULL) from creating
 * a NEW Booking/Payment/Contract obligation that would let them postpone
 * their own deletion indefinitely (see App\Actions\Auth\
 * DeleteAccountAction's docblock, invariant E). Deliberately narrow: it
 * only guards the small set of Actions that INSERT a new blocking
 * obligation (App\Actions\Payment\CreatePaymentAttemptAction,
 * App\Actions\Contract\RequestContractAction, App\Actions\Contract\
 * CreateContractBookingAction) - resolving an obligation that already
 * exists (cancelling a Booking, paying an already-open Payment, reading
 * Contract/Booking state, editing a Property, Cart preparation with no
 * Payment yet) is never blocked by this guard, since none of those
 * create a NEW obligation.
 *
 * Every caller must invoke isPending() only while already holding the
 * `users` row FOR UPDATE for this same customer, matching the lock order
 * DeleteAccountAction itself uses (`users` first) - this is what actually
 * closes the race between "customer requests deletion" and "customer
 * creates a new obligation" rather than merely reducing its window: two
 * transactions that both take that single shared lock as their first
 * action can never interleave, so whichever commits first is the one the
 * other observes.
 */
final class PendingAccountDeletionGuard
{
    public const REJECTION_MESSAGE = 'Your account is pending deletion and cannot create new bookings, contracts, or payments.';

    public function __construct(private readonly AccountDeletionRequestStore $store = new AccountDeletionRequestStore) {}

    public function isPending(string $userIdBinary): bool
    {
        return $this->store->findPending($userIdBinary) !== null;
    }
}
