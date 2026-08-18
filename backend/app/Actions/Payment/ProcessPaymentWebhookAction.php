<?php

namespace App\Actions\Payment;

use App\Actions\Booking\CreateBookingFromSuccessfulPaymentAction;
use App\Support\Cart\CartStatuses;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Payment\CanonicalJson;
use App\Support\Payment\Gateway\NormalizedPaymentEvent;
use App\Support\Payment\Gateway\NormalizedPaymentOutcome;
use App\Support\Payment\Gateway\PaymentGateway;
use App\Support\Payment\PaymentAttemptStateMachine;
use App\Support\Uuid\UuidBinary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Processes exactly one inbound provider webhook delivery end to end:
 * signature verification (against the RAW body only - see the Controller,
 * which never JSON-decodes before calling this) -> normalization ->
 * event-ledger dedup -> locked state-machine transition -> reconciliation
 * checks -> ledger finalization. Phase 6A itself still ends at a
 * SUCCESSFUL (possibly reconciliation-flagged) payment attempt and never
 * writes a `bookings` row directly.
 *
 * Phase 7A hand-off: once that transaction commits, `handle()` makes one
 * best-effort, idempotent attempt to convert the resolved attempt into a
 * Booking (CreateBookingFromSuccessfulPaymentAction) - deliberately
 * OUTSIDE the transaction above and in its own separate transaction, so a
 * Booking-conversion failure can never roll back or otherwise affect the
 * payment state that has already safely committed. Any exception from
 * that attempt is reported and swallowed - the webhook still responds 200
 * (the delivery itself was validly processed) and the missing Booking
 * remains recoverable via `php artisan bookings:convert-successful-
 * payments` (see App\Console\Commands\ConvertSuccessfulPaymentsToBookings).
 *
 * Webhook trust boundary: verifyWebhook() must pass before anything else
 * runs. An invalid/unverified payload never reaches the ledger and never
 * mutates a payment attempt (docs/api-contracts/payments-v1.md "Webhook
 * trust boundary").
 */
class ProcessPaymentWebhookAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentAttemptStateMachine $stateMachine = new PaymentAttemptStateMachine,
        private readonly CreateBookingFromSuccessfulPaymentAction $bookingConversion = new CreateBookingFromSuccessfulPaymentAction,
    ) {}

    /**
     * @param  array<string, string>  $signatureHeaders
     * @return array<string, mixed>
     */
    public function handle(string $rawBody, array $signatureHeaders): array
    {
        $verified = $this->gateway->verifyWebhook($rawBody, $signatureHeaders);

        if (! $verified->valid) {
            return $this->unprocessable('Invalid webhook signature.');
        }

        $event = $this->gateway->parseWebhook($verified->providerEvent);
        $payloadHash = hash('sha256', $rawBody, binary: true);

        $transactionResult = DB::transaction(function () use ($event, $payloadHash): array {
            $ledger = $this->ledgerEntry($event, $payloadHash);

            if ($ledger['alreadyProcessed']) {
                return [
                    'alreadyProcessed' => true,
                    'resolvedAttemptId' => null,
                ];
            }

            return [
                'alreadyProcessed' => false,
                'resolvedAttemptId' => $this->process($event, $ledger['id']),
            ];
        });

        if ($transactionResult['alreadyProcessed']) {
            return $this->ok(200, 'Webhook already processed.', []);
        }

        $resolvedAttemptId = $transactionResult['resolvedAttemptId'];

        if ($resolvedAttemptId !== null) {
            $this->attemptBookingConversion($resolvedAttemptId);
        }

        return $this->ok(200, 'Webhook processed.', []);
    }

    /**
     * Never allowed to turn a webhook delivery into a 5xx or to touch the
     * payment_attempts row's financial fields - the payment already
     * committed SUCCESSFUL (or whatever terminal/non-terminal state Phase
     * 6A resolved) before this runs. A failure here only means the
     * Booking is not yet visible; `requires_reconciliation` is untouched,
     * so the recovery Artisan command can always find and retry it.
     */
    private function attemptBookingConversion(string $paymentAttemptIdBinary): void
    {
        try {
            $this->bookingConversion->handle(UuidBinary::toString($paymentAttemptIdBinary));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Inserts (or, on a duplicate provider_event_id, safely reuses) the
     * event-ledger row. UNIQUE(provider_code, provider_event_id) is the
     * hard backstop; processing_attempt_count is bumped on every retry of
     * an event that never reached PROCESSED, so an interrupted delivery
     * (crash mid-processing) can always be retried by the provider.
     *
     * @return array{id: string, alreadyProcessed: bool}
     */
    private function ledgerEntry(NormalizedPaymentEvent $event, string $payloadHash): array
    {
        $now = now();
        $timestamp = $now->format('Y-m-d H:i:s.u');
        $providerCode = $this->gateway->providerCode();

        // Probe without a locking read first. If the row already exists,
        // re-read that concrete row under FOR UPDATE so only one delivery
        // can process a retryable event at a time. The missing-key path goes
        // directly to the unique INSERT claim instead of locking its gap.
        $existingId = DB::table('payment_webhook_events')
            ->where('provider_code', $providerCode)
            ->where('provider_event_id', $event->providerEventId)
            ->value('id');

        $existing = $existingId === null
            ? null
            : DB::table('payment_webhook_events')
                ->where('id', $existingId)
                ->lockForUpdate()
                ->first(['id', 'status_id']);

        if ($existing !== null) {
            $processedStatusId = $this->webhookStatusId('PROCESSED');
            $ignoredStatusId = $this->webhookStatusId('IGNORED');

            if ((int) $existing->status_id === $processedStatusId || (int) $existing->status_id === $ignoredStatusId) {
                return ['id' => $existing->id, 'alreadyProcessed' => true];
            }

            DB::table('payment_webhook_events')->where('id', $existing->id)->increment('processing_attempt_count', 1, [
                'updated_at' => $timestamp,
            ]);

            return ['id' => $existing->id, 'alreadyProcessed' => false];
        }

        $idBinary = UuidBinary::toBinary(UuidBinary::generate());

        try {
            DB::table('payment_webhook_events')->insert([
                'id' => $idBinary,
                'provider_code' => $providerCode,
                'provider_event_id' => $event->providerEventId,
                'payment_attempt_id' => null,
                'event_type' => $event->eventType,
                'provider_transaction_reference' => $event->providerTransactionReference,
                'payload_hash' => $payloadHash,
                'status_id' => $this->webhookStatusId('RECEIVED'),
                'processing_attempt_count' => 1,
                'received_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'provider_event')) {
                throw $e;
            }

            // Another concurrent delivery won the unique INSERT race.
            // Do NOT recurse through the earlier non-locking probe: under
            // InnoDB REPEATABLE READ that probe may still see the original
            // transaction snapshot. Read the concrete winning row using a
            // locking/current read instead.
            $existing = DB::table('payment_webhook_events')
                ->where('provider_code', $providerCode)
                ->where('provider_event_id', $event->providerEventId)
                ->lockForUpdate()
                ->first(['id', 'status_id']);

            if ($existing === null) {
                throw $e;
            }

            $processedStatusId = $this->webhookStatusId('PROCESSED');
            $ignoredStatusId = $this->webhookStatusId('IGNORED');

            if (
                (int) $existing->status_id === $processedStatusId
                || (int) $existing->status_id === $ignoredStatusId
            ) {
                return ['id' => $existing->id, 'alreadyProcessed' => true];
            }

            DB::table('payment_webhook_events')
                ->where('id', $existing->id)
                ->increment('processing_attempt_count', 1, [
                    'updated_at' => $timestamp,
                ]);

            return ['id' => $existing->id, 'alreadyProcessed' => false];
        }

        return ['id' => $idBinary, 'alreadyProcessed' => false];
    }

    /**
     * @return string|null The resolved payment_attempts.id (raw binary),
     *                     for the caller's post-commit Phase 7A conversion attempt - regardless
     *                     of which outcome branch actually ran, since a Booking-conversion
     *                     retry is always safe to attempt and is exactly how a previously
     *                     failed/skipped conversion for an already-SUCCESSFUL attempt gets a
     *                     second chance on the next delivery of the same or a later event.
     *                     null only when no local attempt could be resolved at all.
     */
    private function process(NormalizedPaymentEvent $event, string $ledgerId): ?string
    {
        // An UNRECOGNIZED outcome means this authentic event is not one
        // BLUE Payment Core acts on (e.g. a non-PaymentIntent Stripe event
        // such as customer.created) - see NormalizedPaymentOutcome::
        // UNRECOGNIZED. It must never be resolved against payment_attempts
        // first: an event with no session/checkout reference to resolve
        // would otherwise be misclassified as PAYMENT_ATTEMPT_NOT_FOUND
        // instead of IGNORED.
        if ($event->outcome === NormalizedPaymentOutcome::UNRECOGNIZED) {
            $this->finalizeLedger($ledgerId, 'IGNORED', null, null, null);

            return null;
        }

        $attempt = $this->resolveAttempt($event);

        if ($attempt === null) {
            $this->finalizeLedger($ledgerId, 'FAILED', null, 'PAYMENT_ATTEMPT_NOT_FOUND', 'No local payment attempt matched this event.');

            return null;
        }

        $locked = DB::table('payment_attempts')->where('id', $attempt->id)->lockForUpdate()->first();

        if ($locked === null) {
            $this->finalizeLedger($ledgerId, 'FAILED', null, 'PAYMENT_ATTEMPT_NOT_FOUND', 'No local payment attempt matched this event.');

            return null;
        }

        $now = now();

        match ($event->outcome) {
            NormalizedPaymentOutcome::SUCCEEDED => $this->handleSucceeded($locked, $event, $now, $ledgerId),
            NormalizedPaymentOutcome::CANCELED => $this->handleCanceled($locked, $event, $now, $ledgerId),
            NormalizedPaymentOutcome::NON_TERMINAL => $this->handleNonTerminal($locked, $event, $ledgerId),
            NormalizedPaymentOutcome::UNEXPECTED_NON_TERMINAL => $this->handleUnexpectedNonTerminal($locked, $event, $ledgerId),
            NormalizedPaymentOutcome::UNRECOGNIZED => $this->finalizeLedger($ledgerId, 'IGNORED', $locked->id, null, null),
        };

        return $locked->id;
    }

    private function resolveAttempt(NormalizedPaymentEvent $event): ?object
    {
        if ($event->providerSessionReference !== null) {
            $row = DB::table('payment_attempts')
                ->where('provider_code', $this->gateway->providerCode())
                ->where('provider_session_reference', $event->providerSessionReference)
                ->first();

            if ($row !== null) {
                return $row;
            }
        }

        if ($event->checkoutReference !== null) {
            return DB::table('payment_attempts')->where('checkout_reference', $event->checkoutReference)->first();
        }

        return null;
    }

    private function handleSucceeded(object $locked, NormalizedPaymentEvent $event, Carbon $now, string $ledgerId): void
    {
        $reason = $this->stateMachine->isPending($locked) ? $this->determineReconciliationReason($locked, $event, $now) : null;

        $transitioned = $this->stateMachine->transitionToSuccessful(
            $locked,
            $now,
            $event->amount ?? $locked->requested_amount,
            $event->providerTransactionReference,
            $event->providerStatusCode,
            $event->paymentMethodType,
            requiresReconciliation: $reason !== null,
            reconciliationReasonCode: $reason,
        );

        if (! $transitioned) {
            $this->finalizeLedger($ledgerId, 'IGNORED', $locked->id, null, null);

            return;
        }

        $this->finalizeLedger($ledgerId, 'PROCESSED', $locked->id, null, null);
    }

    private function handleCanceled(object $locked, NormalizedPaymentEvent $event, Carbon $now, string $ledgerId): void
    {
        $transitioned = $this->stateMachine->transitionToCancelled($locked, $now, $event->providerStatusCode);

        if (! $transitioned) {
            $this->finalizeLedger($ledgerId, 'IGNORED', $locked->id, null, null);

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

        $this->finalizeLedger($ledgerId, 'PROCESSED', $locked->id, null, null);
    }

    /**
     * requires_payment_method / requires_confirmation / requires_action /
     * processing - the attempt correctly stays PENDING. Only the raw
     * provider status is refreshed for observability, and only while still
     * PENDING - a stale non-terminal event arriving after the attempt was
     * already finalized must never touch it.
     */
    private function handleNonTerminal(object $locked, NormalizedPaymentEvent $event, string $ledgerId): void
    {
        if ($this->stateMachine->isPending($locked)) {
            $update = ['updated_at' => now()->format('Y-m-d H:i:s.u')];

            if ($event->providerStatusCode !== null) {
                $update['provider_status_code'] = $event->providerStatusCode;
            }

            if ($event->paymentMethodType !== null) {
                $update['payment_method_type'] = $event->paymentMethodType;
            }

            DB::table('payment_attempts')->where('id', $locked->id)->update($update);
        }

        $this->finalizeLedger($ledgerId, 'PROCESSED', $locked->id, null, null);
    }

    /**
     * requires_capture - BLUE V1 never intentionally uses manual capture,
     * so this is a configuration/integration problem, not a customer
     * outcome. Stays PENDING, flagged for reconciliation/observability -
     * never SUCCESSFUL, never FAILED.
     */
    private function handleUnexpectedNonTerminal(object $locked, NormalizedPaymentEvent $event, string $ledgerId): void
    {
        if ($this->stateMachine->isPending($locked)) {
            DB::table('payment_attempts')->where('id', $locked->id)->update([
                'provider_status_code' => $event->providerStatusCode,
                'requires_reconciliation' => 1,
                'reconciliation_reason_code' => 'UNEXPECTED_PROVIDER_STATE',
                'updated_at' => now()->format('Y-m-d H:i:s.u'),
            ]);
        }

        $this->finalizeLedger($ledgerId, 'PROCESSED', $locked->id, null, null);
    }

    /**
     * Financial truth from a verified provider SUCCEEDED event always
     * wins - see docs/api-contracts/payments-v1.md "Reconciliation". This
     * never returns FAILED; it only decides whether the SUCCESSFUL
     * transition must also carry a reconciliation flag Phase 7 will refuse
     * to auto-book from.
     */
    private function determineReconciliationReason(object $locked, NormalizedPaymentEvent $event, Carbon $now): ?string
    {
        if ($event->amount !== null && bccomp($event->amount, (string) $locked->requested_amount, 6) !== 0) {
            return 'AMOUNT_MISMATCH';
        }

        if ($event->currencyCode !== null) {
            $currencyCode = DB::table('currencies')->where('id', $locked->currency_id)->value('code');

            if ($event->currencyCode !== $currencyCode) {
                return 'CURRENCY_MISMATCH';
            }
        }

        $hold = DB::table('appointment_holds')->where('id', $locked->appointment_hold_id)->first();

        if ($hold === null || $hold->cart_id !== $locked->cart_id) {
            return 'HOLD_CART_MISMATCH';
        }

        if ($hold->released_at !== null) {
            return 'HOLD_RELEASED';
        }

        if (Carbon::parse($hold->expires_at)->lessThanOrEqualTo($now)) {
            return 'HOLD_EXPIRED';
        }

        // Re-canonicalize before re-hashing: MySQL's JSON column is not
        // guaranteed to return the exact bytes that were inserted (only
        // the same semantic content), so comparing raw bytes would produce
        // false-positive integrity failures. CanonicalJson::encode() is a
        // pure function of the decoded value, independent of how MySQL
        // chose to format it on storage/retrieval.
        $recomputedHash = CanonicalJson::sha256(CanonicalJson::encode(json_decode((string) $locked->checkout_snapshot, true)));

        if (! hash_equals((string) $locked->checkout_snapshot_hash, $recomputedHash)) {
            return 'SNAPSHOT_INTEGRITY_FAILURE';
        }

        return null;
    }

    private function finalizeLedger(string $ledgerId, string $statusCode, ?string $paymentAttemptId, ?string $errorCode, ?string $errorMessage): void
    {
        $timestamp = now()->format('Y-m-d H:i:s.u');

        DB::table('payment_webhook_events')->where('id', $ledgerId)->update([
            'status_id' => $this->webhookStatusId($statusCode),
            'payment_attempt_id' => $paymentAttemptId,
            'processed_at' => $timestamp,
            'last_error_code' => $errorCode,
            'last_error_message' => $errorMessage,
            'updated_at' => $timestamp,
        ]);
    }

    private function webhookStatusId(string $code): int
    {
        $id = DB::table('payment_webhook_event_statuses')->where('code', $code)->where('is_active', 1)->value('id');

        if ($id === null) {
            throw new RuntimeException("Missing required reference row: payment_webhook_event_statuses.code = {$code}");
        }

        return (int) $id;
    }
}
