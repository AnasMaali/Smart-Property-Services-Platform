<?php

namespace App\Actions\Auth;

use App\Models\OtpVerification;
use App\Models\User;
use App\Support\Auth\AccountDeletionEligibilityChecker;
use App\Support\Auth\AccountDeletionRequestStore;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Genuine customer account deletion, designed to support Apple App Store
 * guideline 5.1.1(v) ("Account Deletion") - never a status flip. Final
 * compliance also depends on the Flutter client actually surfacing this
 * endpoint in-app, and on the residual retention gaps documented in
 * docs/api-contracts/authentication-v1.md being resolved or accepted by
 * BLUE's own legal/product review; this Action alone does not constitute a
 * compliance guarantee. A DEACTIVATED account and a deleted account are
 * NOT the same thing: the actual erasure/anonymization write set (live PII
 * gone, sessions gone, OTPs gone, CUSTOMER authorization gone) lives in
 * App\Actions\Auth\AccountDeletionExecutor - the one authoritative
 * implementation, also used by App\Console\Commands\
 * ProcessPendingAccountDeletions, never duplicated here.
 *
 * BLUE V1 Phase 13 changed this Action from "refuse outright (409) while
 * blocked" to "initiate now, defer completion": Apple's guideline requires
 * a customer be ABLE TO INITIATE deletion even when completion must wait,
 * not merely be told to come back later with no durable record of their
 * request. The three possible outcomes:
 *
 *   - wrong OTP / non-ACTIVE account -> 422, nothing written.
 *   - active ADMIN/SUPER_ADMIN role -> 409, nothing written. A `users` row
 *     can legitimately be both a Customer and a company Admin at once
 *     (see AuthenticateAdmin), and the erasure lifecycle's tombstoning is
 *     not scoped to "just the Customer half" of a shared identity - we
 *     must never queue a process that could later tombstone an active
 *     company Admin, so this is refused outright, not deferred.
 *   - no blocking obligation -> erasure runs immediately, 200. If a
 *     PENDING request already existed (the customer is retrying after
 *     their obligation cleared), it is marked completed in the same
 *     transaction rather than left dangling.
 *   - a non-terminal Booking/Contract or an open/unresolved Payment
 *     blocks it -> a customer_account_deletion_requests row is created
 *     (or the existing one is returned unchanged - requested_at never
 *     moves on retry), 202. App\Support\Auth\PendingAccountDeletionGuard
 *     then prevents this customer from creating any NEW blocking
 *     obligation (Payment/Contract/CONTRACT Booking) while the request is
 *     pending, and App\Console\Commands\ProcessPendingAccountDeletions
 *     completes the exact same erasure automatically once the obligation
 *     reaches a terminal state. The customer's session remains fully
 *     usable while PENDING, specifically so they can resolve the
 *     obligation (cancel a Booking, wait for a Contract to end, etc.) -
 *     only actual completion revokes sessions.
 *
 * Financial/audit history that must legally or operationally survive
 * (SUCCESSFUL payment_attempts, COMPLETED/CANCELLED bookings, CANCELLED/
 * EXPIRED Contracts and their billing rows, booking/contract status
 * history) is never touched - only live, customer-controllable PII is
 * erased. payment_attempts.checkout_snapshot (address, visit_contact_phone)
 * is a known, separately-documented residual gap NOT addressed by this
 * change - see docs/api-contracts/authentication-v1.md.
 *
 * Lock order: users -> carts (all, ordered by id) -> customer_properties
 * (all, ordered by id, inside AccountDeletionExecutor) - matching the
 * project-wide "USER first" lock convention (see CreatePaymentAttemptAction,
 * AddCartItemAction). service_contracts/bookings/payment_attempts are
 * read, never locked, for the eligibility check - locking rows that do not
 * yet exist cannot prevent a concurrent insert either way, and every
 * high-traffic path that inserts a new cart/payment/contract obligation
 * for this customer now locks `users` first specifically to serialize
 * against this Action (see PendingAccountDeletionGuard's docblock for the
 * exact race this closes). No external network/provider call is ever made
 * here.
 */
class DeleteAccountAction
{
    private const GENERIC_INVALID_MESSAGE = 'Your session is no longer valid. Please log in again.';

    private const ADMIN_IDENTITY_MESSAGE = 'This account cannot be deleted through self-service while it retains administrative access. Please contact support.';

    private const PENDING_MESSAGE = 'Account deletion has been requested and will complete automatically after your active bookings, contracts, or payments are resolved.';

    public function __construct(
        private readonly AccountDeletionEligibilityChecker $checker = new AccountDeletionEligibilityChecker,
        private readonly AccountDeletionExecutor $executor = new AccountDeletionExecutor,
        private readonly AccountDeletionRequestStore $requestStore = new AccountDeletionRequestStore,
    ) {}

    /**
     * @param  array{otp_code: string}  $data
     * @return array{success: bool, status: int, message: string, data: array<string, mixed>|null}
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

            if (! $this->verifyAccountDeletionOtp($user, $data['otp_code'])) {
                return $this->failure(422, 'Invalid or expired verification code.');
            }

            if ($this->checker->hasActiveAdminRole($userIdBinary)) {
                return $this->failure(409, self::ADMIN_IDENTITY_MESSAGE);
            }

            $cartIds = DB::table('carts')
                ->where('customer_user_id', $userIdBinary)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            $now = now();

            if ($this->checker->hasBlockingObligation($userIdBinary, $cartIds)) {
                $request = $this->requestStore->createOrResumePending($userIdBinary, $now);

                return $this->pending($request->requested_at);
            }

            $this->executor->execute($user, $cartIds);
            $this->requestStore->markCompleted($userIdBinary, $now);

            return $this->success();
        });
    }

    private function success(): array
    {
        return ['success' => true, 'status' => 200, 'message' => 'Account deleted successfully.', 'data' => null];
    }

    /**
     * $requestedAt may be a string (fresh from DB::table()) or a Carbon
     * instance - always normalized to ISO-8601 for the response.
     */
    private function pending(mixed $requestedAt): array
    {
        return [
            'success' => true,
            'status' => 202,
            'message' => self::PENDING_MESSAGE,
            'data' => [
                'deletion_status' => 'PENDING',
                'requested_at' => Carbon::parse($requestedAt)->toIso8601String(),
            ],
        ];
    }

    private function failure(int $status, string $message): array
    {
        return ['success' => false, 'status' => $status, 'message' => $message, 'data' => null];
    }

    private function verifyAccountDeletionOtp(User $user, string $otpCode): bool
    {
        $purposeId = $this->lookupId('otp_verification_purposes', 'ACCOUNT_DELETION');
        $pendingOtpStatusId = $this->lookupId('otp_verification_statuses', 'PENDING');
        $attemptsExceededStatusId = $this->lookupId('otp_verification_statuses', 'ATTEMPTS_EXCEEDED');
        $verifiedStatusId = $this->lookupId('otp_verification_statuses', 'VERIFIED');
        $expiredStatusId = $this->lookupId('otp_verification_statuses', 'EXPIRED');

        $otp = OtpVerification::where('user_id', UuidBinary::toBinary($user->id))
            ->where('purpose_id', $purposeId)
            ->orderByDesc('created_at')
            ->lockForUpdate()
            ->first();

        if ($otp === null || $otp->status_id !== $pendingOtpStatusId) {
            return false;
        }

        $now = now();

        if ($now->greaterThanOrEqualTo($otp->expires_at)) {
            $otp->status_id = $expiredStatusId;
            $otp->save();

            return false;
        }

        if ($otp->failed_attempt_count >= $otp->max_attempts) {
            if ($otp->status_id !== $attemptsExceededStatusId) {
                $otp->status_id = $attemptsExceededStatusId;
                $otp->save();
            }

            return false;
        }

        if (! Hash::check($otpCode, $otp->code_hash)) {
            $otp->failed_attempt_count++;
            $otp->last_attempt_at = $now;

            if ($otp->failed_attempt_count >= $otp->max_attempts) {
                $otp->status_id = $attemptsExceededStatusId;
            }

            $otp->save();

            return false;
        }

        $otp->status_id = $verifiedStatusId;
        $otp->verified_at = $now;
        $otp->save();

        return true;
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
