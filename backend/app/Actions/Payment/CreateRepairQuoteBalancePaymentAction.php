<?php

namespace App\Actions\Payment;

use App\Support\Booking\BookingItemRepairQuoteStatuses;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Payment\CheckoutReferenceGenerator;
use App\Support\Payment\Gateway\PaymentCreationData;
use App\Support\Payment\Gateway\PaymentCreationOutcome;
use App\Support\Payment\Gateway\PaymentCreationResult;
use App\Support\Payment\Gateway\PaymentGateway;
use App\Support\Payment\PaymentStatuses;
use App\Support\Payment\RepairQuoteBalancePaymentPresenter;
use App\Support\Payment\RepairQuoteBalancePaymentStateMachine;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * BLUE V1 Phase B25 - the one server-authoritative entry point for paying
 * an ACCEPTED repair quote's remaining balance online (POST
 * /v1/bookings/{booking}/quote/pay-balance). Deliberately mirrors
 * App\Actions\Payment\CreatePaymentAttemptAction's overall shape
 * (idempotency-key lookup/resume, a locked "Transaction A" that persists a
 * PENDING attempt, then an out-of-transaction PaymentGateway call, then a
 * short "Transaction B" that persists whatever the gateway returned) but
 * against `repair_quote_payment_attempts` - a dedicated, Cart-less table -
 * never `payment_attempts` itself (see that table's docblock in
 * database/phase25_inspection_quote_credit_migration.sql for why: its
 * `cart_id`/`appointment_hold_id` columns are NOT NULL and this payment
 * books no appointment slot).
 *
 * `bookings.payment_attempt_id` (the original inspection payment) is never
 * read for write purposes here and never overwritten - this Action never
 * touches the `bookings` table at all. CARD/APPLE_PAY only - PAY_ON_SITE
 * is never valid for a repair-quote balance (BLUE V1 catalog spec Phase
 * B25 section 19), enforced by the same StripePaymentGateway every other
 * online payment already uses (`automatic_payment_methods.enabled = true`
 * - Apple Pay is that same PaymentIntent, never a second provider).
 */
final class CreateRepairQuoteBalancePaymentAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly RepairQuoteBalancePaymentStateMachine $stateMachine = new RepairQuoteBalancePaymentStateMachine,
    ) {}

    public function handle(string $userUuid, string $rawIdempotencyKey, string $bookingUuid): array
    {
        if (! Str::isUuid($rawIdempotencyKey)) {
            return $this->unprocessable('The Idempotency-Key header must be a valid UUID.');
        }

        try {
            $bookingIdBinary = UuidBinary::toBinary($bookingUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Booking not found.');
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
            $committed = DB::transaction(fn () => $this->commitTransactionA($userIdBinary, $bookingIdBinary, $idempotencyHash));
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
        $row = DB::table('repair_quote_payment_attempts')
            ->join('booking_item_repair_quotes', 'booking_item_repair_quotes.id', '=', 'repair_quote_payment_attempts.quote_id')
            ->join('bookings', 'bookings.id', '=', 'booking_item_repair_quotes.booking_id')
            ->join('carts', 'carts.id', '=', 'bookings.cart_id')
            ->where('repair_quote_payment_attempts.idempotency_key', $idempotencyHash)
            ->first(['repair_quote_payment_attempts.*', 'carts.customer_user_id']);

        if ($row === null) {
            return ['row' => null, 'conflict' => false];
        }

        if ($row->customer_user_id !== $userIdBinary) {
            return ['row' => null, 'conflict' => true];
        }

        return ['row' => $row, 'conflict' => false];
    }

    private function resumeExistingAttempt(object $row): array
    {
        $isPending = (int) $row->status_id === PaymentStatuses::id('PENDING');

        if ($isPending && $row->provider_session_reference === null) {
            return $this->attemptGatewayCreation($row, isNewAttempt: false);
        }

        return $this->ok(200, 'Balance payment attempt already exists.', ['payment' => RepairQuoteBalancePaymentPresenter::present($row)]);
    }

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

        if (str_contains($message, 'open_quote')) {
            return $this->conflict('A balance payment is already in progress for this repair quote.');
        }

        throw $e;
    }

    /**
     * @return array<string, mixed>
     */
    private function commitTransactionA(string $userIdBinary, string $bookingIdBinary, string $idempotencyHash): array
    {
        $booking = DB::table('bookings')
            ->join('carts', 'carts.id', '=', 'bookings.cart_id')
            ->where('bookings.id', $bookingIdBinary)
            ->where('carts.customer_user_id', $userIdBinary)
            ->first(['bookings.id']);

        if ($booking === null) {
            return $this->notFound('Booking not found.');
        }

        $quote = DB::table('booking_item_repair_quotes')
            ->where('booking_id', $bookingIdBinary)
            ->whereNull('closed_at')
            ->lockForUpdate()
            ->first();

        if ($quote === null) {
            return $this->notFound('No actionable repair quote for this Booking.');
        }

        if ((int) $quote->status_id !== BookingItemRepairQuoteStatuses::id('ACCEPTED')) {
            return $this->unprocessable('The repair quote must be accepted before its balance can be paid.');
        }

        if (bccomp((string) $quote->balance_due_amount, '0', 6) <= 0) {
            return $this->unprocessable('This repair quote has no remaining balance to pay.');
        }

        $hasOpenAttempt = DB::table('repair_quote_payment_attempts')
            ->where('quote_id', $quote->id)
            ->whereNull('finalized_at')
            ->lockForUpdate()
            ->exists();

        if ($hasOpenAttempt) {
            return $this->conflict('A balance payment is already in progress for this repair quote.');
        }

        $timestamp = now()->format('Y-m-d H:i:s.u');
        $attemptIdBinary = UuidBinary::toBinary(UuidBinary::generate());

        DB::table('repair_quote_payment_attempts')->insert([
            'id' => $attemptIdBinary,
            'quote_id' => $quote->id,
            'status_id' => PaymentStatuses::id('PENDING'),
            'currency_id' => $quote->currency_id,
            'reference' => CheckoutReferenceGenerator::generate(),
            'idempotency_key' => $idempotencyHash,
            'provider_code' => $this->gateway->providerCode(),
            'requested_amount' => $quote->balance_due_amount,
            'status_changed_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $row = DB::table('repair_quote_payment_attempts')->where('id', $attemptIdBinary)->first();

        return $this->ok(201, 'Balance payment attempt created.', ['row' => $row]);
    }

    /**
     * @return array<string, mixed>
     */
    private function attemptGatewayCreation(object $row, bool $isNewAttempt): array
    {
        $currency = DB::table('currencies')->where('id', $row->currency_id)->first(['code', 'minor_unit']);
        $attemptUuid = UuidBinary::toString($row->id);

        $creationData = new PaymentCreationData(
            paymentAttemptUuid: $attemptUuid,
            checkoutReference: $row->reference,
            amount: $row->requested_amount,
            currencyCode: $currency->code,
            currencyMinorUnit: (int) $currency->minor_unit,
            providerIdempotencyKey: 'blue_quote_balance_'.$attemptUuid,
        );

        $result = $this->gateway->createPayment($creationData);

        return match ($result->outcome) {
            PaymentCreationOutcome::CREATED => $this->persistCreationSuccess($row, $result, $isNewAttempt),
            PaymentCreationOutcome::DEFINITIVE_FAILURE => $this->compensateDefinitiveFailure($row),
            PaymentCreationOutcome::UNKNOWN => $this->ok(
                $isNewAttempt ? 201 : 200,
                'Balance payment attempt is pending provider confirmation.',
                ['payment' => RepairQuoteBalancePaymentPresenter::present($row)],
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function persistCreationSuccess(object $row, PaymentCreationResult $result, bool $isNewAttempt): array
    {
        $timestamp = now()->format('Y-m-d H:i:s.u');

        DB::table('repair_quote_payment_attempts')->where('id', $row->id)->update([
            'provider_session_reference' => $result->providerSessionReference,
            'provider_status_code' => $result->providerStatusCode,
            'updated_at' => $timestamp,
        ]);

        $fresh = DB::table('repair_quote_payment_attempts')->where('id', $row->id)->first();

        return $this->ok($isNewAttempt ? 201 : 200, 'Balance payment attempt created.', ['payment' => RepairQuoteBalancePaymentPresenter::present($fresh, $result)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function compensateDefinitiveFailure(object $row): array
    {
        DB::transaction(function () use ($row): void {
            $locked = DB::table('repair_quote_payment_attempts')->where('id', $row->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->stateMachine->isPending($locked)) {
                return;
            }

            $this->stateMachine->transitionToFailed($locked, now(), 'PROVIDER_CREATE_FAILED', 'The payment provider could not start this payment.', null);
        });

        $fresh = DB::table('repair_quote_payment_attempts')->where('id', $row->id)->first();

        return $this->ok(200, 'Payment could not be started.', ['payment' => RepairQuoteBalancePaymentPresenter::present($fresh)]);
    }
}
