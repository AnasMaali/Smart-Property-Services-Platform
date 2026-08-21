<?php

namespace App\Actions\Auth;

use App\Actions\Auth\Concerns\RevokesAuthSessions;
use App\Models\User;
use App\Support\Booking\BookingStatuses;
use App\Support\Contract\ContractStatuses;
use App\Support\Payment\PaymentStatuses;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Genuine customer account deletion, designed to support Apple App Store
 * guideline 5.1.1(v) ("Account Deletion") - never a status flip. Final
 * compliance also depends on the Flutter client actually surfacing this
 * endpoint in-app, and on the residual retention gaps documented below and
 * in docs/api-contracts/authentication-v1.md being resolved or accepted by
 * BLUE's own legal/product review; this Action alone does not constitute a
 * compliance guarantee. A DEACTIVATED account and a
 * deleted account are NOT the same thing: this Action performs the full
 * erasure/anonymization lifecycle (live PII gone, sessions gone, OTPs
 * gone, CUSTOMER authorization gone) every time it succeeds; nothing else
 * in this codebase currently sets DEACTIVATED at all.
 *
 * `users` itself is never physically DELETEd - `service_contracts.
 * accepted_by_user_id`, `booking_status_history.changed_by_user_id`, and
 * every other RESTRICT foreign key back to `users`/`customer_profiles`
 * (see database/blue_v1_schema.sql) make that impossible the moment a
 * customer has ever accepted a Contract, added anything to a cart, or had
 * a Booking/Contract status changed - i.e. for virtually every real
 * customer. This Action instead tombstones the row in place: live PII is
 * overwritten with non-personal placeholder values, `phone_number`/
 * `email` are replaced so the originals are immediately free for a new
 * signup, `account_status_id` moves to DEACTIVATED (already required
 * ACTIVE by every login/session path - see AuthenticateCustomer,
 * LoginAction), the CUSTOMER role membership is removed, and every
 * `auth_sessions` row is revoked. `deleted_at` (Phase 12 migration) is the
 * one unambiguous, permanent marker that this was real deletion, not
 * some future "temporarily deactivate my account" feature also landing on
 * DEACTIVATED.
 *
 * Eligibility: an account currently holding an active ADMIN/SUPER_ADMIN
 * role (see hasActiveAdminRole()) is refused outright - a `users` row can
 * legitimately be both a Customer and a company Admin at once, and this
 * Action's PII tombstoning is not scoped to "just the Customer half" of a
 * shared identity. An account with a non-terminal Booking, a non-terminal
 * Contract, or an open/unresolved Payment Attempt cannot yet be deleted -
 * see hasNonTerminalBooking()/hasNonTerminalContract()/
 * hasOpenOrUnresolvedPayment() for the exact terminal-state definitions,
 * read from the real status tables rather than assumed. This is a known,
 * disclosed compliance gap relative to Apple's literal "must be able to
 * initiate deletion" wording: a blocked customer has no in-app deferred-
 * deletion path today, only a same-endpoint retry once their obligation
 * resolves. Chosen deliberately over a PENDING_DELETION-style deferred-
 * erasure mechanism, which would be the correct long-term fix but is a
 * genuine architecture change (new status/column, a reconciliation
 * scheduled command, re-entrant eligibility semantics) - recommended as a
 * follow-up before final App Store submission, not implemented here.
 * Financial/audit
 * history that must legally or operationally survive (SUCCESSFUL
 * payment_attempts, COMPLETED/CANCELLED bookings, CANCELLED/EXPIRED
 * Contracts and their billing rows, booking/contract status history) is
 * never touched - only live, customer-controllable PII is erased.
 *
 * Deliberate exception - payment_attempts.checkout_snapshot: this frozen
 * JSON blob (see App\Support\Payment\CheckoutSnapshotBuilder) embeds the
 * exact service address and visit_contact_phone captured at the moment of
 * payment, paired with a SHA-256 checkout_snapshot_hash
 * (App\Support\Payment\CanonicalJson). This is not a merely-decorative
 * immutability guarantee: ProcessPaymentWebhookAction and
 * CreateBookingFromSuccessfulPaymentAction both actively recompute and
 * hash_equals()-verify this hash against the live snapshot content on
 * every webhook delivery, including provider retries/redeliveries of an
 * already-processed payment. Mutating the snapshot after deletion - even
 * while also recomputing a new hash - would silently change what that
 * hash validates against for any future retried webhook on this payment,
 * which is a currently-exercised code path, not a hypothetical one.
 * Redacting only the personal fields (visit_contact_phone, the exact
 * address) while leaving the financial core (pricing, service, currency,
 * quantity) intact would require splitting checkout_snapshot into a
 * separately-hashed financial subset and a redactable fulfillment-PII
 * subset - a real schema/hashing-scheme change, not attempted here.
 * Retention today is driven primarily by this technical hash-integrity
 * constraint, not by any BLUE-documented legal retention policy for
 * visit_contact_phone specifically - no such policy exists in this
 * codebase. This Action is designed to support Apple's account-deletion
 * requirement for every field it can safely erase; the personal fields
 * still embedded in this one frozen structure are a known, disclosed
 * residual gap (see docs/api-contracts/authentication-v1.md "Delete
 * Account") that should be resolved by a dedicated snapshot redesign, not
 * assumed compliant merely because the field is hash-protected. It is
 * never exposed through any customer-facing read path after deletion,
 * since the deleted account has no working credentials, role, or session
 * left to query it with.
 *
 * Lock order: users -> carts (all, ordered by id) -> customer_properties
 * (all, ordered by id) - matching the project-wide "USER first" lock
 * convention (see CreatePaymentAttemptAction, AddCartItemAction).
 * service_contracts/bookings/payment_attempts are read, never locked,
 * for the eligibility check - locking rows that do not yet exist cannot
 * prevent a concurrent insert either way, and every high-traffic path
 * that inserts a new cart/payment obligation for this customer
 * (AddCartItemAction, CreatePaymentAttemptAction) already locks the
 * `users` row first, so it naturally serializes behind this transaction.
 * No external network/provider call is ever made here.
 */
class DeleteAccountAction
{
    use RevokesAuthSessions;

    private const GENERIC_INVALID_MESSAGE = 'Your session is no longer valid. Please log in again.';

    private const BLOCKED_MESSAGE = 'The account cannot be deleted while active bookings, contracts, or payments exist.';

    private const ADMIN_IDENTITY_MESSAGE = 'This account cannot be deleted through self-service while it retains administrative access. Please contact support.';

    private const ADMIN_ROLE_CODES = ['ADMIN', 'SUPER_ADMIN'];

    /**
     * @param  array{current_password: string}  $data
     * @return array{success: bool, status: int, message: string, data: null}
     */
    public function handle(string $userUuid, array $data): array
    {
        return DB::transaction(function () use ($userUuid, $data) {
            $userIdBinary = UuidBinary::toBinary($userUuid);

            $user = User::where('id', $userIdBinary)->lockForUpdate()->first();

            $activeAccountStatusId = $this->lookupId('user_account_statuses', 'ACTIVE');

            if ($user === null || $user->account_status_id !== $activeAccountStatusId) {
                return $this->failure(422, self::GENERIC_INVALID_MESSAGE);
            }

            if (! Hash::check($data['current_password'], $user->password_hash)) {
                return $this->failure(422, 'The current password you entered is incorrect.');
            }

            if ($this->hasActiveAdminRole($userIdBinary)) {
                return $this->failure(409, self::ADMIN_IDENTITY_MESSAGE);
            }

            $cartIds = DB::table('carts')
                ->where('customer_user_id', $userIdBinary)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            if ($this->hasNonTerminalBooking($cartIds)) {
                return $this->failure(409, self::BLOCKED_MESSAGE);
            }

            if ($this->hasOpenOrUnresolvedPayment($cartIds)) {
                return $this->failure(409, self::BLOCKED_MESSAGE);
            }

            if ($this->hasNonTerminalContract($userIdBinary)) {
                return $this->failure(409, self::BLOCKED_MESSAGE);
            }

            $now = now();
            $timestamp = $now->format('Y-m-d H:i:s.u');

            $this->deleteOrStripCarts($cartIds);
            $this->deleteOrAnonymizeProperties($userIdBinary, $timestamp);
            $this->invalidatePendingOtps($userIdBinary, $timestamp);

            DB::table('customer_service_interests')->where('customer_user_id', $userIdBinary)->delete();

            DB::table('customer_profiles')->where('user_id', $userIdBinary)->update([
                'stripe_customer_id' => null,
                'updated_at' => $timestamp,
            ]);

            DB::table('user_profiles')->where('user_id', $userIdBinary)->update([
                'full_name' => 'Deleted User',
                'updated_at' => $timestamp,
            ]);

            DB::table('user_roles')
                ->where('user_id', $userIdBinary)
                ->where('role_id', $this->lookupId('roles', 'CUSTOMER'))
                ->delete();

            $user->email = $this->tombstoneEmail();
            $user->phone_number = $this->tombstonePhoneNumber();
            $user->password_hash = Hash::make(Str::random(40));
            $user->account_status_id = $this->lookupId('user_account_statuses', 'DEACTIVATED');
            $user->deleted_at = $now;
            $user->save();

            // No $exceptSessionIdBinary: every session, including the one
            // making this request, is revoked - the account is gone.
            $this->revokeOtherSessions($userIdBinary, $now);

            return $this->success();
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>  $cartIds  Raw binary(16) cart ids.
     */
    private function hasNonTerminalBooking($cartIds): bool
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
     * @param  \Illuminate\Support\Collection<int, string>  $cartIds
     */
    private function hasOpenOrUnresolvedPayment($cartIds): bool
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

    /**
     * `users` is never physically deleted (see class docblock), and a
     * `users` row is not exclusively a Customer identity - AuthenticateAdmin
     * itself documents that the same row can independently hold CUSTOMER and
     * an ADMIN/SUPER_ADMIN role (Admin login uses the same phone_number/
     * email/password_hash columns this Action would otherwise tombstone).
     * There is no way to scope the phone/email/password tombstoning or the
     * account_status_id/session-revocation below to "just the Customer
     * half" of the row - both identities share the same login credentials
     * and the same account_status_id gate (AuthenticateAdmin also requires
     * ACTIVE). Deleting the Customer identity would therefore silently lock
     * a legitimate company Admin/Super Admin out of their own account, so
     * this is refused outright rather than attempted.
     */
    private function hasActiveAdminRole(string $userIdBinary): bool
    {
        return DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $userIdBinary)
            ->whereIn('roles.code', self::ADMIN_ROLE_CODES)
            ->where('roles.is_active', 1)
            ->exists();
    }

    private function hasNonTerminalContract(string $userIdBinary): bool
    {
        $terminalStatusIds = [ContractStatuses::id('EXPIRED'), ContractStatuses::id('CANCELLED')];

        return DB::table('service_contracts')
            ->where('customer_user_id', $userIdBinary)
            ->whereNotIn('status_id', $terminalStatusIds)
            ->exists();
    }

    /**
     * A cart with no Booking and no Payment Attempt ever made against it
     * (eligibility above already proved neither is non-terminal/open, but
     * a TERMINAL one - a completed Booking, a failed/cancelled/already-
     * converted payment - may still exist and permanently RESTRICTs the
     * cart row itself from deletion) is pure transient shopping state and
     * is fully deleted - cart_items, its option selections,
     * cart_locations, and appointment_holds all cascade from `carts`
     * (see database/blue_v1_schema.sql). A cart that must stay only ever
     * has its cart_locations row (the one piece of real PII on a cart -
     * visit_contact_phone, address fields) stripped; cart_items and any
     * historical appointment_holds are left alone as the order-content
     * half of a retained financial record, not personal contact data.
     *
     * @param  \Illuminate\Support\Collection<int, string>  $cartIds
     */
    private function deleteOrStripCarts($cartIds): void
    {
        foreach ($cartIds as $cartId) {
            $hasFinancialHistory = DB::table('bookings')->where('cart_id', $cartId)->exists()
                || DB::table('payment_attempts')->where('cart_id', $cartId)->exists();

            if ($hasFinancialHistory) {
                DB::table('cart_locations')->where('cart_id', $cartId)->delete();

                continue;
            }

            DB::table('carts')->where('id', $cartId)->delete();
        }
    }

    /**
     * A Property never referenced by any Contract (any status, including
     * historical) is fully deleted. A Property referenced by a Contract -
     * `service_contracts.customer_property_id` is a RESTRICT foreign key,
     * so the row can never be deleted once any Contract (even a long-
     * CANCELLED/EXPIRED one) has used it - has every personal field
     * anonymized in place instead, exactly like
     * App\Actions\Property\ArchivePropertyAction's own is_active=0
     * convention, keeping only the non-personal reference-data ids
     * (area_id, property_type_id, property_relationship_type_id) a
     * retained Contract's own relational integrity needs.
     */
    private function deleteOrAnonymizeProperties(string $userIdBinary, string $timestamp): void
    {
        $propertyIds = DB::table('customer_properties')
            ->where('customer_user_id', $userIdBinary)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        foreach ($propertyIds as $propertyId) {
            $isReferencedByContract = DB::table('service_contracts')
                ->where('customer_property_id', $propertyId)
                ->exists();

            if (! $isReferencedByContract) {
                DB::table('customer_properties')->where('id', $propertyId)->delete();

                continue;
            }

            DB::table('customer_properties')->where('id', $propertyId)->update([
                'label' => 'Deleted property',
                'other_property_type_name' => null,
                'street_name' => 'Deleted',
                'address_line' => 'Deleted',
                'building_name_or_number' => 'Deleted',
                'floor_number' => null,
                'unit_number' => null,
                'nearby_landmark' => null,
                'additional_location_notes' => null,
                'visit_contact_phone' => $this->tombstonePhoneNumber(),
                'is_active' => 0,
                'updated_at' => $timestamp,
            ]);
        }
    }

    private function invalidatePendingOtps(string $userIdBinary, string $timestamp): void
    {
        DB::table('otp_verifications')
            ->where('user_id', $userIdBinary)
            ->where('status_id', $this->lookupId('otp_verification_statuses', 'PENDING'))
            ->update([
                'status_id' => $this->lookupId('otp_verification_statuses', 'INVALIDATED'),
                'invalidated_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
    }

    /**
     * A random (never predictable/collidable), never-personal placeholder
     * that satisfies uq_users_email, releasing the real original email for
     * a future signup. ".invalid" is the RFC 2606-reserved TLD for exactly
     * this "known never to resolve" placeholder use.
     */
    private function tombstoneEmail(): string
    {
        return 'deleted+'.bin2hex(random_bytes(12)).'@deleted.invalid';
    }

    /**
     * A random (never predictable/collidable), never-personal placeholder
     * that satisfies uq_users_phone_number / chk_users_phone_number_not_
     * blank (8-20 ascii chars) and can never authenticate, releasing the
     * real original phone number for a future signup.
     */
    private function tombstonePhoneNumber(): string
    {
        return 'DEL'.bin2hex(random_bytes(7));
    }

    private function success(): array
    {
        return ['success' => true, 'status' => 200, 'message' => 'Account deleted successfully.', 'data' => null];
    }

    private function failure(int $status, string $message): array
    {
        return ['success' => false, 'status' => $status, 'message' => $message, 'data' => null];
    }

    private function lookupId(string $table, string $code): int
    {
        $id = DB::table($table)->where('code', $code)->value('id');

        if ($id === null) {
            throw new RuntimeException("Missing required reference row: {$table}.code = {$code}");
        }

        return (int) $id;
    }
}
