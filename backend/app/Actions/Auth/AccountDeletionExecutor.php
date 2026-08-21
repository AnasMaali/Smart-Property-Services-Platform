<?php

namespace App\Actions\Auth;

use App\Actions\Auth\Concerns\RevokesAuthSessions;
use App\Models\User;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The one authoritative implementation of the actual irreversible erasure/
 * anonymization write set - unchanged from the original Phase 12
 * DeleteAccountAction, only relocated so both the HTTP-facing immediate
 * path (DeleteAccountAction) and the scheduled deferred path
 * (App\Console\Commands\ProcessPendingAccountDeletions) call the exact
 * same code rather than maintaining two copies.
 *
 * Callers MUST already, in the same transaction:
 *   - hold `users` FOR UPDATE for $user,
 *   - hold every one of $cartIds FOR UPDATE,
 *   - have confirmed eligibility via AccountDeletionEligibilityChecker
 *     (no non-terminal Booking/Contract, no open/unresolved Payment, no
 *     active ADMIN/SUPER_ADMIN role).
 *
 * This class performs no eligibility checks itself and makes no external
 * network/provider (Stripe, Twilio) call.
 */
final class AccountDeletionExecutor
{
    use RevokesAuthSessions;

    /**
     * @param  Collection<int, string>  $cartIds  Raw binary(16) cart ids, already locked by the caller.
     */
    public function execute(User $user, Collection $cartIds): void
    {
        $userIdBinary = UuidBinary::toBinary($user->id);
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
        // making this request (if any - the scheduled processor has none),
        // is revoked - the account is gone.
        $this->revokeOtherSessions($userIdBinary, $now);
    }

    /**
     * A cart with no Booking and no Payment Attempt ever made against it
     * (eligibility already proved neither is non-terminal/open, but a
     * TERMINAL one - a completed Booking, a failed/cancelled/already-
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
     * @param  Collection<int, string>  $cartIds
     */
    private function deleteOrStripCarts(Collection $cartIds): void
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

    private function lookupId(string $table, string $code): int
    {
        $id = DB::table($table)->where('code', $code)->value('id');

        if ($id === null) {
            throw new RuntimeException("Missing required reference row: {$table}.code = {$code}");
        }

        return (int) $id;
    }
}
