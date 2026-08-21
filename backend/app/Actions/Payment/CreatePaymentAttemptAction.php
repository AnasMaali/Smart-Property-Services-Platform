<?php

namespace App\Actions\Payment;

use App\Models\AppointmentSlot;
use App\Models\Cart;
use App\Models\CartLocation;
use App\Models\User;
use App\Support\Auth\PendingAccountDeletionGuard;
use App\Support\Cart\CartStatuses;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Checkout\CheckoutPresenter;
use App\Support\Payment\CanonicalJson;
use App\Support\Payment\CheckoutReferenceGenerator;
use App\Support\Payment\CheckoutSnapshotBuilder;
use App\Support\Payment\Gateway\PaymentCreationData;
use App\Support\Payment\Gateway\PaymentCreationOutcome;
use App\Support\Payment\Gateway\PaymentCreationResult;
use App\Support\Payment\Gateway\PaymentGateway;
use App\Support\Payment\PaymentAttemptStateMachine;
use App\Support\Payment\PaymentPresenter;
use App\Support\Payment\PaymentStatuses;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The one server-authoritative Payment creation entry point (POST
 * /v1/payments). Revalidates Checkout from scratch inside a locked
 * transaction (Transaction A - see docs/api-contracts/payments-v1.md
 * "External call / DB transaction boundary"), freezes an immutable
 * checkout snapshot, renews the appointment hold, transitions the cart
 * ACTIVE -> CHECKOUT, and only then - fully outside any DB lock - calls the
 * configured PaymentGateway. Transaction B (a second, short transaction)
 * persists whatever the gateway returned; a DEFINITIVE_FAILURE outcome
 * triggers a compensation transaction (FAILED, hold released, cart back to
 * ACTIVE); an UNKNOWN outcome leaves the attempt PENDING and recoverable.
 *
 * Lock order: USER -> CART -> CART_LOCATION -> APPOINTMENT_SLOT ->
 * APPOINTMENT_HOLD, extending Checkout's own established order
 * (docs/api-contracts/checkout-v1.md §13) by one level. One captured $now
 * drives every expiry/readiness decision in Transaction A.
 */
class CreatePaymentAttemptAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly CheckoutPresenter $checkoutPresenter = new CheckoutPresenter,
        private readonly CheckoutSnapshotBuilder $snapshotBuilder = new CheckoutSnapshotBuilder,
        private readonly PaymentAttemptStateMachine $stateMachine = new PaymentAttemptStateMachine,
        private readonly PendingAccountDeletionGuard $deletionGuard = new PendingAccountDeletionGuard,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $userUuid, string $rawIdempotencyKey): array
    {
        if (! Str::isUuid($rawIdempotencyKey)) {
            return $this->unprocessable('The Idempotency-Key header must be a valid UUID.');
        }

        $userIdBinary = UuidBinary::toBinary($userUuid);
        $idempotencyHash = hash('sha256', strtolower($rawIdempotencyKey), true);

        $lookup = $this->findByIdempotencyKey($idempotencyHash, $userIdBinary);

        if ($lookup['conflict']) {
            return $this->unprocessable('This Idempotency-Key has already been used.');
        }

        if ($lookup['row'] !== null) {
            return $this->resumeExistingAttempt($lookup['row']);
        }

        try {
            $committed = DB::transaction(fn () => $this->commitTransactionA($userUuid, $userIdBinary, $idempotencyHash));
        } catch (UniqueConstraintViolationException $e) {
            return $this->handleInsertRace($e, $idempotencyHash, $userIdBinary);
        }

        if (! $committed['success']) {
            return $committed;
        }

        return $this->attemptGatewayCreation($committed['data']['row'], isNewAttempt: true);
    }

    /**
     * @return array{row: ?object, conflict: bool}
     */
    private function findByIdempotencyKey(string $idempotencyHash, string $userIdBinary): array
    {
        $row = DB::table('payment_attempts')
            ->join('carts', 'carts.id', '=', 'payment_attempts.cart_id')
            ->where('payment_attempts.idempotency_key', $idempotencyHash)
            ->first(['payment_attempts.*', 'carts.customer_user_id']);

        if ($row === null) {
            return ['row' => null, 'conflict' => false];
        }

        if ($row->customer_user_id !== $userIdBinary) {
            // Global uniqueness on idempotency_key means a key collision
            // across two different customers is possible in principle -
            // never resolve or reveal another customer's payment attempt.
            return ['row' => null, 'conflict' => true];
        }

        return ['row' => $row, 'conflict' => false];
    }

    /**
     * @return array<string, mixed>
     */
    private function resumeExistingAttempt(object $row): array
    {
        $isPending = (int) $row->status_id === PaymentStatuses::id('PENDING');

        if ($isPending && $row->provider_session_reference === null) {
            // The previous call never confirmed a provider object was
            // created (network loss, timeout, or a DB failure between the
            // provider call and Transaction B). Safe to retry the gateway
            // call with the SAME stable provider idempotency key - never a
            // new local attempt, never a new provider idempotency key.
            return $this->attemptGatewayCreation($row, isNewAttempt: false);
        }

        return $this->ok(200, 'Payment attempt already exists.', ['payment' => PaymentPresenter::present($row)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleInsertRace(UniqueConstraintViolationException $e, string $idempotencyHash, string $userIdBinary): array
    {
        $message = $e->getMessage();

        if (str_contains($message, 'idempotency_key')) {
            $existing = $this->findByIdempotencyKey($idempotencyHash, $userIdBinary);

            if ($existing['row'] !== null) {
                return $this->resumeExistingAttempt($existing['row']);
            }

            return $this->unprocessable('This Idempotency-Key has already been used.');
        }

        if (str_contains($message, 'open_cart')) {
            return $this->conflict('A payment is already in progress for this cart.');
        }

        throw $e;
    }

    /**
     * Whether the authenticated customer already owns an open (non-final)
     * payment attempt on any of their carts - the CHECKOUT cart's attempt
     * survives even though the cart itself is no longer ACTIVE, so this
     * cannot be found by looking for an ACTIVE cart alone.
     */
    private function hasOpenAttemptForCustomer(string $userIdBinary): bool
    {
        return DB::table('payment_attempts')
            ->join('carts', 'carts.id', '=', 'payment_attempts.cart_id')
            ->where('carts.customer_user_id', $userIdBinary)
            ->whereNull('payment_attempts.finalized_at')
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function commitTransactionA(string $userUuid, string $userIdBinary, string $idempotencyHash): array
    {
        $now = now();

        $user = User::where('id', $userIdBinary)->lockForUpdate()->first();

        if ($user === null) {
            throw new RuntimeException("Authenticated user {$userUuid} not found.");
        }

        if ($this->deletionGuard->isPending($userIdBinary)) {
            return $this->conflict(PendingAccountDeletionGuard::REJECTION_MESSAGE);
        }

        $cart = Cart::where('customer_user_id', $userIdBinary)
            ->where('status_id', CartStatuses::id('ACTIVE'))
            ->lockForUpdate()
            ->first();

        if ($cart === null) {
            // No ACTIVE cart to open a new attempt against. Before falling
            // back to a plain not-found, distinguish "this customer already
            // has an open payment attempt on a CHECKOUT cart" (a known,
            // owned financial state - 409) from "truly nothing to pay for"
            // (404). The User row lock taken above already serializes
            // concurrent requests from this same customer, so this read is
            // race-free without an extra lock.
            if ($this->hasOpenAttemptForCustomer($userIdBinary)) {
                return $this->conflict('A payment is already in progress for this checkout.');
            }

            return $this->notFound('No active cart to pay for.');
        }

        $cartIdBinary = UuidBinary::toBinary($cart->id);

        $hasOpenAttempt = DB::table('payment_attempts')
            ->where('cart_id', $cartIdBinary)
            ->whereNull('finalized_at')
            ->lockForUpdate()
            ->exists();

        if ($hasOpenAttempt) {
            return $this->conflict('A payment is already in progress for this cart.');
        }

        $location = CartLocation::where('cart_id', $cartIdBinary)->lockForUpdate()->first();

        if ($location === null) {
            return $this->unprocessable('Checkout is not ready for payment.');
        }

        $currentHold = DB::table('appointment_holds')
            ->where('cart_id', $cartIdBinary)
            ->whereNull('released_at')
            ->whereNull('converted_at')
            ->where('expires_at', '>', $now)
            ->orderByDesc('created_at')
            ->first(['id', 'appointment_slot_id']);

        if ($currentHold === null) {
            return $this->unprocessable('Checkout is not ready for payment.');
        }

        $slot = AppointmentSlot::query()
            ->join('appointment_time_windows', 'appointment_time_windows.id', '=', 'appointment_slots.time_window_id')
            ->where('appointment_slots.id', $currentHold->appointment_slot_id)
            ->where('appointment_slots.is_active', 1)
            ->where('appointment_time_windows.is_active', 1)
            ->lockForUpdate()
            ->first(['appointment_slots.*']);

        if ($slot === null || $slot->starts_at->lessThanOrEqualTo($now)) {
            return $this->unprocessable('Checkout is not ready for payment.');
        }

        $heldRow = DB::table('appointment_holds')->where('id', $currentHold->id)->lockForUpdate()->first();

        if ($heldRow === null
            || $heldRow->released_at !== null
            || $heldRow->converted_at !== null
            || Carbon::parse($heldRow->expires_at)->lessThanOrEqualTo($now)
        ) {
            return $this->unprocessable('Checkout is not ready for payment.');
        }

        $presented = $this->checkoutPresenter->present($cart);

        if ($presented['ready_for_payment'] !== true) {
            return $this->unprocessable('Checkout is not ready for payment.');
        }

        $timestamp = $now->format('Y-m-d H:i:s.u');
        $ttlMinutes = (int) config('checkout.appointment_hold_ttl_minutes');
        $newExpiresAt = $now->copy()->addMinutes($ttlMinutes)->format('Y-m-d H:i:s.u');

        DB::table('appointment_holds')->where('id', $currentHold->id)->update([
            'expires_at' => $newExpiresAt,
            'updated_at' => $timestamp,
        ]);

        DB::table('carts')->where('id', $cartIdBinary)->update([
            'status_id' => CartStatuses::id('CHECKOUT'),
            'status_changed_at' => $timestamp,
            'last_activity_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $snapshot = $this->snapshotBuilder->build($location, $slot, $presented);
        $canonicalJson = CanonicalJson::encode($snapshot);
        $snapshotHash = CanonicalJson::sha256($canonicalJson);

        $paymentAttemptIdBinary = UuidBinary::toBinary(UuidBinary::generate());
        $checkoutReference = CheckoutReferenceGenerator::generate();

        DB::table('payment_attempts')->insert([
            'id' => $paymentAttemptIdBinary,
            'cart_id' => $cartIdBinary,
            'appointment_hold_id' => $currentHold->id,
            'status_id' => PaymentStatuses::id('PENDING'),
            'currency_id' => $cart->currency_id,
            'checkout_reference' => $checkoutReference,
            'idempotency_key' => $idempotencyHash,
            'provider_code' => $this->gateway->providerCode(),
            'requested_amount' => $presented['total'],
            'checkout_snapshot' => $canonicalJson,
            'checkout_snapshot_hash' => $snapshotHash,
            'expires_at' => $newExpiresAt,
            'status_changed_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $row = DB::table('payment_attempts')->where('id', $paymentAttemptIdBinary)->first();

        return $this->ok(201, 'Payment attempt created.', ['row' => $row]);
    }

    /**
     * @return array<string, mixed>
     */
    private function attemptGatewayCreation(object $row, bool $isNewAttempt): array
    {
        $currency = DB::table('currencies')->where('id', $row->currency_id)->first(['code', 'minor_unit']);
        $paymentAttemptUuid = UuidBinary::toString($row->id);

        $creationData = new PaymentCreationData(
            paymentAttemptUuid: $paymentAttemptUuid,
            checkoutReference: $row->checkout_reference,
            amount: $row->requested_amount,
            currencyCode: $currency->code,
            currencyMinorUnit: (int) $currency->minor_unit,
            providerIdempotencyKey: 'blue_'.$paymentAttemptUuid,
        );

        $result = $this->gateway->createPayment($creationData);

        return match ($result->outcome) {
            PaymentCreationOutcome::CREATED => $this->persistCreationSuccess($row, $result, $isNewAttempt),
            PaymentCreationOutcome::DEFINITIVE_FAILURE => $this->compensateDefinitiveFailure($row),
            PaymentCreationOutcome::UNKNOWN => $this->ok(
                $isNewAttempt ? 201 : 200,
                'Payment attempt is pending provider confirmation.',
                ['payment' => PaymentPresenter::present($row)],
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function persistCreationSuccess(object $row, PaymentCreationResult $result, bool $isNewAttempt): array
    {
        $timestamp = now()->format('Y-m-d H:i:s.u');

        DB::table('payment_attempts')->where('id', $row->id)->update([
            'provider_session_reference' => $result->providerSessionReference,
            'provider_status_code' => $result->providerStatusCode,
            'updated_at' => $timestamp,
        ]);

        $fresh = DB::table('payment_attempts')->where('id', $row->id)->first();

        return $this->ok($isNewAttempt ? 201 : 200, 'Payment attempt created.', ['payment' => PaymentPresenter::present($fresh, $result)]);
    }

    /**
     * Compensation transaction for a DEFINITIVE_FAILURE creation outcome:
     * PENDING -> FAILED, the hold released, and the cart returned to
     * ACTIVE. Re-locks and re-checks the attempt is still PENDING before
     * touching anything - a concurrent webhook/fetch may already have
     * resolved it, and a terminal/successful attempt must never regress.
     *
     * @return array<string, mixed>
     */
    private function compensateDefinitiveFailure(object $row): array
    {
        DB::transaction(function () use ($row): void {
            $locked = DB::table('payment_attempts')->where('id', $row->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->stateMachine->isPending($locked)) {
                return;
            }

            $now = now();
            $transitioned = $this->stateMachine->transitionToFailed(
                $locked,
                $now,
                'PROVIDER_CREATE_FAILED',
                'The payment provider could not start this payment.',
                null,
            );

            if (! $transitioned) {
                return;
            }

            $timestamp = $now->format('Y-m-d H:i:s.u');

            DB::table('appointment_holds')->where('id', $locked->appointment_hold_id)
                ->whereNull('released_at')
                ->whereNull('converted_at')
                ->update(['released_at' => $timestamp, 'updated_at' => $timestamp]);

            DB::table('carts')->where('id', $locked->cart_id)
                ->where('status_id', CartStatuses::id('CHECKOUT'))
                ->update([
                    'status_id' => CartStatuses::id('ACTIVE'),
                    'status_changed_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
        });

        $fresh = DB::table('payment_attempts')->where('id', $row->id)->first();

        return $this->ok(200, 'Payment could not be started.', ['payment' => PaymentPresenter::present($fresh)]);
    }
}
