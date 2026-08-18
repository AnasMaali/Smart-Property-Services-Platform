<?php

namespace App\Actions\Contract\Billing;

use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Contract\Billing\ContractBillingStatuses;
use App\Support\Contract\Billing\Gateway\ContractBillingGateway;
use App\Support\Contract\Billing\Gateway\NormalizedContractBillingEvent;
use App\Support\Contract\ContractStatuses;
use App\Support\Contract\ContractStatusMachine;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Processes exactly one inbound Stripe Contract Billing webhook delivery
 * end to end - signature verification (raw body only, see
 * ContractBillingWebhookController) -> normalization -> event-ledger dedup
 * -> locked state transitions. Structurally mirrors
 * App\Actions\Payment\ProcessPaymentWebhookAction (same ledger-first,
 * dedup-before-DB-write shape) but is a fully separate class with a fully
 * separate ledger table (`service_contract_billing_webhook_events`) - a
 * Subscription/Invoice event is never routed through the PaymentAttempt
 * ledger or state machine, and vice versa (BLUE V1 Phase 11 "Do not
 * blindly mix PaymentAttempt event handling with Subscription handling").
 *
 * Webhook trust boundary: verifyWebhook() (against this domain's OWN
 * Stripe-issued signing secret) must pass before anything else runs. An
 * invalid/unverified payload never reaches the ledger and never mutates a
 * billing row.
 *
 * Activation authority (BLUE V1 Phase 11 "INITIAL ACTIVATION"):
 * checkout.session.completed and customer.subscription.* NEVER move
 * `service_contract_billings.status_id` to ACTIVE and NEVER move the parent
 * Contract PENDING_PAYMENT -> ACTIVE - they only link Stripe references and
 * sync period/cancellation metadata. Only invoice.paid - authoritative
 * proof the subscription's invoice was actually paid - can do either. This
 * is deliberately more conservative than Stripe's own subscription.status
 * ('active' can appear on subscription.created/updated before the
 * associated invoice.paid webhook is guaranteed to have already been
 * processed for out-of-order delivery).
 *
 * Terminal-state / out-of-order protection: every handler below re-checks
 * the CURRENT locked status before writing - a CANCELLED billing row is
 * never resurrected by a late-arriving event of any kind, and a Contract
 * already ACTIVE never receives a second "activation" status-history row
 * (BLUE V1 Phase 11 "out-of-order events must not regress a terminal
 * billing status" / "do not create duplicate Contract lifecycle history if
 * Contract is already ACTIVE").
 *
 * Lock order whenever both are needed (invoice.paid only): SERVICE_CONTRACT
 * -> SERVICE_CONTRACT_BILLING - identical to
 * App\Actions\Contract\Billing\CreateContractBillingCheckoutAction and
 * App\Actions\Contract\CreateContractBookingAction, so this webhook handler
 * can never deadlock against either. Every other event handler here only
 * ever locks SERVICE_CONTRACT_BILLING alone.
 *
 * Term-end cancel_at scheduling (BLUE V1 Phase 11 real-Stripe fix): the
 * moment a Subscription id first becomes known - from either
 * checkout.session.completed (handleCheckoutCompleted) or
 * customer.subscription.created/updated (handleSubscriptionSync), whichever
 * webhook is delivered first (webhook-order-safe) - process() returns that
 * Contract's id so handle() can fire
 * App\Actions\Contract\Billing\ScheduleContractBillingSubscriptionCancelAtAction
 * AFTER the DB transaction commits (never inside it, exactly like every
 * other provider network call in this domain). Every other handler always
 * returns null; scheduling is only ever attempted from those two event
 * types.
 */
class ProcessContractBillingWebhookAction
{
    use BuildsCartResult;

    private const HANDLED_EVENT_TYPES = [
        'checkout.session.completed',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'invoice.paid',
        'invoice.payment_failed',
        'invoice.payment_action_required',
    ];

    public function __construct(
        private readonly ContractBillingGateway $gateway,
        private readonly ScheduleContractBillingSubscriptionCancelAtAction $scheduleCancelAt,
        private readonly ContractStatusMachine $contractMachine = new ContractStatusMachine,
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

        $ledger = $this->ledgerEntry($event, $payloadHash);

        if ($ledger['alreadyProcessed']) {
            return $this->ok(200, 'Webhook already processed.', []);
        }

        $pendingCancelAtScheduleContractId = DB::transaction(fn (): ?string => $this->process($event, $ledger['id']));

        if ($pendingCancelAtScheduleContractId !== null) {
            // Best-effort, fire-and-forget - see class docblock "Term-end
            // cancel_at scheduling". Deliberately outside the transaction
            // above and never allowed to affect this webhook's own HTTP
            // response either way; a failed attempt stays retryable via
            // contracts:retry-pending-cancel-at-scheduling.
            $this->scheduleCancelAt->handle($pendingCancelAtScheduleContractId);
        }

        if ($this->ledgerFailedToResolve($ledger['id'])) {
            // Real-Stripe-test-mode-verified out-of-order gap: BILLING_RECORD_NOT_FOUND
            // means resolveBilling() could not match this event to any local
            // row YET - most commonly an invoice.paid delivered before
            // checkout.session.completed/customer.subscription.* has linked
            // the Subscription id locally (Stripe does not guarantee webhook
            // delivery order). This codebase never persists the raw webhook
            // body (only a payload_hash, deliberately not reversible), so it
            // has no way to internally replay this event itself - a non-2xx
            // response is therefore the ONLY correct way to recover: Stripe
            // automatically retries a webhook delivery that received a
            // non-2xx response, with backoff, so this same event is
            // redelivered once local linkage has caught up, and
            // ledgerEntry() above already treats a FAILED row as
            // not-yet-processed on that redelivery (re-attempting, never
            // silently dropping it). A 200 here would tell Stripe delivery
            // succeeded and permanently strand this Contract's activation.
            return $this->conflict('Billing record not yet resolvable locally; provider should retry delivery.');
        }

        return $this->ok(200, 'Webhook processed.', []);
    }

    private function ledgerFailedToResolve(string $ledgerId): bool
    {
        $statusId = DB::table('service_contract_billing_webhook_events')->where('id', $ledgerId)->value('status_id');

        return (int) $statusId === $this->webhookStatusId('FAILED');
    }

    /**
     * @return array{id: string, alreadyProcessed: bool}
     */
    private function ledgerEntry(NormalizedContractBillingEvent $event, string $payloadHash): array
    {
        $now = now();
        $timestamp = $now->format('Y-m-d H:i:s.u');
        $providerCode = $this->gateway->providerCode();

        $existing = DB::table('service_contract_billing_webhook_events')
            ->where('provider_code', $providerCode)
            ->where('provider_event_id', $event->providerEventId)
            ->first(['id', 'status_id']);

        if ($existing !== null) {
            $processedStatusId = $this->webhookStatusId('PROCESSED');
            $ignoredStatusId = $this->webhookStatusId('IGNORED');

            if ((int) $existing->status_id === $processedStatusId || (int) $existing->status_id === $ignoredStatusId) {
                return ['id' => $existing->id, 'alreadyProcessed' => true];
            }

            DB::table('service_contract_billing_webhook_events')->where('id', $existing->id)->increment('processing_attempt_count', 1, [
                'updated_at' => $timestamp,
            ]);

            return ['id' => $existing->id, 'alreadyProcessed' => false];
        }

        $idBinary = UuidBinary::toBinary(UuidBinary::generate());

        DB::table('service_contract_billing_webhook_events')->insert([
            'id' => $idBinary,
            'provider_code' => $providerCode,
            'provider_event_id' => $event->providerEventId,
            'service_contract_billing_id' => null,
            'event_type' => $event->eventType,
            'provider_object_reference' => $event->stripeSubscriptionId,
            'payload_hash' => $payloadHash,
            'status_id' => $this->webhookStatusId('RECEIVED'),
            'processing_attempt_count' => 1,
            'received_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return ['id' => $idBinary, 'alreadyProcessed' => false];
    }

    /**
     * @return string|null Binary Contract id needing a term-end cancel_at
     *                     scheduling attempt after this transaction
     *                     commits, or null if none is needed.
     */
    private function process(NormalizedContractBillingEvent $event, string $ledgerId): ?string
    {
        if (! in_array($event->eventType, self::HANDLED_EVENT_TYPES, true)) {
            $this->finalizeLedger($ledgerId, 'IGNORED', null, null, null);

            return null;
        }

        $billing = $this->resolveBilling($event);

        if ($billing === null) {
            $this->finalizeLedger($ledgerId, 'FAILED', null, 'BILLING_RECORD_NOT_FOUND', 'No local Contract Billing record matched this event.');

            return null;
        }

        $pendingCancelAtScheduleContractId = match ($event->eventType) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($billing, $event),
            'customer.subscription.created', 'customer.subscription.updated' => $this->handleSubscriptionSync($billing, $event),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($billing, $event),
            'invoice.paid' => $this->handleInvoicePaid($billing, $event),
            'invoice.payment_failed', 'invoice.payment_action_required' => $this->handleInvoiceFailed($billing),
        };

        $this->finalizeLedger($ledgerId, 'PROCESSED', $billing->id, null, null);

        return $pendingCancelAtScheduleContractId;
    }

    /**
     * A plain (unlocked) identification read only - resolves WHICH billing
     * record this event is about. Every handler below takes its own fresh,
     * correctly-ordered lock before writing; nothing here is trusted as
     * "current" beyond the id/service_contract_id used to acquire that
     * lock.
     */
    private function resolveBilling(NormalizedContractBillingEvent $event): ?object
    {
        $row = null;

        if ($event->stripeSubscriptionId !== null) {
            $row = DB::table('service_contract_billings')->where('stripe_subscription_id', $event->stripeSubscriptionId)->first();
        }

        if ($row === null && $event->contractBillingUuid !== null) {
            $idBinary = $this->safeBinary($event->contractBillingUuid);

            if ($idBinary !== null) {
                $row = DB::table('service_contract_billings')->where('id', $idBinary)->first();
            }
        }

        if ($row === null && $event->stripeCheckoutSessionId !== null) {
            $row = DB::table('service_contract_billings')->where('stripe_checkout_session_id', $event->stripeCheckoutSessionId)->first();
        }

        return $row;
    }

    private function handleCheckoutCompleted(object $billing, NormalizedContractBillingEvent $event): ?string
    {
        $locked = DB::table('service_contract_billings')->where('id', $billing->id)->lockForUpdate()->first();

        if ($locked === null || ContractBillingStatuses::code((int) $locked->status_id) !== 'PENDING_CHECKOUT') {
            // Already linked/progressed (duplicate delivery) or terminal -
            // never regressed or re-linked.
            return null;
        }

        $timestamp = now()->format('Y-m-d H:i:s.u');
        $hadSubscriptionIdAlready = $locked->stripe_subscription_id !== null;
        $newSubscriptionId = $event->stripeSubscriptionId ?? $locked->stripe_subscription_id;

        DB::table('service_contract_billings')->where('id', $locked->id)->update(array_filter([
            'status_id' => ContractBillingStatuses::id('INCOMPLETE'),
            'stripe_subscription_id' => $newSubscriptionId,
            'stripe_customer_id' => $event->stripeCustomerId ?? $locked->stripe_customer_id,
            'stripe_checkout_session_id' => $locked->stripe_checkout_session_id ?? $event->stripeCheckoutSessionId,
            'updated_at' => $timestamp,
        ], static fn (mixed $value): bool => $value !== null));

        // See class docblock "Term-end cancel_at scheduling" - only a
        // candidate the moment THIS event is what first links the
        // Subscription id (never re-fired just because a later event also
        // happens to report an id we already knew - contracts:retry-pending-
        // cancel-at-scheduling is the sole retry path once the first
        // attempt has fired).
        return (! $hadSubscriptionIdAlready && $newSubscriptionId !== null && $locked->cancel_at === null) ? $locked->service_contract_id : null;
    }

    private function handleSubscriptionSync(object $billing, NormalizedContractBillingEvent $event): ?string
    {
        $locked = DB::table('service_contract_billings')->where('id', $billing->id)->lockForUpdate()->first();

        if ($locked === null || ContractBillingStatuses::code((int) $locked->status_id) === 'CANCELLED') {
            return null;
        }

        $currentCode = ContractBillingStatuses::code((int) $locked->status_id);
        $targetCode = $this->nextStatusFromSubscription($currentCode, $event);

        $timestamp = now()->format('Y-m-d H:i:s.u');
        $hadSubscriptionIdAlready = $locked->stripe_subscription_id !== null;
        $newSubscriptionId = $event->stripeSubscriptionId ?? $locked->stripe_subscription_id;
        $newCancelAt = $event->cancelAt ?? $locked->cancel_at;

        $update = array_filter([
            'stripe_subscription_id' => $newSubscriptionId,
            'stripe_price_id' => $event->stripePriceId ?? $locked->stripe_price_id,
            'stripe_product_id' => $event->stripeProductId ?? $locked->stripe_product_id,
            'current_period_start' => $event->currentPeriodStart,
            'current_period_end' => $event->currentPeriodEnd,
            'cancel_at' => $event->cancelAt,
        ], static fn (mixed $value): bool => $value !== null);

        $update['updated_at'] = $timestamp;

        $becomesCancelled = false;

        if ($targetCode !== null && $targetCode !== $currentCode) {
            $update['status_id'] = ContractBillingStatuses::id($targetCode);

            if ($targetCode === 'PAST_DUE' && $currentCode !== 'PAST_DUE') {
                $update['past_due_since'] = $timestamp;
            }

            if ($targetCode === 'CANCELLED') {
                $update['cancelled_at'] = $event->canceledAt ?? $timestamp;
                $becomesCancelled = true;
            }
        }

        DB::table('service_contract_billings')->where('id', $locked->id)->update($update);

        // See class docblock "Term-end cancel_at scheduling" - only a
        // candidate the moment THIS event is what first links the
        // Subscription id (webhook-order-safe: customer.subscription.created
        // can legitimately arrive before checkout.session.completed) - never
        // re-fired just because a later sync event also reports an id we
        // already knew, and never once this event just cancelled the
        // subscription outright.
        return (! $hadSubscriptionIdAlready && ! $becomesCancelled && $newSubscriptionId !== null && $newCancelAt === null) ? $locked->service_contract_id : null;
    }

    /**
     * Never promotes to ACTIVE (see class docblock "Activation authority") -
     * only degrades/schedules. Returns null when the Stripe status carries
     * no actionable local transition (e.g. 'trialing', 'incomplete').
     */
    private function nextStatusFromSubscription(string $currentCode, NormalizedContractBillingEvent $event): ?string
    {
        return match (true) {
            $event->subscriptionStatus === 'canceled' => 'CANCELLED',
            in_array($event->subscriptionStatus, ['past_due', 'unpaid'], true) => 'PAST_DUE',
            $event->subscriptionStatus === 'active' && $event->cancelAtPeriodEnd === true => 'CANCEL_AT_PERIOD_END',
            $event->subscriptionStatus === 'active' && $currentCode === 'CANCEL_AT_PERIOD_END' && $event->cancelAtPeriodEnd === false => 'ACTIVE',
            default => null,
        };
    }

    private function handleSubscriptionDeleted(object $billing, NormalizedContractBillingEvent $event): ?string
    {
        $locked = DB::table('service_contract_billings')->where('id', $billing->id)->lockForUpdate()->first();

        if ($locked === null || ContractBillingStatuses::code((int) $locked->status_id) === 'CANCELLED') {
            return null;
        }

        $timestamp = now()->format('Y-m-d H:i:s.u');

        DB::table('service_contract_billings')->where('id', $locked->id)->update([
            'status_id' => ContractBillingStatuses::id('CANCELLED'),
            'cancelled_at' => $event->canceledAt ?? $timestamp,
            'updated_at' => $timestamp,
        ]);

        return null;
    }

    /**
     * The sole authoritative activation trigger - see class docblock. Locks
     * the parent Contract BEFORE the Billing row (SERVICE_CONTRACT ->
     * SERVICE_CONTRACT_BILLING), matching every other Action that locks
     * both.
     *
     * SUSPENDED handling (billing suspension recovery): a Contract escalated
     * ACTIVE -> SUSPENDED by App\Actions\Contract\Billing\
     * SuspendContractsPastDueBillingAction (billing stuck PAST_DUE beyond
     * the grace period) always gets its BILLING row restored to ACTIVE here
     * if Stripe's automatic retry eventually succeeds - past_due_since is
     * cleared and the period is refreshed exactly as for any other
     * recovery. Whether the CONTRACT itself is also recovered ACTIVE
     * depends on WHY it is SUSPENDED, distinguished by
     * `service_contract_billings.billing_suspended_at`:
     *   - not null (SuspendContractsPastDueBillingAction caused it): the
     *     Contract is automatically recovered SUSPENDED -> ACTIVE too, via
     *     the dedicated ContractStatusMachine::
     *     recoverSuspendedByBillingToActive() (never the generic
     *     transition machinery an Admin resume endpoint might one day use),
     *     `billing_suspended_at` is cleared, and exactly one Contract
     *     lifecycle history row is written with a billing-recovery reason.
     *   - null (an Admin manually suspended it via App\Actions\Admin\
     *     Contract\AdminSuspendContractAction): the Contract MUST stay
     *     SUSPENDED regardless of billing recovering - BLUE V1 has no
     *     automatic reactivation for a manual suspension, and no Admin
     *     resume endpoint yet either - so no Contract lifecycle history row
     *     is written for this branch, exactly as before.
     * Restoring billing eligibility now (rather than leaving it wrongly
     * stuck PAST_DUE) means that even in the manual-suspension case, the
     * moment an Admin does reactivate the Contract through whatever future
     * mechanism, new CONTRACT Bookings are immediately authorized again,
     * with no second billing event required.
     */
    private function handleInvoicePaid(object $billing, NormalizedContractBillingEvent $event): ?string
    {
        $contract = DB::table('service_contracts')->where('id', $billing->service_contract_id)->lockForUpdate()->first();
        $locked = DB::table('service_contract_billings')->where('id', $billing->id)->lockForUpdate()->first();

        if ($contract === null || $locked === null || ContractBillingStatuses::code((int) $locked->status_id) === 'CANCELLED') {
            return null;
        }

        $contractStatusCode = ContractStatuses::code((int) $contract->status_id);

        if (! in_array($contractStatusCode, ['PENDING_PAYMENT', 'ACTIVE', 'SUSPENDED'], true)) {
            // The Contract is EXPIRED/CANCELLED (or REQUESTED/APPROVED/
            // PENDING_CUSTOMER_ACCEPTANCE, structurally impossible once
            // billing exists) - a late/out-of-order invoice.paid must never
            // resurrect billing eligibility for a Contract that is no
            // longer eligible for it at all.
            return null;
        }

        $now = now();
        $timestamp = $now->format('Y-m-d H:i:s.u');

        $isBillingCausedSuspension = $contractStatusCode === 'SUSPENDED' && $locked->billing_suspended_at !== null;

        $update = [
            'status_id' => ContractBillingStatuses::id('ACTIVE'),
            'past_due_since' => null,
            'updated_at' => $timestamp,
        ];

        if ($event->currentPeriodStart !== null) {
            $update['current_period_start'] = $event->currentPeriodStart;
        }

        if ($event->currentPeriodEnd !== null) {
            $update['current_period_end'] = $event->currentPeriodEnd;
        }

        if ($isBillingCausedSuspension) {
            $update['billing_suspended_at'] = null;
        }

        DB::table('service_contract_billings')->where('id', $locked->id)->update($update);

        if ($contractStatusCode === 'PENDING_PAYMENT') {
            if (! $this->contractMachine->transitionToActive($contract, $now)) {
                return null;
            }

            DB::table('service_contract_status_history')->insert([
                'id' => UuidBinary::toBinary(UuidBinary::generate()),
                'service_contract_id' => $contract->id,
                'from_status_id' => ContractStatuses::id('PENDING_PAYMENT'),
                'to_status_id' => ContractStatuses::id('ACTIVE'),
                'changed_by_user_id' => null,
                'reason' => "Stripe subscription's first invoice was paid.",
                'changed_at' => $timestamp,
            ]);

            return null;
        }

        if ($isBillingCausedSuspension) {
            if (! $this->contractMachine->recoverSuspendedByBillingToActive($contract, $now)) {
                return null;
            }

            DB::table('service_contract_status_history')->insert([
                'id' => UuidBinary::toBinary(UuidBinary::generate()),
                'service_contract_id' => $contract->id,
                'from_status_id' => ContractStatuses::id('SUSPENDED'),
                'to_status_id' => ContractStatuses::id('ACTIVE'),
                'changed_by_user_id' => null,
                'reason' => 'Billing recovered after a past-due suspension; contract reactivated automatically.',
                'changed_at' => $timestamp,
            ]);

            return null;
        }

        // Contract already ACTIVE, or SUSPENDED by an Admin (never
        // billing-caused): billing is already updated above; write no
        // additional Contract lifecycle history row (BLUE V1 Phase 11
        // "RENEWALS" for ACTIVE, "a manual suspension is never
        // auto-recovered" for SUSPENDED - see method docblock).
        return null;
    }

    private function handleInvoiceFailed(object $billing): ?string
    {
        $locked = DB::table('service_contract_billings')->where('id', $billing->id)->lockForUpdate()->first();

        if ($locked === null) {
            return null;
        }

        $currentCode = ContractBillingStatuses::code((int) $locked->status_id);

        if (in_array($currentCode, ['CANCELLED', 'PAST_DUE'], true)) {
            // Terminal, or already correctly reflects an in-progress
            // failure - never reset past_due_since on a repeat failure.
            return null;
        }

        $timestamp = now()->format('Y-m-d H:i:s.u');

        $targetCode = in_array($currentCode, ['ACTIVE', 'CANCEL_AT_PERIOD_END'], true) ? 'PAST_DUE' : 'INCOMPLETE';

        $update = [
            'status_id' => ContractBillingStatuses::id($targetCode),
            'updated_at' => $timestamp,
        ];

        if ($targetCode === 'PAST_DUE') {
            $update['past_due_since'] = $timestamp;
        }

        DB::table('service_contract_billings')->where('id', $locked->id)->update($update);

        return null;
    }

    private function finalizeLedger(string $ledgerId, string $statusCode, ?string $billingId, ?string $errorCode, ?string $errorMessage): void
    {
        $timestamp = now()->format('Y-m-d H:i:s.u');

        DB::table('service_contract_billing_webhook_events')->where('id', $ledgerId)->update([
            'status_id' => $this->webhookStatusId($statusCode),
            'service_contract_billing_id' => $billingId,
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

    private function safeBinary(string $uuid): ?string
    {
        try {
            return UuidBinary::toBinary($uuid);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
